<?php
declare(strict_types=1);

$files = is_array($files ?? null) ? $files : [];
$selectedFile = is_array($selected_file ?? null) ? $selected_file : null;
$invalidSelection = (bool) ($invalid_selection ?? false);
$lines = is_array($lines ?? null) ? $lines : [];
$maxLines = (int) ($max_lines ?? 500);
$timezoneName = (string) ($timezone_name ?? 'UTC');
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
$statusClass = static function (string $status): string {
    return match (strtolower($status)) {
        'success' => 'text-success',
        'failed', 'error' => 'text-danger',
        'pending_2fa', 'warning' => 'text-warning',
        default => 'text-muted',
    };
};
$labelMap = [
    'reason' => 'Grund',
    'identifier' => 'Login',
    'identifier_type' => 'Login-Typ',
    'user_id' => 'User-ID',
    'user_found' => 'Benutzer gefunden',
    'password_verified' => 'Passwort geprüft',
    'user_blocked' => 'Benutzer gesperrt',
    'remember_requested' => 'Remember angefordert',
    'token_present' => 'Remember-Cookie vorhanden',
    'token_rotated' => 'Token rotiert',
    'request_method' => 'Methode',
    'request_path' => 'Pfad',
    'ip' => 'IP',
    'forwarded_for' => 'Forwarded-For',
    'session_id' => 'Session-ID',
    'session_active' => 'Session aktiv',
    'remember_cookie_present' => 'Remember-Cookie',
    'csrf_check' => 'CSRF',
    'input_identifier_present' => 'Loginfeld vorhanden',
    'input_password_present' => 'Passwortfeld vorhanden',
    'user_agent' => 'User-Agent',
    'env' => 'Umgebung',
    'debug' => 'Debug',
    'type' => 'Typ',
    'severity' => 'Severity',
    'severity_name' => 'Severity-Name',
    'message' => 'Meldung',
    'file' => 'Datei',
    'line' => 'Zeile',
    'method' => 'Methode',
    'uri' => 'URI',
    'get' => 'GET',
    'post' => 'POST',
    'cookie' => 'Cookies',
    'session_keys' => 'Session-Keys',
];
$formatLogValue = static function (mixed $value): string {
    if (is_bool($value)) {
        return $value ? 'Ja' : 'Nein';
    }
    if ($value === null) {
        return 'null';
    }
    if (is_array($value)) {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($json) ? $json : '';
    }
    return (string) $value;
};
$renderLogFields = static function (array $items, array $skip = []) use ($e, $labelMap, $formatLogValue): void {
    foreach ($items as $key => $value) {
        $key = (string) $key;
        if (in_array($key, $skip, true)) {
            continue;
        }
        if (is_array($value)) {
            continue;
        }
        $label = $labelMap[$key] ?? str_replace('_', ' ', $key);
        ?>
        <div class="modulon-kv-row">
            <dt class="modulon-kv-label text-body-secondary"><?= $e($label) ?></dt>
            <dd class="modulon-kv-value text-break"><?= $e($formatLogValue($value)) ?></dd>
        </div>
        <?php
    }
};
?>

<section class="app-card p-4">
    <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 mb-4">
        <div>
            <h1 class="h4 mb-1">Logs</h1>
            <p class="text-muted mb-0">Admin-Ansicht für lokale Logdateien aus <code>storage/logs</code>.</p>
        </div>
        <?php if ($selectedFile !== null): ?>
            <div class="text-muted small align-self-lg-end">
                <?= $e($selectedFile['name'] ?? '') ?> · <?= $e($formatBytes((int) ($selectedFile['size'] ?? 0))) ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($files === []): ?>
        <div class="alert alert-secondary mb-0">Keine lesbaren Logdateien gefunden.</div>
    <?php else: ?>
        <form method="get" action="/admin/logs" class="row g-3 align-items-end mb-4">
            <div class="col-md-6 col-xl-4">
                <label class="form-label" for="log-file">Logdatei</label>
                <select id="log-file" name="file" class="form-select" onchange="this.form.submit()">
                    <?php foreach ($files as $file): ?>
                        <?php $name = (string) ($file['name'] ?? ''); ?>
                        <option value="<?= $e($name) ?>" <?= $selectedFile !== null && $name === (string) ($selectedFile['name'] ?? '') ? 'selected' : '' ?>>
                            <?= $e($name) ?> · <?= $e($file['modified_local'] ?? '') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <noscript>
                <div class="col-auto">
                    <button class="btn btn-primary" type="submit">Anzeigen</button>
                </div>
            </noscript>
        </form>

        <?php if ($invalidSelection): ?>
            <div class="alert alert-warning">
                Die angeforderte Logdatei wurde nicht gefunden oder ist nicht erlaubt. Angezeigt wird die aktuelle Standardauswahl.
            </div>
        <?php endif; ?>

        <div class="alert alert-secondary">
            Es werden die letzten <?= $maxLines ?> Zeilen angezeigt. Zeiten werden in der Benutzer-Zeitzone <strong><?= $e($timezoneName) ?></strong> dargestellt. Passwörter, Cookies, Session-IDs und Token werden von Modulons Error-Logging sanitisiert; Auth-Remember-Events speichern keine Tokenwerte oder Token-Hashfragmente.
        </div>

        <?php if ($lines === []): ?>
            <div class="alert alert-secondary mb-0">Diese Logdatei ist leer.</div>
        <?php else: ?>
            <div class="vstack gap-3">
                <?php foreach (array_reverse($lines) as $line): ?>
                    <?php
                    $line = is_array($line) ? $line : [];
                    $status = (string) ($line['status'] ?? '');
                    ?>
                    <article class="app-card p-3 app-log-entry">
                        <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
                            <div class="fw-semibold">
                                <?= $e($line['event'] ?? 'Logeintrag') ?>
                                <?php if ($status !== ''): ?>
                                    <span class="<?= $e($statusClass($status)) ?>">· <?= $e($status) ?></span>
                                <?php endif; ?>
                                <?php if (!empty($line['reason'])): ?>
                                    <span class="text-muted">· <?= $e($line['reason']) ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($line['timestamp_local'])): ?>
                                <div class="text-muted small text-end">
                                    <?= $e($line['timestamp_local']) ?><br>
                                    <span><?= $e($timezoneName) ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($line['is_json'])): ?>
                            <div class="row g-3">
                                <div class="col-lg-6">
                                    <h3 class="h6 text-muted mb-2">Ereignis</h3>
                                    <dl class="mb-0 modulon-kv-list app-logviewer-fields">
                                        <?php $renderLogFields(is_array($line['data'] ?? null) ? $line['data'] : [], ['context']); ?>
                                    </dl>
                                </div>
                                <div class="col-lg-6">
                                    <h3 class="h6 text-muted mb-2">Request</h3>
                                    <dl class="mb-0 modulon-kv-list app-logviewer-fields">
                                        <?php $renderLogFields(is_array($line['context'] ?? null) ? $line['context'] : []); ?>
                                    </dl>
                                </div>
                            </div>
                            <details class="mt-3">
                                <summary class="text-muted small">Rohdaten anzeigen</summary>
                                <pre class="mt-2 mb-0 p-3 rounded border app-logviewer-pre"><code><?= $e($line['pretty'] ?? $line['raw'] ?? '') ?></code></pre>
                            </details>
                        <?php else: ?>
                            <div class="app-logviewer-plain"><?= nl2br($e($line['raw'] ?? '')) ?></div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>
