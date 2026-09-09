<?php
declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
$key = (string) ($tool['key'] ?? '');
$label = (string) ($tool['label'] ?? '');
$description = (string) ($tool['description'] ?? '');
$isSingleTool = (bool) ($is_single_tool ?? false);
$textareaRows = $isSingleTool ? 10 : 6;
?>

<article class="app-card tools-card h-100 p-3<?= $isSingleTool ? ' tools-card-single' : '' ?>" id="<?= $e($key) ?>">
    <?php if ($isSingleTool): ?>
        <div class="d-flex justify-content-end mb-3">
            <span class="badge text-bg-secondary">Browser</span>
        </div>
    <?php else: ?>
        <div class="d-flex flex-column flex-sm-row justify-content-between gap-3 mb-3">
            <div>
                <h3 class="h5 mb-1"><?= $e($label) ?></h3>
                <p class="text-muted small mb-0"><?= $e($description) ?></p>
            </div>
            <div class="d-flex flex-wrap gap-2 align-items-start">
                <span class="badge text-bg-secondary">Browser</span>
                <a class="btn btn-outline-secondary btn-sm" href="/tools/<?= $e($key) ?>">Einzeln öffnen</a>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($key === 'text-counter'): ?>
        <textarea class="form-control tools-textarea" id="tools-text-input" rows="<?= $textareaRows ?>" placeholder="Text eingeben..."></textarea>
        <div class="tools-metrics mt-3">
            <span>Zeichen <strong id="tools-count-chars">0</strong></span>
            <span>Ohne Leerzeichen <strong id="tools-count-no-space">0</strong></span>
            <span>Wörter <strong id="tools-count-words">0</strong></span>
            <span>Zeilen <strong id="tools-count-lines">0</strong></span>
            <span>Absätze <strong id="tools-count-paragraphs">0</strong></span>
        </div>
    <?php elseif ($key === 'text-cleaner'): ?>
        <label class="form-label small" for="tools-clean-input">Eingabe</label>
        <textarea class="form-control tools-textarea" id="tools-clean-input" rows="<?= $textareaRows ?>" placeholder="Text bereinigen oder umwandeln..."></textarea>
        <div class="d-flex flex-wrap gap-2 mt-3">
            <button class="btn btn-outline-secondary btn-sm" type="button" data-clean-action="clean">Bereinigen</button>
            <button class="btn btn-outline-secondary btn-sm" type="button" data-clean-action="lower">Alles klein</button>
            <button class="btn btn-outline-secondary btn-sm" type="button" data-clean-action="title">Jedes Wort groß</button>
            <button class="btn btn-outline-secondary btn-sm" type="button" data-clean-action="sentence">Satzanfänge groß</button>
        </div>
        <label class="form-label small mt-3" for="tools-clean-output">Ergebnis</label>
        <textarea class="form-control tools-textarea" id="tools-clean-output" rows="<?= max(5, $textareaRows - 1) ?>" readonly></textarea>
    <?php elseif ($key === 'base64'): ?>
        <textarea class="form-control tools-textarea" id="tools-base64-input" rows="5" placeholder="Text oder Base64..."></textarea>
        <div class="d-flex flex-wrap gap-2 mt-3">
            <button class="btn btn-outline-secondary btn-sm" type="button" id="tools-base64-encode">Encode</button>
            <button class="btn btn-outline-secondary btn-sm" type="button" id="tools-base64-decode">Decode</button>
        </div>
        <pre class="tools-output mt-3" id="tools-base64-output"></pre>
    <?php elseif ($key === 'url-codec'): ?>
        <textarea class="form-control tools-textarea" id="tools-url-input" rows="5" placeholder="Text oder URL-Komponente..."></textarea>
        <div class="d-flex flex-wrap gap-2 mt-3">
            <button class="btn btn-outline-secondary btn-sm" type="button" id="tools-url-encode">Encode</button>
            <button class="btn btn-outline-secondary btn-sm" type="button" id="tools-url-decode">Decode</button>
        </div>
        <pre class="tools-output mt-3" id="tools-url-output"></pre>
    <?php elseif ($key === 'json-formatter'): ?>
        <textarea class="form-control tools-textarea" id="tools-json-input" rows="<?= $isSingleTool ? 12 : 7 ?>" placeholder='{"status":"ok"}'></textarea>
        <div class="d-flex flex-wrap gap-2 mt-3">
            <button class="btn btn-outline-secondary btn-sm" type="button" id="tools-json-format">Formatieren</button>
            <button class="btn btn-outline-secondary btn-sm" type="button" id="tools-json-minify">Minify</button>
        </div>
        <div class="small mt-2" id="tools-json-status"></div>
    <?php elseif ($key === 'uuid-generator'): ?>
        <button class="btn btn-primary btn-sm" type="button" id="tools-uuid-generate">UUID erzeugen</button>
        <div class="d-flex flex-wrap gap-2 align-items-start mt-3">
            <pre class="tools-output flex-grow-1 mb-0" id="tools-uuid-output"></pre>
            <button class="btn btn-outline-secondary btn-sm" type="button" data-copy-target="#tools-uuid-output">Kopieren</button>
        </div>
    <?php elseif ($key === 'password-generator'): ?>
        <div class="row g-3 align-items-end">
            <div class="col-6 col-md-3">
                <label class="form-label small" for="tools-password-length">Länge</label>
                <input class="form-control" id="tools-password-length" type="number" min="8" max="128" value="24">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small" for="tools-password-count">Anzahl</label>
                <input class="form-control" id="tools-password-count" type="number" min="1" max="100" value="1">
            </div>
            <div class="col-12 col-lg-6">
                <div class="tools-option-grid">
                    <label class="form-check">
                        <input class="form-check-input" id="tools-password-lower" type="checkbox" checked>
                        <span class="form-check-label">Kleinbuchstaben</span>
                    </label>
                    <label class="form-check">
                        <input class="form-check-input" id="tools-password-upper" type="checkbox" checked>
                        <span class="form-check-label">Großbuchstaben</span>
                    </label>
                    <label class="form-check">
                        <input class="form-check-input" id="tools-password-numbers" type="checkbox" checked>
                        <span class="form-check-label">Zahlen</span>
                    </label>
                    <label class="form-check">
                        <input class="form-check-input" id="tools-password-symbols" type="checkbox" checked>
                        <span class="form-check-label">Sonderzeichen</span>
                    </label>
                </div>
            </div>
            <div class="col-12">
                <button class="btn btn-primary btn-sm" type="button" id="tools-password-generate">Passwort erzeugen</button>
            </div>
        </div>
        <div class="small mt-2" id="tools-password-status"></div>
        <div class="d-flex flex-column flex-md-row gap-2 align-items-stretch align-items-md-start mt-3">
            <textarea class="form-control tools-output tools-output-textarea flex-grow-1" id="tools-password-output" rows="5" readonly></textarea>
            <button class="btn btn-outline-secondary btn-sm" type="button" data-copy-target="#tools-password-output">Kopieren</button>
        </div>
    <?php elseif ($key === 'timestamp-converter'): ?>
        <div class="row g-2">
            <div class="col-md-6">
                <label class="form-label small" for="tools-timestamp">Unix Timestamp</label>
                <input class="form-control" id="tools-timestamp" type="number" placeholder="1714399200">
            </div>
            <div class="col-md-6">
                <label class="form-label small" for="tools-date">Datum/Zeit lokal</label>
                <input class="form-control" id="tools-date" type="datetime-local">
            </div>
        </div>
        <div class="d-flex flex-wrap gap-2 mt-3">
            <button class="btn btn-outline-secondary btn-sm" type="button" id="tools-timestamp-to-date">Timestamp → Datum</button>
            <button class="btn btn-outline-secondary btn-sm" type="button" id="tools-date-to-timestamp">Datum → Timestamp</button>
            <button class="btn btn-outline-secondary btn-sm" type="button" id="tools-timestamp-now">Jetzt</button>
        </div>
    <?php elseif ($key === 'hash-generator'): ?>
        <textarea class="form-control tools-textarea" id="tools-hash-input" rows="<?= $textareaRows ?>" placeholder="Text hashen..."></textarea>
        <div class="row g-2 align-items-end mt-1">
            <div class="col-12 col-md-6">
                <label class="form-label small" for="tools-hash-algorithm">Algorithmus</label>
                <select class="form-select" id="tools-hash-algorithm">
                    <option value="SHA-1">SHA-1</option>
                    <option value="SHA-256" selected>SHA-256</option>
                    <option value="SHA-384">SHA-384</option>
                    <option value="SHA-512">SHA-512</option>
                </select>
            </div>
            <div class="col-12 col-md-auto">
                <button class="btn btn-primary btn-sm" type="button" id="tools-hash-generate">Hash erzeugen</button>
            </div>
        </div>
        <div class="d-flex flex-column flex-md-row gap-2 align-items-stretch align-items-md-start mt-3">
            <pre class="tools-output flex-grow-1 mb-0" id="tools-hash-output"></pre>
            <button class="btn btn-outline-secondary btn-sm" type="button" data-copy-target="#tools-hash-output">Kopieren</button>
        </div>
    <?php elseif ($key === 'qr-code'): ?>
        <textarea class="form-control tools-textarea" id="tools-qr-input" rows="4" placeholder="Text oder URL..."></textarea>
        <button class="btn btn-primary btn-sm mt-3" type="button" id="tools-qr-generate">QR-Code erzeugen</button>
        <div class="tools-qr-output mt-3"><canvas id="tools-qr-canvas" width="220" height="220"></canvas></div>
        <div class="small text-muted mt-2" id="tools-qr-status"></div>
    <?php elseif ($key === 'markdown-preview'): ?>
        <textarea class="form-control tools-textarea" id="tools-markdown-input" rows="<?= $isSingleTool ? 12 : 7 ?>" placeholder="# Überschrift"></textarea>
        <div class="tools-preview mt-3" id="tools-markdown-preview"></div>
    <?php elseif ($key === 'regex-tester'): ?>
        <div class="row g-2">
            <div class="col-md-8">
                <label class="form-label small" for="tools-regex-pattern">Pattern</label>
                <input class="form-control" id="tools-regex-pattern" placeholder="\\bModulon\\b">
            </div>
            <div class="col-md-4">
                <label class="form-label small" for="tools-regex-flags">Flags</label>
                <input class="form-control" id="tools-regex-flags" value="gi">
            </div>
        </div>
        <textarea class="form-control tools-textarea mt-2" id="tools-regex-text" rows="<?= $isSingleTool ? 9 : 5 ?>" placeholder="Testtext..."></textarea>
        <pre class="tools-output mt-3" id="tools-regex-output"></pre>
    <?php endif; ?>
</article>
