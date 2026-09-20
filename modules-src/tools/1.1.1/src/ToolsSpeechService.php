<?php

declare(strict_types=1);

namespace ModulNest\Tools;

use PDO;
use RuntimeException;
use Throwable;

final class ToolsSpeechService
{
    private const MAX_UPLOAD_BYTES = 524288000; // 500 MB; PHP/nginx limits still apply first.
    private const ALLOWED_EXTENSIONS = ['mp3', 'wav', 'm4a', 'mp4', 'ogg'];
    private const ALLOWED_MIME_PREFIXES = ['audio/', 'video/mp4'];
    private const JOB_STATUSES = ['queued', 'running', 'done', 'error'];

    private readonly string $moduleRoot;

    public function __construct(
        private readonly string $basePath,
        ?string $moduleRoot = null,
        private readonly ?PDO $pdo = null,
    ) {
        $this->moduleRoot = $moduleRoot ?? dirname(__DIR__);
    }

    /**
     * @param array<string, mixed> $file
     * @param array{language?:string,model_path?:string} $options
     * @return array{ok:bool,title:string,summary:string,job:array<string, mixed>,requirements:array<string, mixed>}
     */
    public function createUploadJob(array $file, array $options = []): array
    {
        $this->ensureRuntimeDirectories();

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload fehlgeschlagen oder keine Datei erhalten.');
        }

        $originalName = (string) ($file['name'] ?? '');
        $tmpName = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_UPLOAD_BYTES) {
            throw new RuntimeException('Datei ist leer oder zu groß.');
        }
        if (!is_uploaded_file($tmpName)) {
            throw new RuntimeException('Upload konnte nicht verifiziert werden.');
        }

        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new RuntimeException('Erlaubt sind mp3, wav, m4a, mp4 und ogg.');
        }
        $mime = $this->detectMime($tmpName);
        if (!$this->isAllowedMime($mime)) {
            throw new RuntimeException('Dateityp ist nicht erlaubt: ' . ($mime !== '' ? $mime : 'unbekannt'));
        }

        $jobId = date('Ymd_His') . '_' . bin2hex(random_bytes(6));
        $safeOriginalName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $originalName) ?: ('audio.' . $extension);
        $sourcePath = $this->uploadDirectory() . '/' . $jobId . '_' . $safeOriginalName;
        if (!move_uploaded_file($tmpName, $sourcePath)) {
            throw new RuntimeException('Upload konnte nicht gespeichert werden.');
        }

        $language = $this->normalizeLanguage((string) ($options['language'] ?? 'de'));
        $modelPath = $this->resolveModelPath((string) ($options['model_path'] ?? ''));
        $resultBase = $this->resultDirectory() . '/' . $jobId;
        $job = [
            'id' => $jobId,
            'status' => 'queued',
            'original_name' => $originalName,
            'source_file' => $sourcePath,
            'wav_file' => $this->wavDirectory() . '/' . $jobId . '.wav',
            'model_path' => $modelPath,
            'language' => $language,
            'result_base' => $resultBase,
            'results' => [
                'txt' => $resultBase . '.txt',
                'srt' => $resultBase . '.srt',
                'vtt' => $resultBase . '.vtt',
            ],
            'error' => '',
            'log_file' => $this->logDirectory() . '/' . $jobId . '.log',
            'created_at' => date(DATE_ATOM),
            'started_at' => null,
            'finished_at' => null,
            'size' => $size,
        ];
        $this->saveJob($job);

        if ($modelPath === '') {
            $job['status'] = 'error';
            $job['error'] = 'Kein Whisper-Modell gefunden. Bitte Modellpfad setzen oder Modell unter storage/modules/modulnest.tools/speech/models ablegen.';
            $job['finished_at'] = date(DATE_ATOM);
            $this->saveJob($job);

            return [
                'ok' => false,
                'title' => 'Speech-to-Text nicht bereit',
                'summary' => $job['error'],
                'job' => $this->publicJob($job),
                'requirements' => $this->requirements($modelPath),
            ];
        }

        $started = $this->ensureWorkerRunning();

        return [
            'ok' => true,
            'title' => $started ? 'Speech-to-Text gestartet' : 'Speech-to-Text eingereiht',
            'summary' => $started
                ? 'Upload gespeichert. Die Transkription läuft im Hintergrund.'
                : 'Upload gespeichert. Der Job wartet in der Queue.',
            'job' => $this->publicJob($this->loadJob($jobId)),
            'requirements' => $this->requirements($modelPath),
        ];
    }

    public function processJob(string $jobId): int
    {
        $this->ensureRuntimeDirectories();
        $lifecycleLock = $this->acquireLifecycleLock();
        if (!is_resource($lifecycleLock)) {
            fwrite(STDERR, "Für das Modul läuft bereits eine Lifecycle-Operation.\n");
            return 0;
        }
        $lock = $this->acquireQueueLock();
        if (!is_resource($lock)) {
            flock($lifecycleLock, LOCK_UN);
            fclose($lifecycleLock);
            fwrite(STDERR, "Queue ist bereits aktiv.\n");
            return 0;
        }

        $exitCode = 0;
        $currentJobId = $jobId;
        try {
            while ($currentJobId !== null && $this->mayProcessJobs()) {
                $exitCode = max($exitCode, $this->processSingleJob($currentJobId));
                $currentJobId = $this->nextQueuedJobId();
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            flock($lifecycleLock, LOCK_UN);
            fclose($lifecycleLock);
        }

        return $exitCode;
    }

    private function processSingleJob(string $jobId): int
    {
        $job = $this->loadJob($jobId);
        if ($job === []) {
            fwrite(STDERR, "Job nicht gefunden.\n");
            return 1;
        }
        if (!in_array((string) ($job['status'] ?? ''), ['queued', 'error'], true)) {
            fwrite(STDERR, "Job ist nicht queued.\n");
            return 1;
        }

        $job['status'] = 'running';
        $job['started_at'] = date(DATE_ATOM);
        $job['error'] = '';
        $this->saveJob($job);

        try {
            $requirements = $this->requirements((string) ($job['model_path'] ?? ''));
            if (empty($requirements['ffmpeg']['available']) || empty($requirements['whisper_cpp']['available'])) {
                throw new RuntimeException('ffmpeg oder whisper.cpp ist nicht verfügbar.');
            }
            $modelPath = (string) ($job['model_path'] ?? '');
            if ($modelPath === '' || !is_readable($modelPath)) {
                throw new RuntimeException('Whisper-Modell ist nicht lesbar.');
            }

            $this->appendLog($job, 'Konvertiere Audio nach WAV 16 kHz mono...');
            $this->runCommand([
                (string) $requirements['ffmpeg']['path'],
                '-y',
                '-i',
                (string) $job['source_file'],
                '-ar',
                '16000',
                '-ac',
                '1',
                '-c:a',
                'pcm_s16le',
                (string) $job['wav_file'],
            ], 1800, $job);

            $language = (string) ($job['language'] ?? 'de');
            $command = [
                (string) $requirements['whisper_cpp']['path'],
                '-m',
                $modelPath,
                '-f',
                (string) $job['wav_file'],
                '-l',
                $language,
                '-otxt',
                '-osrt',
                '-ovtt',
                '-of',
                (string) $job['result_base'],
            ];

            $this->appendLog($job, 'Starte whisper.cpp...');
            $this->runCommand($command, 7200, $job);

            foreach (['txt', 'srt', 'vtt'] as $format) {
                $path = (string) ($job['results'][$format] ?? '');
                if ($path !== '' && !is_file($path)) {
                    file_put_contents($path, '');
                }
            }

            $this->removeWorkFiles($job);
            $job['status'] = 'done';
            $job['finished_at'] = date(DATE_ATOM);
            $job['source_file_deleted'] = true;
            $job['wav_file_deleted'] = true;
            $this->saveJob($job);
            return 0;
        } catch (Throwable $exception) {
            $job['status'] = 'error';
            $job['error'] = $this->safeError($exception->getMessage());
            $job['finished_at'] = date(DATE_ATOM);
            $this->appendLog($job, 'Fehler: ' . $job['error']);
            $this->saveJob($job);
            return 1;
        }
    }

    /**
     * @return array{ffmpeg:array{available:bool,path:string},whisper_cpp:array{available:bool,path:string},model:array{available:bool,path:string},ready:bool}
     */
    public function requirements(string $preferredModelPath = ''): array
    {
        $ffmpeg = $this->binaryInfo(['/usr/bin/ffmpeg', '/bin/ffmpeg', '/usr/local/bin/ffmpeg']);
        $whisper = $this->binaryInfo([
            $this->basePath . '/bin/whisper-cli',
            $this->basePath . '/bin/whisper.cpp/main',
            '/usr/local/bin/whisper-cli',
            '/usr/bin/whisper-cli',
            '/usr/local/bin/whisper.cpp',
            '/usr/bin/whisper.cpp',
        ]);
        $modelPath = $this->resolveModelPath($preferredModelPath);
        $model = ['available' => $modelPath !== '', 'path' => $modelPath];

        return [
            'ffmpeg' => $ffmpeg,
            'whisper_cpp' => $whisper,
            'model' => $model,
            'ready' => !empty($ffmpeg['available']) && !empty($whisper['available']) && !empty($model['available']),
        ];
    }

    /**
     * @return array<int, array{path:string,label:string,source:string,is_default:bool,size:int,size_label:string}>
     */
    public function availableModels(string $preferredModelPath = ''): array
    {
        $models = [];
        $seen = [];
        $defaultPath = $this->resolveModelPath($preferredModelPath);
        foreach ($this->modelCandidates($preferredModelPath, true) as $candidate) {
            $real = realpath($candidate['path']);
            if (!is_string($real) || isset($seen[$real]) || !is_readable($real) || !is_file($real)) {
                continue;
            }
            $extension = strtolower(pathinfo($real, PATHINFO_EXTENSION));
            if (!in_array($extension, ['bin', 'gguf'], true)) {
                continue;
            }
            $seen[$real] = true;
            $models[] = [
                'path' => $real,
                'label' => basename($real),
                'source' => $candidate['source'],
                'is_default' => $defaultPath !== '' && $real === $defaultPath,
                'size' => (int) filesize($real),
                'size_label' => $this->formatBytes((int) filesize($real)),
            ];
        }

        usort($models, static function (array $a, array $b): int {
            $sizeOrder = ((int) ($a['size'] ?? 0)) <=> ((int) ($b['size'] ?? 0));
            if ($sizeOrder !== 0) {
                return $sizeOrder;
            }

            return strcmp((string) $a['label'], (string) $b['label']);
        });

        return $models;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listJobs(int $limit = 20): array
    {
        $this->ensureRuntimeDirectories();
        $this->cleanupCompletedWorkFiles();
        $paths = glob($this->jobDirectory() . '/*.json');
        if (!is_array($paths)) {
            return [];
        }

        rsort($paths);
        $jobs = [];
        foreach (array_slice($paths, 0, $limit) as $path) {
            $job = $this->readJobFile($path);
            if ($job !== []) {
                $jobs[] = $this->publicJob($job);
            }
        }

        return $jobs;
    }

    public function cleanupCompletedWorkFiles(): int
    {
        $this->ensureRuntimeDirectories();
        $paths = glob($this->jobDirectory() . '/*.json');
        if (!is_array($paths)) {
            return 0;
        }

        $deleted = 0;
        foreach ($paths as $path) {
            $job = $this->readJobFile($path);
            if (($job['status'] ?? '') !== 'done') {
                continue;
            }
            foreach (['source_file', 'wav_file'] as $field) {
                if ($this->isRuntimeFileAvailable((string) ($job[$field] ?? ''))) {
                    $this->deleteRuntimeFile((string) ($job[$field] ?? ''));
                    $deleted++;
                }
            }
            $job['source_file_deleted'] = true;
            $job['wav_file_deleted'] = true;
            try {
                $this->saveJob($job);
            } catch (Throwable) {
                // Cleanup is best effort. A stale permission on old runtime files must not break the job list.
            }
        }

        return $deleted;
    }

    public function ensureWorkerRunning(): bool
    {
        if ($this->hasRunningJob() || $this->isQueueLocked()) {
            return false;
        }

        $jobId = $this->nextQueuedJobId();
        return $jobId !== null && $this->startWorker($jobId);
    }

    /**
     * @return array<string, mixed>
     */
    public function loadPublicJob(string $jobId): array
    {
        $job = $this->loadJob($jobId);
        return $job !== [] ? $this->publicJob($job) : [];
    }

    public function deleteJob(string $jobId): bool
    {
        $job = $this->loadJob($jobId);
        if ($job === []) {
            return false;
        }
        if ((string) ($job['status'] ?? '') === 'running') {
            throw new RuntimeException('Laufende Jobs können nicht gelöscht werden.');
        }

        $paths = [
            (string) ($job['source_file'] ?? ''),
            (string) ($job['wav_file'] ?? ''),
            (string) ($job['log_file'] ?? ''),
            $this->logDirectory() . '/' . $jobId . '.worker.log',
            $this->jobDirectory() . '/' . $jobId . '.json',
        ];

        foreach (['txt', 'srt', 'vtt'] as $format) {
            $paths[] = (string) ($job['results'][$format] ?? '');
        }

        foreach ($paths as $path) {
            $this->deleteRuntimeFile($path);
        }

        return true;
    }

    /**
     * @return array{path:string,filename:string,content_type:string}
     */
    public function downloadFile(string $jobId, string $format): array
    {
        $format = strtolower(trim($format));
        if (!in_array($format, ['txt', 'srt', 'vtt'], true)) {
            throw new RuntimeException('Ungültiges Format.');
        }

        $job = $this->loadJob($jobId);
        $path = (string) ($job['results'][$format] ?? '');
        $real = $path !== '' ? realpath($path) : false;
        $resultDir = realpath($this->resultDirectory());
        if (!is_string($real) || !is_string($resultDir) || !str_starts_with($real, $resultDir . '/') || !is_readable($real)) {
            throw new RuntimeException('Ergebnisdatei nicht gefunden.');
        }

        $contentTypes = [
            'txt' => 'text/plain; charset=UTF-8',
            'srt' => 'application/x-subrip; charset=UTF-8',
            'vtt' => 'text/vtt; charset=UTF-8',
        ];

        return [
            'path' => $real,
            'filename' => 'speech-' . $jobId . '.' . $format,
            'content_type' => $contentTypes[$format],
        ];
    }

    /**
     * @return array<int, string>
     */
    public function supportedLanguages(): array
    {
        return ['de', 'auto', 'en', 'fr', 'es', 'it'];
    }

    private function startWorker(string $jobId): bool
    {
        $worker = $this->moduleRoot . '/bin/tools-speech-worker.php';
        if (!is_file($worker)) {
            return false;
        }

        $log = $this->logDirectory() . '/' . $jobId . '.worker.log';
        $descriptor = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $log, 'a'],
            2 => ['file', $log, 'a'],
        ];
        $php = $this->phpBinary();
        if ($php === '') {
            return false;
        }

        try {
            $process = proc_open([$php, $worker, '--job-id=' . $jobId, '--base-path=' . $this->basePath], $descriptor, $pipes, $this->basePath);
        } catch (Throwable) {
            return false;
        }
        if (!is_resource($process)) {
            return false;
        }

        // Do not call proc_close(): this HTTP request must not wait for the long-running worker.
        return true;
    }

    private function hasRunningJob(): bool
    {
        $paths = glob($this->jobDirectory() . '/*.json');
        if (!is_array($paths)) {
            return false;
        }
        foreach ($paths as $path) {
            $job = $this->readJobFile($path);
            if (($job['status'] ?? '') === 'running') {
                return true;
            }
        }

        return false;
    }

    private function isQueueLocked(): bool
    {
        $lock = fopen($this->queueLockPath(), 'c');
        if (!is_resource($lock)) {
            return false;
        }
        $locked = !flock($lock, LOCK_EX | LOCK_NB);
        if (!$locked) {
            flock($lock, LOCK_UN);
        }
        fclose($lock);

        return $locked;
    }

    /**
     * @return resource|null
     */
    private function acquireQueueLock()
    {
        $lock = fopen($this->queueLockPath(), 'c');
        if (!is_resource($lock)) {
            return null;
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return null;
        }

        return $lock;
    }

    private function queueLockPath(): string
    {
        return $this->basePath . '/storage/cache/modules/modulnest.tools/speech/worker.lock';
    }

    /** @return resource|null */
    private function acquireLifecycleLock()
    {
        $directory = $this->basePath . '/storage/locks/modules';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) return null;
        $lock = fopen($directory . '/modulnest.tools.lock', 'c');
        if (!is_resource($lock)) return null;
        if (!flock($lock, LOCK_SH | LOCK_NB)) {
            fclose($lock);
            return null;
        }
        return $lock;
    }

    private function mayProcessJobs(): bool
    {
        if (!$this->pdo instanceof PDO) return true;
        $statement = $this->pdo->prepare(
            'SELECT m.is_active,r.release_path FROM module_installations i '
            . 'JOIN modules m ON m.id=i.module_row_id '
            . 'JOIN module_releases r ON r.module_id=i.module_id AND r.release_id=i.active_release_id '
            . 'WHERE i.module_id=? AND i.installed_version IS NOT NULL LIMIT 1'
        );
        $statement->execute(['modulnest.tools']);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || (int) ($row['is_active'] ?? 0) !== 1) return false;
        $activeRoot = realpath((string) ($row['release_path'] ?? ''));
        $workerRoot = realpath($this->moduleRoot);
        return is_string($activeRoot) && is_string($workerRoot) && hash_equals($activeRoot, $workerRoot);
    }

    private function nextQueuedJobId(): ?string
    {
        $paths = glob($this->jobDirectory() . '/*.json');
        if (!is_array($paths)) {
            return null;
        }

        sort($paths);
        foreach ($paths as $path) {
            $job = $this->readJobFile($path);
            if (($job['status'] ?? '') === 'queued') {
                return (string) ($job['id'] ?? '');
            }
        }

        return null;
    }

    private function phpBinary(): string
    {
        $candidates = [
            PHP_BINARY,
            '/usr/bin/php',
            '/bin/php',
            '/usr/local/bin/php',
        ];

        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '' && is_executable($candidate)) {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * @param array<int, string> $command
     * @param array<string, mixed> $job
     */
    private function runCommand(array $command, int $timeoutSeconds, array $job): string
    {
        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptor, $pipes, $this->basePath);
        if (!is_resource($process)) {
            throw new RuntimeException('Prozess konnte nicht gestartet werden.');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output = '';
        $error = '';
        $deadline = time() + $timeoutSeconds;
        while (true) {
            $output .= stream_get_contents($pipes[1]) ?: '';
            $error .= stream_get_contents($pipes[2]) ?: '';
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if (time() >= $deadline) {
                proc_terminate($process);
                throw new RuntimeException('Prozess wegen Timeout abgebrochen.');
            }
            usleep(200000);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        $text = trim($output . ($error !== '' ? "\n" . $error : ''));
        if ($text !== '') {
            $this->appendLog($job, $text);
        }
        if ($exitCode !== 0) {
            throw new RuntimeException('Prozess wurde mit Fehlercode ' . $exitCode . ' beendet.');
        }

        return $text;
    }

    /**
     * @param array<string, mixed> $job
     */
    private function appendLog(array $job, string $text): void
    {
        $path = (string) ($job['log_file'] ?? '');
        if ($path === '') {
            return;
        }

        file_put_contents($path, '[' . date(DATE_ATOM) . '] ' . $text . "\n", FILE_APPEND);
    }

    private function normalizeLanguage(string $language): string
    {
        $language = strtolower(trim($language));
        return in_array($language, $this->supportedLanguages(), true) ? $language : 'de';
    }

    private function resolveModelPath(string $preferred = ''): string
    {
        foreach ($this->modelCandidates($preferred, true) as $candidate) {
            $real = realpath($candidate['path']);
            if (!is_string($real) || !is_readable($real) || !is_file($real)) {
                continue;
            }
            $extension = strtolower(pathinfo($real, PATHINFO_EXTENSION));
            if (in_array($extension, ['bin', 'gguf'], true)) {
                return $real;
            }
        }

        return '';
    }

    /**
     * @return array<int, array{path:string,source:string}>
     */
    private function modelCandidates(string $preferred = '', bool $includeGlobbed = false): array
    {
        $candidates = [];
        $preferred = trim($preferred);
        $legacyPrefix = $this->basePath . '/storage/tools/speech/';
        if (str_starts_with($preferred, $legacyPrefix)) {
            $preferred = $this->speechStorage() . '/' . substr($preferred, strlen($legacyPrefix));
        }
        if ($preferred !== '') {
            $candidates[] = ['path' => $preferred, 'source' => 'Manuell'];
        }

        $env = getenv('TOOLS_SPEECH_MODEL_PATH');
        if (is_string($env) && trim($env) !== '') {
            $candidates[] = ['path' => trim($env), 'source' => 'Environment'];
        }

        foreach ([
            $this->modelDirectory() . '/ggml-base.bin',
            $this->modelDirectory() . '/ggml-small.bin',
            $this->modelDirectory() . '/ggml-medium.bin',
            $this->modelDirectory() . '/ggml-large-v3.bin',
        ] as $candidate) {
            $candidates[] = ['path' => $candidate, 'source' => 'Modulon models'];
        }

        foreach ([
            '/usr/share/whisper.cpp/models/ggml-base.bin',
            '/usr/share/whisper/models/ggml-base.bin',
            '/usr/local/share/whisper.cpp/models/ggml-base.bin',
        ] as $candidate) {
            $candidates[] = ['path' => $candidate, 'source' => 'System'];
        }

        if ($includeGlobbed) {
            foreach ([
                $this->modelDirectory() . '/*.{bin,gguf}' => 'Modulon models',
                '/usr/share/whisper.cpp/models/*.{bin,gguf}' => 'System',
                '/usr/share/whisper/models/*.{bin,gguf}' => 'System',
                '/usr/local/share/whisper.cpp/models/*.{bin,gguf}' => 'System',
            ] as $pattern => $source) {
                $globbed = glob($pattern, GLOB_BRACE);
                if (!is_array($globbed)) {
                    continue;
                }
                foreach ($globbed as $path) {
                    $candidates[] = ['path' => $path, 'source' => $source];
                }
            }
        }

        return $candidates;
    }

    /**
     * @param array<int, string> $paths
     * @return array{available:bool,path:string}
     */
    private function binaryInfo(array $paths): array
    {
        foreach ($paths as $path) {
            if (is_executable($path)) {
                return ['available' => true, 'path' => $path];
            }
        }

        return ['available' => false, 'path' => ''];
    }

    private function detectMime(string $path): string
    {
        if (!function_exists('finfo_open')) {
            return '';
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return '';
        }
        $mime = finfo_file($finfo, $path);
        return is_string($mime) ? strtolower($mime) : '';
    }

    private function isAllowedMime(string $mime): bool
    {
        if ($mime === '') {
            return true;
        }
        foreach (self::ALLOWED_MIME_PREFIXES as $prefix) {
            if (str_starts_with($mime, $prefix)) {
                return true;
            }
        }
        return in_array($mime, ['application/ogg', 'video/quicktime', 'application/octet-stream'], true);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadJob(string $jobId): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{10,80}$/', $jobId)) {
            return [];
        }

        return $this->readJobFile($this->jobDirectory() . '/' . $jobId . '.json');
    }

    /**
     * @return array<string, mixed>
     */
    private function readJobFile(string $path): array
    {
        $real = realpath($path);
        $jobDir = realpath($this->jobDirectory());
        if (!is_string($real) || !is_string($jobDir) || !str_starts_with($real, $jobDir . '/') || !is_readable($real)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($real), true);
        if (!is_array($decoded)) {
            return [];
        }
        $status = (string) ($decoded['status'] ?? '');
        if (!in_array($status, self::JOB_STATUSES, true)) {
            return [];
        }

        return $this->normalizeLegacyJobPaths($decoded);
    }

    /**
     * @param array<string, mixed> $job
     */
    private function saveJob(array $job): void
    {
        $this->ensureRuntimeDirectories();
        $jobId = (string) ($job['id'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_-]{10,80}$/', $jobId)) {
            throw new RuntimeException('Ungültige Job-ID.');
        }

        $job['updated_at'] = date(DATE_ATOM);
        $json = json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || @file_put_contents($this->jobDirectory() . '/' . $jobId . '.json', $json, LOCK_EX) === false) {
            throw new RuntimeException('Job-Metadaten konnten nicht gespeichert werden.');
        }
    }

    /**
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    private function publicJob(array $job): array
    {
        $results = [];
        foreach (['txt', 'srt', 'vtt'] as $format) {
            $path = (string) ($job['results'][$format] ?? '');
            $available = $path !== '' && is_file($path) && is_readable($path);
            $results[$format] = [
                'available' => $available,
                'url' => $available ? '/admin/tools/speech/download?job=' . rawurlencode((string) $job['id']) . '&format=' . $format : '',
            ];
        }

        $txt = (string) ($job['results']['txt'] ?? '');
        $transcript = '';
        if ($txt !== '' && is_readable($txt)) {
            $transcript = trim((string) file_get_contents($txt));
        }
        $prettyTranscript = $this->prettyTranscript($transcript);

        return [
            'id' => (string) ($job['id'] ?? ''),
            'status' => (string) ($job['status'] ?? ''),
            'original_name' => (string) ($job['original_name'] ?? ''),
            'language' => (string) ($job['language'] ?? ''),
            'model_path' => (string) ($job['model_path'] ?? ''),
            'model_name' => basename((string) ($job['model_path'] ?? '')),
            'error' => $this->safeError((string) ($job['error'] ?? '')),
            'created_at' => (string) ($job['created_at'] ?? ''),
            'started_at' => (string) ($job['started_at'] ?? ''),
            'finished_at' => (string) ($job['finished_at'] ?? ''),
            'updated_at' => (string) ($job['updated_at'] ?? ''),
            'size' => (int) ($job['size'] ?? 0),
            'duration_seconds' => $this->durationSeconds($job),
            'duration_label' => $this->durationLabel($this->durationSeconds($job)),
            'source_file_available' => $this->isRuntimeFileAvailable((string) ($job['source_file'] ?? '')),
            'wav_file_available' => $this->isRuntimeFileAvailable((string) ($job['wav_file'] ?? '')),
            'results' => $results,
            'transcript' => $transcript,
            'transcript_pretty' => $prettyTranscript,
        ];
    }

    private function prettyTranscript(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/^[ \t]+/m', '', $text) ?? $text;
        $text = preg_replace('/[ \t]{2,}/', ' ', $text) ?? $text;
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        $paragraphs = preg_split('/\n\s*\n+/', $text) ?: [$text];
        $paragraphs = array_map(static function (string $paragraph): string {
            $lines = array_filter(array_map('trim', explode("\n", $paragraph)), static fn (string $line): bool => $line !== '');
            $flow = implode(' ', $lines);
            $flow = preg_replace('/[ \t]{2,}/', ' ', $flow) ?? $flow;
            $flow = preg_replace('/\s+([,.!?;:])/', '$1', $flow) ?? $flow;

            return trim($flow);
        }, $paragraphs);
        $paragraphs = array_values(array_filter($paragraphs, static fn (string $paragraph): bool => $paragraph !== ''));

        return implode("\n\n", $paragraphs);
    }

    /**
     * @param array<string, mixed> $job
     */
    private function durationSeconds(array $job): ?int
    {
        $started = (string) ($job['started_at'] ?? '');
        $finished = (string) ($job['finished_at'] ?? '');
        if ($started === '' || $finished === '') {
            return null;
        }

        try {
            return max(0, (new \DateTimeImmutable($finished))->getTimestamp() - (new \DateTimeImmutable($started))->getTimestamp());
        } catch (Throwable) {
            return null;
        }
    }

    private function durationLabel(?int $seconds): string
    {
        if ($seconds === null) {
            return '';
        }

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $rest = $seconds % 60;
        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $rest);
        }

        return sprintf('%d:%02d', $minutes, $rest);
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2, ',', '.') . ' GB';
        }
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1, ',', '.') . ' KB';
        }

        return $bytes . ' B';
    }

    /**
     * @param array<string, mixed> $job
     */
    private function removeWorkFiles(array $job): void
    {
        $this->deleteRuntimeFile((string) ($job['source_file'] ?? ''));
        $this->deleteRuntimeFile((string) ($job['wav_file'] ?? ''));
    }

    private function isRuntimeFileAvailable(string $path): bool
    {
        if ($path === '') {
            return false;
        }
        $real = realpath($path);
        if (!is_string($real)) {
            return false;
        }

        foreach ([$this->uploadDirectory(), $this->wavDirectory(), $this->resultDirectory(), $this->logDirectory(), $this->jobDirectory()] as $directory) {
            $base = realpath($directory);
            if (is_string($base) && str_starts_with($real, $base . '/') && is_file($real)) {
                return true;
            }
        }

        return false;
    }

    private function deleteRuntimeFile(string $path): void
    {
        if ($path === '') {
            return;
        }
        $real = realpath($path);
        if (!is_string($real)) {
            return;
        }

        foreach ([$this->uploadDirectory(), $this->wavDirectory(), $this->resultDirectory(), $this->logDirectory(), $this->jobDirectory()] as $directory) {
            $base = realpath($directory);
            if (is_string($base) && str_starts_with($real, $base . '/') && is_file($real)) {
                @unlink($real);
                return;
            }
        }
    }

    private function safeError(string $message): string
    {
        $message = preg_replace('/\/[^\s]+/', '[pfad]', $message) ?? $message;
        return trim(substr($message, 0, 500));
    }

    private function ensureRuntimeDirectories(): void
    {
        foreach ([
            $this->uploadDirectory(),
            $this->wavDirectory(),
            $this->resultDirectory(),
            $this->jobDirectory(),
            $this->logDirectory(),
            $this->modelDirectory(),
            dirname($this->queueLockPath()),
        ] as $path) {
            if (!is_dir($path) && !@mkdir($path, 0775, true) && !is_dir($path)) {
                throw new RuntimeException('Verzeichnis konnte nicht angelegt werden: ' . $path);
            }
        }
    }

    private function uploadDirectory(): string
    {
        return $this->speechStorage() . '/uploads';
    }

    private function wavDirectory(): string
    {
        return $this->speechStorage() . '/wav';
    }

    private function resultDirectory(): string
    {
        return $this->speechStorage() . '/results';
    }

    private function jobDirectory(): string
    {
        return $this->speechStorage() . '/jobs';
    }

    private function logDirectory(): string
    {
        return $this->speechStorage() . '/logs';
    }

    private function modelDirectory(): string
    {
        return $this->speechStorage() . '/models';
    }

    private function speechStorage(): string
    {
        return $this->basePath . '/storage/modules/modulnest.tools/speech';
    }

    /** @param array<string,mixed> $job @return array<string,mixed> */
    private function normalizeLegacyJobPaths(array $job): array
    {
        $legacy = $this->basePath . '/storage/tools/speech/';
        $replacement = $this->speechStorage() . '/';
        foreach (['source_file', 'wav_file', 'model_path', 'result_base', 'log_file'] as $field) {
            $value = (string) ($job[$field] ?? '');
            if (str_starts_with($value, $legacy)) $job[$field] = $replacement . substr($value, strlen($legacy));
        }
        if (is_array($job['results'] ?? null)) {
            foreach ($job['results'] as $format => $value) {
                if (is_string($value) && str_starts_with($value, $legacy)) {
                    $job['results'][$format] = $replacement . substr($value, strlen($legacy));
                }
            }
        }
        return $job;
    }
}
