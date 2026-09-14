<?php
declare(strict_types=1);
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$homeDir = (string) ($home_directory ?? '/home');
?>
<div class="modal fade" id="mirrorDirectoryPickerModal" tabindex="-1" aria-labelledby="mirrorDirPickerTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title h6" id="mirrorDirPickerTitle">
                    <i class="bi bi-folder2-open me-2 text-primary"></i>Verzeichnis im Home-Bereich auswählen
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
            </div>
            <div class="modal-body p-3">
                <div class="alert alert-info py-2 px-3 small mb-3">
                    <i class="bi bi-info-circle me-1"></i>
                    Zur Sicherheit können nur Verzeichnisse innerhalb deines Home-Verzeichnisses (<code><?= $e($homeDir) ?></code>) ausgewählt werden.
                </div>

                <div class="d-flex align-items-center gap-2 mb-3 bg-body-tertiary p-2 rounded">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="mirror-picker-btn-parent" title="Eine Ebene nach oben" disabled>
                        <i class="bi bi-arrow-up"></i>
                    </button>
                    <div class="font-monospace small text-truncate flex-grow-1" id="mirror-picker-current-path"><?= $e($homeDir) ?></div>
                </div>

                <div id="mirror-picker-loading" class="text-center py-4 d-none">
                    <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
                    <span class="small ms-2 text-body-secondary">Lade Verzeichnisse...</span>
                </div>

                <div id="mirror-picker-list" class="list-group list-group-flush border rounded">
                    <!-- Loaded via JS -->
                </div>
            </div>
            <div class="modal-footer justify-content-between">
                <div class="small text-body-secondary">
                    Ausgewählt: <strong id="mirror-picker-selected-label"><?= $e($homeDir) ?></strong>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="button" class="btn btn-primary btn-sm" id="mirror-picker-btn-choose">
                        <i class="bi bi-check2 me-1"></i>Dieses Verzeichnis wählen
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
