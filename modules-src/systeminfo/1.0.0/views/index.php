<?php
declare(strict_types=1);

$sections = is_array($sections ?? null) ? $sections : [];

$sectionIconNames = [
    'Anwendung / Modulon' => 'grid-1x2',
    'PHP' => 'filetype-php',
    'Webserver' => 'hdd-network',
    'Betriebssystem' => 'cpu',
    'Linux / System' => 'activity',
    'Datenbank' => 'database',
    'Sicherheit / Umgebung' => 'shield-lock',
    'Systemcheck' => 'clipboard2-check',
];

$highlightLabels = [
    'Modulon Version',
    'PHP Version',
    'OS / Distro',
    'Kernel',
    'Verbindung',
];

$renderBootstrapIcon = static function (string $name): string {
    return match ($name) {
        'grid-1x2' => '<svg viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M1 1h6v6H1V1zm8 0h6v6H9V1zM1 9h6v6H1V9zm8 0h6v6H9V9z"/></svg>',
        'filetype-php' => '<svg viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M14 4.5V14a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V2a2 2 0 0 1 2-2h5.5L14 4.5zM9.5 1.5V4a1 1 0 0 0 1 1H13"/><path d="M4.5 8.5h1.2c.8 0 1.3.4 1.3 1.1 0 .8-.5 1.2-1.3 1.2H5.3V12H4.5V8.5zm.8.7v1h.3c.4 0 .6-.1.6-.5 0-.3-.2-.5-.6-.5h-.3zm2.3-.7h1.3c1 0 1.7.7 1.7 1.7S9.9 12 8.9 12H7.6V8.5zm.8.7v2.1h.5c.6 0 1-.4 1-1s-.4-1.1-1-1.1h-.5zm3.1-.7h2.2v.7h-1.4v.7h1.3v.7h-1.3V12h-.8V8.5z"/></svg>',
        'hdd-network' => '<svg viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M14 3a2 2 0 0 1 2 2v4h-1V5a1 1 0 0 0-1-1h-3V3h3zM0 5a2 2 0 0 1 2-2h3v1H2a1 1 0 0 0-1 1v4H0V5z"/><path d="M4.5 6A1.5 1.5 0 0 0 3 7.5v4A1.5 1.5 0 0 0 4.5 13h7a1.5 1.5 0 0 0 1.5-1.5v-4A1.5 1.5 0 0 0 11.5 6h-7zM4 7.5a.5.5 0 0 1 .5-.5h7a.5.5 0 0 1 .5.5V9H4V7.5zm0 2.5h8v1.5a.5.5 0 0 1-.5.5h-7a.5.5 0 0 1-.5-.5V10zm1.5 1a.5.5 0 1 0 0 1 .5.5 0 0 0 0-1zm2 0a.5.5 0 1 0 0 1 .5.5 0 0 0 0-1z"/></svg>',
        'cpu' => '<svg viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M5 0h1v2h4V0h1v2h1.5A1.5 1.5 0 0 1 14 3.5V5h2v1h-2v4h2v1h-2v1.5a1.5 1.5 0 0 1-1.5 1.5H11v2h-1v-2H6v2H5v-2H3.5A1.5 1.5 0 0 1 2 12.5V11H0v-1h2V6H0V5h2V3.5A1.5 1.5 0 0 1 3.5 2H5V0zm-1.5 3a.5.5 0 0 0-.5.5v9a.5.5 0 0 0 .5.5h9a.5.5 0 0 0 .5-.5v-9a.5.5 0 0 0-.5-.5h-9z"/><path d="M6 5.5A1.5 1.5 0 0 1 7.5 4h1A1.5 1.5 0 0 1 10 5.5v1A1.5 1.5 0 0 1 8.5 8h-1A1.5 1.5 0 0 1 6 6.5v-1zm0 4A1.5 1.5 0 0 1 7.5 8h1A1.5 1.5 0 0 1 10 9.5v1A1.5 1.5 0 0 1 8.5 12h-1A1.5 1.5 0 0 1 6 10.5v-1z"/></svg>',
        'activity' => '<svg viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M6 2a.5.5 0 0 1 .48.36L8.17 8H10l1.52-4.56a.5.5 0 0 1 .96.02l1.5 5.5a.5.5 0 0 1-.96.28L11.64 4H9.83L8.48 9.64a.5.5 0 0 1-.96.02L5.83 4H4.36L3.48 6.64a.5.5 0 0 1-.95-.28l1.5-4.5A.5.5 0 0 1 4.5 1.5h1.5z"/><path d="M0 8a8 8 0 1 1 16 0A8 8 0 0 1 0 8zm8-7a7 7 0 1 0 0 14A7 7 0 0 0 8 1z"/></svg>',
        'database' => '<svg viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M8 0C4.686 0 2 1.343 2 3v10c0 1.657 2.686 3 6 3s6-1.343 6-3V3c0-1.657-2.686-3-6-3zm0 1c2.945 0 5 .995 5 2s-2.055 2-5 2-5-.995-5-2 2.055-2 5-2zm0 5c2.945 0 5-.995 5-2v2c0 1.005-2.055 2-5 2s-5-.995-5-2V4c0 1.005 2.055 2 5 2zm0 3c2.945 0 5-.995 5-2v2c0 1.005-2.055 2-5 2s-5-.995-5-2V7c0 1.005 2.055 2 5 2zm0 3c2.945 0 5-.995 5-2v2c0 1.005-2.055 2-5 2s-5-.995-5-2v-2c0 1.005 2.055 2 5 2z"/></svg>',
        'shield-lock' => '<svg viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M5.5 8a2.5 2.5 0 1 1 5 0v1h.5A1.5 1.5 0 0 1 12.5 10.5v2A1.5 1.5 0 0 1 11 14H5a1.5 1.5 0 0 1-1.5-1.5v-2A1.5 1.5 0 0 1 5 9h.5V8zm1 1h3V8a1.5 1.5 0 1 0-3 0v1z"/><path d="M8 0 1.5 2.5v4.776c0 3.53 2.248 6.667 5.674 7.9L8 15.5l.826-.324c3.426-1.233 5.674-4.37 5.674-7.9V2.5L8 0zm5.5 7.276c0 3.108-1.97 5.876-4.999 7.014L8 14.5l-.501-.21C4.47 13.152 2.5 10.384 2.5 7.276V3.19L8 1.077l5.5 2.114v4.085z"/></svg>',
        'clipboard2-check' => '<svg viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M10 1.5v1h1.5A1.5 1.5 0 0 1 13 4v10a1.5 1.5 0 0 1-1.5 1.5h-7A1.5 1.5 0 0 1 3 14V4a1.5 1.5 0 0 1 1.5-1.5H6v-1h4zM7 2v1h2V2H7zm4.5 1.5h-7a.5.5 0 0 0-.5.5v10a.5.5 0 0 0 .5.5h7a.5.5 0 0 0 .5-.5V4a.5.5 0 0 0-.5-.5z"/><path d="m7.854 10.646 3-3-.708-.708-2.646 2.647-1.146-1.147-.708.708 1.5 1.5a.5.5 0 0 0 .708 0z"/></svg>',
        default => '<svg viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><circle cx="8" cy="8" r="7"/><circle cx="8" cy="8" r="2" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>',
    };
};
?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <h1 class="h4 mb-0">Systeminfo</h1>
</div>

<div class="row g-4">
    <?php foreach ($sections as $title => $items): ?>
        <?php $rows = is_array($items) ? $items : []; ?>
        <div class="col-12 col-xl-6">
            <section class="card shadow-sm border-0 app-card h-100 modulon-system-card">
                <header class="card-header modulon-system-card-header">
                    <span class="modulon-system-card-icon" aria-hidden="true"><?= $renderBootstrapIcon((string) ($sectionIconNames[$title] ?? 'default')) ?></span>
                    <h2 class="h6 mb-0 modulon-system-card-title"><?= htmlspecialchars((string) $title, ENT_QUOTES, 'UTF-8') ?></h2>
                </header>
                <div class="card-body p-0">
                    <dl class="mb-0 modulon-kv-list">
                        <?php foreach ($rows as $key => $value): ?>
                            <?php
                            $label = (string) $key;
                            $textValue = (string) $value;
                            $valueClass = 'modulon-kv-value';
                            if (in_array($label, $highlightLabels, true)) {
                                $valueClass .= ' is-highlight';
                            }

                            $valueLower = strtolower($textValue);
                            if (str_contains($valueLower, '[ok]') || in_array($valueLower, ['aktiv', 'ja', 'verbunden'], true)) {
                                $valueClass .= ' is-positive';
                                $valueClass .= ' is-status-ok';
                            } elseif (str_contains($valueLower, '[warning]')) {
                                $valueClass .= ' is-status-warning';
                            } elseif (str_contains($valueLower, '[error]') || str_contains($valueLower, 'fehlt')) {
                                $valueClass .= ' is-status-error';
                            } elseif (in_array($valueLower, ['inaktiv', 'nein', 'nicht verfügbar'], true)) {
                                $valueClass .= ' is-muted';
                            }

                            if ($label === 'Status') {
                                $statusValue = strtolower(trim($textValue));
                                if ($statusValue === 'ok') {
                                    $valueClass .= ' is-status-ok';
                                } elseif ($statusValue === 'warning') {
                                    $valueClass .= ' is-status-warning';
                                } elseif ($statusValue === 'error') {
                                    $valueClass .= ' is-status-error';
                                }
                            }
                            if (($label === 'Warnungen' || $label === 'Fehler') && ctype_digit(trim($textValue)) && (int) $textValue > 0) {
                                $valueClass .= $label === 'Warnungen' ? ' is-status-warning' : ' is-status-error';
                            }
                            ?>
                            <div class="modulon-kv-row">
                                <dt class="modulon-kv-label text-body-secondary"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></dt>
                                <dd class="<?= htmlspecialchars($valueClass, ENT_QUOTES, 'UTF-8') ?> text-break"><?= htmlspecialchars($textValue, ENT_QUOTES, 'UTF-8') ?></dd>
                            </div>
                        <?php endforeach; ?>
                    </dl>
                </div>
            </section>
        </div>
    <?php endforeach; ?>
</div>
