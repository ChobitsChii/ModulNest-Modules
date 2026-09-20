<?php
declare(strict_types=1);

$toolGroups = is_array($tool_groups ?? null) ? $tool_groups : [];
$speechRequirements = is_array($speech_requirements ?? null) ? $speech_requirements : [];
$speechModels = is_array($speech_models ?? null) ? $speech_models : [];
$speechJobs = is_array($speech_jobs ?? null) ? $speech_jobs : [];
$speechLanguages = is_array($speech_languages ?? null) ? $speech_languages : ['de', 'auto'];
$speechError = (string) ($speech_error ?? '');
$toolsMessage = (string) ($tools_message ?? '');
$toolsError = (string) ($tools_error ?? '');
$csrfToken = (string) ($csrf_token ?? '');
$e = static fn (mixed $value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
$formatBytes = static function (int $bytes): string {
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 1, ',', '.') . ' KB';
    }
    return $bytes . ' B';
};
?>

<section class="app-card p-4 mb-4">
    <div class="d-flex flex-column flex-lg-row justify-content-between gap-3">
        <div>
            <p class="text-uppercase text-muted small fw-semibold mb-1">Admin-Tools</p>
            <h1 class="h3 mb-2">Diagnose- und Sicherheitswerkzeuge</h1>
            <p class="text-muted mb-0">Serverseitige Tools sind admin-only, validieren Eingaben zentral und laufen mit Timeouts.</p>
        </div>
        <div class="align-self-lg-end tools-search-wrap">
            <label class="form-label small text-muted" for="tools-search">Tools suchen</label>
            <input class="form-control" id="tools-search" type="search" placeholder="z. B. DNS, SSL, Port">
        </div>
    </div>
</section>

<?php if ($toolsMessage !== ''): ?>
    <div class="alert alert-success"><?= $e($toolsMessage) ?></div>
<?php endif; ?>
<?php if ($toolsError !== ''): ?>
    <div class="alert alert-danger"><?= $e($toolsError) ?></div>
<?php endif; ?>

<section class="app-card tools-admin-result-card p-3 p-lg-4 mb-4" id="tools-admin-result-card">
    <div class="d-flex flex-column flex-lg-row justify-content-between gap-2 mb-3">
        <div>
            <p class="text-uppercase text-muted small fw-semibold mb-1">Gemeinsame Ausgabe</p>
            <h2 class="h5 mb-1">Ergebnis der Admin-Tools</h2>
            <p class="text-muted small mb-0">Alle serverseitigen Tools schreiben ihre Ausgabe in diese Box.</p>
        </div>
    </div>
    <div class="tools-admin-result text-muted small" id="tools-admin-result" aria-live="polite">Noch kein Tool ausgeführt.</div>
</section>

<div class="row g-4 align-items-start">
    <div class="col-12 col-xxl-8">
        <div class="tools-grid">
            <?php foreach ($toolGroups as $category => $tools): ?>
                <?php if ((string) $category === 'Speech-to-Text') { continue; } ?>
                <section class="tools-category">
                    <h2 class="h5 mb-3"><?= $e($category) ?></h2>
                    <div class="row g-3">
                        <?php foreach ($tools as $tool): ?>
                            <?php
                            $key = (string) ($tool['key'] ?? '');
                            $label = (string) ($tool['label'] ?? '');
                            $description = (string) ($tool['description'] ?? '');
                            if ($key === 'speech-to-text') {
                                continue;
                            }
                            ?>
                            <div class="col-12 col-xl-6 tools-card-wrap" data-tool-card data-tool-search="<?= $e(strtolower($label . ' ' . $description . ' ' . $category)) ?>">
                                <article class="app-card tools-card h-100 p-3">
                                    <div class="d-flex justify-content-between gap-3 mb-3">
                                        <div>
                                            <h3 class="h5 mb-1"><?= $e($label) ?></h3>
                                            <p class="text-muted small mb-0"><?= $e($description) ?></p>
                                        </div>
                                        <span class="badge text-bg-warning align-self-start">Admin</span>
                                    </div>
                                    <form class="js-tools-admin-form vstack gap-2" method="post" action="/admin/tools/network">
                                        <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                                        <input type="hidden" name="tool" value="<?= $e($key) ?>">

                                        <?php if (in_array($key, ['ping', 'traceroute', 'dns', 'ssl-info', 'port-check', 'mail-dns'], true)): ?>
                                            <label class="form-label small mb-0" for="tool-<?= $e($key) ?>-host">Host/Domain</label>
                                            <input class="form-control" id="tool-<?= $e($key) ?>-host" name="host" placeholder="example.com" required>
                                        <?php endif; ?>

                                        <?php if ($key === 'dns'): ?>
                                            <label class="form-label small mb-0" for="tool-dns-type">Record-Typ</label>
                                            <select class="form-select" id="tool-dns-type" name="record_type">
                                                <?php foreach (['A', 'AAAA', 'MX', 'TXT', 'CNAME', 'NS'] as $type): ?>
                                                    <option value="<?= $e($type) ?>"><?= $e($type) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php endif; ?>

                                        <?php if ($key === 'reverse-dns'): ?>
                                            <label class="form-label small mb-0" for="tool-reverse-ip">IP-Adresse</label>
                                            <input class="form-control" id="tool-reverse-ip" name="ip" placeholder="1.1.1.1" required>
                                        <?php endif; ?>

                                        <?php if ($key === 'http-headers'): ?>
                                            <label class="form-label small mb-0" for="tool-http-url">URL</label>
                                            <input class="form-control" id="tool-http-url" name="url" type="url" placeholder="https://example.com" required>
                                        <?php endif; ?>

                                        <?php if (in_array($key, ['ssl-info', 'port-check'], true)): ?>
                                            <label class="form-label small mb-0" for="tool-<?= $e($key) ?>-port">Port</label>
                                            <input class="form-control" id="tool-<?= $e($key) ?>-port" name="port" type="number" min="1" max="65535" value="<?= $key === 'ssl-info' ? '443' : '80' ?>">
                                        <?php endif; ?>

                                        <?php if ($key === 'mail-dns'): ?>
                                            <label class="form-label small mb-0" for="tool-mail-selector">DKIM-Selector optional</label>
                                            <input class="form-control" id="tool-mail-selector" name="selector" placeholder="default">
                                        <?php endif; ?>

                                        <button class="btn btn-primary btn-sm align-self-start mt-2" type="submit">Prüfen</button>
                                    </form>
                                </article>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>
    </div>

    <aside class="col-12 col-xxl-4 tools-admin-speech-column">
        <section class="app-card p-3 mb-4" data-tool-card data-tool-search="speech-to-text whisper ffmpeg transkription audio">
            <div class="d-flex justify-content-between gap-3 mb-3">
                <div>
                    <h2 class="h5 mb-1">Speech-to-Text</h2>
                    <p class="text-muted small mb-0">Audio hochladen, in WAV 16 kHz mono konvertieren und per whisper.cpp im Hintergrund transkribieren.</p>
                </div>
                <span class="badge text-bg-warning align-self-start">Admin</span>
            </div>
            <form class="js-tools-speech-form vstack gap-3" method="post" action="/admin/tools/speech" enctype="multipart/form-data">
                <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                <div>
                    <label class="form-label" for="tools-audio-file">Audiodatei</label>
                    <input class="form-control" id="tools-audio-file" name="audio_file" type="file" accept=".mp3,.wav,.m4a,.mp4,.ogg,audio/*,video/mp4" required>
                    <div class="form-text text-muted">Erlaubt: mp3, wav, m4a, mp4, ogg. Limit: <?= $e($formatBytes(524288000)) ?>, begrenzt zusätzlich durch PHP/Webserver.</div>
                </div>
                <div>
                    <label class="form-label" for="tools-speech-language">Sprache</label>
                    <select class="form-select" id="tools-speech-language" name="language">
                        <?php foreach ($speechLanguages as $language): ?>
                            <option value="<?= $e($language) ?>" <?= $language === 'de' ? 'selected' : '' ?>>
                                <?= $language === 'auto' ? 'Automatisch erkennen' : strtoupper($e($language)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label" for="tools-speech-model">Modellpfad optional</label>
                    <?php if ($speechModels !== []): ?>
                        <select class="form-select mb-2" id="tools-speech-model-select">
                            <option value="">Modell auswählen...</option>
                            <?php foreach ($speechModels as $model): ?>
                                <?php
                                $modelPath = (string) ($model['path'] ?? '');
                                $modelLabel = (string) ($model['label'] ?? basename($modelPath));
                                $modelSource = (string) ($model['source'] ?? '');
                                $modelSize = (string) ($model['size_label'] ?? '');
                                ?>
                                <option value="<?= $e($modelPath) ?>" <?= !empty($model['is_default']) ? 'selected' : '' ?>>
                                    <?= $e($modelLabel) ?><?= $modelSize !== '' ? ' · ' . $e($modelSize) : '' ?><?= $modelSource !== '' ? ' · ' . $e($modelSource) : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                    <input class="form-control" id="tools-speech-model" name="model_path" value="<?= $e($speechRequirements['model']['path'] ?? '') ?>" placeholder="storage/modules/modulnest.tools/speech/models/ggml-base.bin">
                    <div class="form-text text-muted">Auswahl übernimmt nur den Pfad ins Textfeld. Eigene Pfade sind weiterhin möglich.</div>
                </div>
                <button class="btn btn-primary btn-sm align-self-start" type="submit">Transkription starten</button>
            </form>
            <div class="mt-3 small tools-speech-readiness">
                <div>ffmpeg: <strong class="<?= !empty($speechRequirements['ffmpeg']['available']) ? 'text-success' : 'text-warning' ?>"><?= !empty($speechRequirements['ffmpeg']['available']) ? $e($speechRequirements['ffmpeg']['path'] ?? 'gefunden') : 'nicht gefunden' ?></strong></div>
                <div>whisper.cpp: <strong class="<?= !empty($speechRequirements['whisper_cpp']['available']) ? 'text-success' : 'text-warning' ?>"><?= !empty($speechRequirements['whisper_cpp']['available']) ? $e($speechRequirements['whisper_cpp']['path'] ?? 'gefunden') : 'nicht gefunden' ?></strong></div>
                <div>Modell: <strong class="<?= !empty($speechRequirements['model']['available']) ? 'text-success' : 'text-warning' ?>"><?= !empty($speechRequirements['model']['available']) ? $e($speechRequirements['model']['path'] ?? '') : 'nicht gefunden' ?></strong></div>
                <div>Bereit: <strong class="<?= !empty($speechRequirements['ready']) ? 'text-success' : 'text-warning' ?>"><?= !empty($speechRequirements['ready']) ? 'ja' : 'nein' ?></strong></div>
            </div>
            <?php if ($speechError !== ''): ?>
                <div class="alert alert-warning mt-3 mb-0 small"><?= $e($speechError) ?></div>
            <?php endif; ?>
        </section>

    </aside>
</div>

<section class="app-card p-4 mt-4">
    <div class="d-flex flex-column flex-lg-row justify-content-between gap-2 mb-3">
        <div>
            <h2 class="h5 mb-1">Speech-to-Text Jobs</h2>
            <p class="text-muted small mb-0">Status wird automatisch aktualisiert. Fertige Jobs bieten TXT, SRT und VTT zum Download. Original- und WAV-Arbeitsdateien werden nach erfolgreicher Verarbeitung entfernt.</p>
        </div>
        <button class="btn btn-outline-secondary btn-sm align-self-lg-start" type="button" id="tools-speech-refresh">Status aktualisieren</button>
    </div>
    <div id="tools-speech-jobs" data-status-url="/admin/tools/speech/status" data-delete-url="/admin/tools/speech/delete" data-csrf-token="<?= $e($csrfToken) ?>">
        <?php if ($speechJobs === []): ?>
            <div class="alert alert-secondary mb-0">Noch keine Speech-to-Text-Jobs vorhanden.</div>
        <?php else: ?>
            <div class="vstack gap-2">
                <?php foreach ($speechJobs as $job): ?>
                    <?php $status = (string) ($job['status'] ?? ''); ?>
                    <article class="tools-speech-job border rounded-3 p-3" data-job-id="<?= $e($job['id'] ?? '') ?>">
                        <div class="d-flex flex-wrap justify-content-between gap-2">
                            <div>
                                <strong><?= $e($job['original_name'] ?? 'Audio') ?></strong>
                                <div class="text-muted small">
                                    <?= $e($job['created_at_local'] ?? $job['created_at'] ?? '') ?>
                                    <?php if (!empty($job['timezone_name'])): ?>
                                        · <?= $e($job['timezone_name']) ?>
                                    <?php endif; ?>
                                    · <?= $e(strtoupper((string) ($job['language'] ?? ''))) ?>
                                </div>
                                <div class="text-muted small">Modell: <?= $e($job['model_name'] ?? basename((string) ($job['model_path'] ?? ''))) ?></div>
                                <?php if (!empty($job['duration_label'])): ?>
                                    <div class="text-muted small">Laufzeit: <?= $e($job['duration_label']) ?></div>
                                <?php endif; ?>
                            </div>
                            <span class="badge tools-speech-status tools-speech-status-<?= $e($status) ?>"><?= $e($status) ?></span>
                        </div>
                        <div class="text-muted small mt-2">
                            Originaldatei: <?= !empty($job['source_file_available']) ? 'vorhanden' : 'entfernt' ?> · WAV: <?= !empty($job['wav_file_available']) ? 'vorhanden' : 'entfernt' ?>
                        </div>
                        <?php if (!empty($job['error'])): ?>
                            <div class="text-danger small mt-2"><?= $e($job['error']) ?></div>
                        <?php endif; ?>
                        <?php
                        $prettyTranscript = (string) ($job['transcript_pretty'] ?? '');
                        $rawTranscript = (string) ($job['transcript'] ?? '');
                        ?>
                        <?php if ($prettyTranscript !== '' || $rawTranscript !== ''): ?>
                            <div class="tools-transcript-flow mt-2 mb-2"><?= nl2br($e($prettyTranscript !== '' ? $prettyTranscript : $rawTranscript)) ?></div>
                            <?php if ($rawTranscript !== '' && $rawTranscript !== $prettyTranscript): ?>
                                <details class="small mb-2">
                                    <summary>Rohtext anzeigen</summary>
                                    <pre class="tools-output mt-2 mb-0"><?= $e($rawTranscript) ?></pre>
                                </details>
                            <?php endif; ?>
                        <?php endif; ?>
                        <div class="d-flex flex-wrap gap-2 mt-2">
                            <?php foreach (['txt' => 'TXT', 'srt' => 'SRT', 'vtt' => 'VTT'] as $format => $label): ?>
                                <?php $result = is_array($job['results'][$format] ?? null) ? $job['results'][$format] : []; ?>
                                <?php if (!empty($result['available'])): ?>
                                    <a class="btn btn-outline-secondary btn-sm" href="<?= $e($result['url'] ?? '#') ?>"><?= $e($label) ?> herunterladen</a>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <?php if ($status !== 'running'): ?>
                                <form method="post" action="/admin/tools/speech/delete" onsubmit="return confirm('Speech-to-Text-Job wirklich löschen?');">
                                    <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                                    <input type="hidden" name="job_id" value="<?= $e($job['id'] ?? '') ?>">
                                    <button class="btn btn-outline-danger btn-sm" type="submit">Löschen</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php $assetVersion = rawurlencode((string) ($app_version ?? '')); ?>
<script src="/assets/modules/modulnest.tools/<?= rawurlencode(basename(dirname(__DIR__))) ?>/tools.js<?= $assetVersion !== '' ? '?v=' . $assetVersion : '' ?>"></script>
