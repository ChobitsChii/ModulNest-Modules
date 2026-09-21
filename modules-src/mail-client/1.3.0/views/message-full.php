<?php
declare(strict_types=1);
/**
 * Vollbild-Mail-Ansicht (wird in neuem Tab/Fenster geöffnet).
 *
 * @var array|null $message    Die kompletten Mail-Daten
 * @var array      $account    Das Mail-Konto
 * @var string     $error
 * @var bool       $allow_external
 * @var int        $blocked_images
 * @var string     $csrf_token
 */
$message        = is_array($message ?? null) ? $message : null;
$account        = is_array($account ?? null) ? $account : [];
$error          = (string) ($error ?? '');
$allowExternal  = (bool) ($allow_external ?? false);
$blockedImages  = (int) ($blocked_images ?? 0);
$csrfToken      = (string) ($csrf_token ?? '');
?>
<style>
<?php require __DIR__ . '/../assets/css/mail-client.css'; ?>

/* Standalone Vollbild-Ansicht: Header & Footer von Modulon ausblenden */
nav.navbar, footer, .app-footer, #admin-update-banner-container, .admin-update-banner-shell {
    display: none !important;
}
body {
    background: var(--bs-body-bg) !important;
    padding: 0 !important;
    margin: 0 !important;
}
main, .app-container, .container, .container-fluid {
    padding: 1.5rem !important;
    margin: 0 auto !important;
    max-width: 960px !important;
    box-shadow: none !important;
}

@media print {
    nav.navbar, footer, .app-footer, .mc-fullview-actions, button, .btn, .alert, .mc-attachment-chip i {
        display: none !important;
    }
    main, .app-container, .container, .container-fluid, .mc-fullview {
        padding: 0 !important;
        margin: 0 !important;
        max-width: 100% !important;
    }
    .mc-fullview-header {
        border-bottom: 2px solid #333 !important;
        padding-bottom: 1rem !important;
        margin-bottom: 1.5rem !important;
    }
}
</style>

<div class="mc-fullview">

    <?php if ($error !== ''): ?>
    <div class="alert alert-danger">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
    </div>
    <?php endif; ?>

    <?php if ($message !== null): ?>

    <!-- ── Header ─────────────────────────────────────────────────────── -->
    <div class="mc-fullview-header">
        <h1 class="mc-fullview-subject">
            <?= htmlspecialchars($message['subject'] ?: '(kein Betreff)', ENT_QUOTES, 'UTF-8') ?>
        </h1>

        <dl class="row g-1 mb-3" style="font-size:0.85rem;">
            <dt class="col-sm-1 text-body-secondary">Von</dt>
            <dd class="col-sm-11">
                <?php
                $fromStr = $message['from_name'] !== ''
                    ? htmlspecialchars($message['from_name'], ENT_QUOTES, 'UTF-8') . ' &lt;' . htmlspecialchars($message['from'], ENT_QUOTES, 'UTF-8') . '&gt;'
                    : htmlspecialchars($message['from'], ENT_QUOTES, 'UTF-8');
                echo $fromStr;
                ?>
            </dd>
            <dt class="col-sm-1 text-body-secondary">An</dt>
            <dd class="col-sm-11"><?= htmlspecialchars($message['to'], ENT_QUOTES, 'UTF-8') ?></dd>
            <?php if (!empty($message['cc'])): ?>
            <dt class="col-sm-1 text-body-secondary">CC</dt>
            <dd class="col-sm-11"><?= htmlspecialchars($message['cc'], ENT_QUOTES, 'UTF-8') ?></dd>
            <?php endif; ?>
            <dt class="col-sm-1 text-body-secondary">Datum</dt>
            <dd class="col-sm-11"><?= htmlspecialchars($message['date'], ENT_QUOTES, 'UTF-8') ?></dd>
        </dl>

        <!-- Aktions-Buttons -->
        <div class="d-flex flex-wrap gap-2 mb-2">
            <a href="/mail-client/compose?reply_to=<?= rawurlencode($message['from']) ?>&subject=<?= rawurlencode('Re: ' . $message['subject']) ?>&account=<?= (int) $account['id'] ?>&to_addr=<?= rawurlencode($message['to']) ?>"
               class="btn btn-sm btn-primary">
                <i class="bi bi-reply"></i> Antworten
            </a>
            <a href="/mail-client/compose?reply_to=<?= rawurlencode($message['to']) ?>&subject=<?= rawurlencode('Fwd: ' . $message['subject']) ?>&account=<?= (int) $account['id'] ?>"
               class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-forward"></i> Weiterleiten
            </a>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()">
                <i class="bi bi-printer"></i> Drucken
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.close()">
                <i class="bi bi-x-lg"></i> Schließen
            </button>
        </div>

        <!-- Blockierte Bilder -->
        <?php if ($blockedImages > 0 && !$allowExternal): ?>
        <div class="alert alert-warning py-2 px-3 mb-0 d-flex align-items-center gap-2" style="font-size:0.83rem;">
            <i class="bi bi-shield-exclamation"></i>
            <span><?= $blockedImages ?> externe Bild(er) wurden blockiert.</span>
            <form method="post" action="/mail-client/api/whitelist" class="ms-auto mb-0 d-inline">
                <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                <input type="hidden" name="scope_type" value="sender">
                <input type="hidden" name="scope_value" value="<?= htmlspecialchars($message['from'], ENT_QUOTES, 'UTF-8') ?>">
                <button type="submit" class="btn btn-sm btn-warning">Absender vertrauen &amp; neu laden</button>
            </form>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── Mail-Body (sandboxed iframe) ─────────────────────────────────── -->
        <!-- Kalender-Einladungen / Termine (.ics) -->
    <?php if (!empty($message['calendar_events'])): ?>
    <div class="mb-3">
        <?php if (count($message['calendar_events']) > 1): ?>
        <div class="alert alert-primary py-2 px-3 mb-2 d-flex align-items-center justify-content-between">
            <span class="small fw-semibold">
                <i class="bi bi-calendar-week me-1"></i> Diese E-Mail enthält <?= count($message['calendar_events']) ?> Termine:
            </span>
        </div>
        <?php endif; ?>
        <?php foreach ($message['calendar_events'] as $idx => $ev): ?>
        <div class="mc-calendar-invite-card" id="fullview-cal-card-<?= (int)$idx ?>">
            <div class="mc-cal-invite-header">
                <div class="d-flex align-items-center gap-3">
                    <div class="mc-cal-invite-icon">
                        <i class="bi bi-calendar-event"></i>
                    </div>
                    <div class="mc-cal-invite-meta">
                        <div class="mc-cal-invite-title"><?= htmlspecialchars($ev['title'] ?: 'Termin', ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="mc-cal-invite-details">
                            <span><i class="bi bi-clock"></i> <?= htmlspecialchars($ev['formatted_date'] ?: $ev['start_at'], ENT_QUOTES, 'UTF-8') ?></span>
                            <?php if (!empty($ev['location'])): ?>
                            <span><i class="bi bi-geo-alt"></i> <?= htmlspecialchars($ev['location'], ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endif; ?>
                            <?php if (!empty($ev['recurrence_rule'])): ?>
                            <span><i class="bi bi-repeat"></i> Wiederkehrend</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="mc-cal-invite-actions" id="fullview-cal-actions-<?= (int)$idx ?>">
                    <?php if (!empty($ev['already_in_calendar'])): ?>
                    <span class="badge bg-success-subtle text-success border border-success-subtle py-2 px-3 d-inline-flex align-items-center gap-1">
                        <i class="bi bi-check-circle-fill"></i> Im Kalender eingetragen
                    </span>
                    <a href="/calendar" target="_blank" class="btn btn-sm btn-link text-decoration-none py-0">Ansehen</a>
                    <?php else: ?>
                    <button type="button" class="btn btn-sm btn-primary" onclick='openFullviewCalendarModal(<?= (int)$idx ?>, <?= json_encode($ev, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                        <i class="bi bi-calendar-plus me-1"></i> In Kalender eintragen...
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php if (!empty($ev['description'])): ?>
            <div class="small text-secondary mt-1 border-top pt-2" style="white-space:pre-wrap; max-height:90px; overflow-y:auto;">
                <?= htmlspecialchars($ev['description'], ENT_QUOTES, 'UTF-8') ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="mc-fullview-body">
        <iframe
            class="mc-safe-iframe w-100 border-0"
            sandbox="allow-same-origin allow-popups"
            style="min-height: 500px; height: auto;"
            srcdoc="<?= htmlspecialchars('<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
                body{margin:1rem;font-family:system-ui,sans-serif;font-size:15px;line-height:1.6;color:#212529;word-break:break-word;}
                a{color:#0d6efd;}img{max-width:100%;height:auto;}
                table{max-width:100%;overflow-x:auto;display:block;}
                pre{white-space:pre-wrap;word-break:break-all;}
                blockquote{border-left:3px solid #dee2e6;padding-left:1rem;color:#6c757d;}
                .mc-blocked-image{width:48px!important;height:48px!important;border:1px dashed #999;background:#f5f5f5;}
            </style></head><body>' . ($message['safe_html'] ?: '<em style="color:#666">(Kein Inhalt)</em>') . '</body></html>', ENT_QUOTES, 'UTF-8') ?>"
            onload="this.style.height=(this.contentDocument.body.scrollHeight+40)+'px'"
        ></iframe>
    </div>

    <!-- ── Anhänge ──────────────────────────────────────────────────────── -->
    <?php if (!empty($message['attachments'])): ?>
    <div class="mt-3 pt-3 border-top">
        <h6 class="text-body-secondary mb-2">
            <i class="bi bi-paperclip me-1"></i>
            <?= count($message['attachments']) ?> Anhang/Anhänge
        </h6>
        <div class="d-flex flex-wrap gap-2">
            <?php foreach ($message['attachments'] as $att): ?>
            <a href="/mail-client/api/attachment?account=<?= (int) $account['id'] ?>&folder=<?= rawurlencode($message['folder'] ?? 'INBOX') ?>&uid=<?= (int) $message['uid'] ?>&part=<?= rawurlencode($att['part_id']) ?>"
               class="mc-attachment-chip"
               download="<?= htmlspecialchars($att['filename'], ENT_QUOTES, 'UTF-8') ?>">
                <i class="bi bi-file-earmark"></i>
                <?= htmlspecialchars($att['filename'], ENT_QUOTES, 'UTF-8') ?>
                <?php if ($att['size'] > 0): ?>
                <span class="text-secondary">(<?= htmlspecialchars(number_format($att['size'] / 1024, 1), ENT_QUOTES, 'UTF-8') ?> KB)</span>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; ?>

</div><!-- /.mc-fullview -->


<!-- Modal: Termin in Kalender eintragen -->
<div class="modal fade" id="mcFullviewCalModal" tabindex="-1" aria-labelledby="mcFullviewCalModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow border-0">
            <div class="modal-header py-2 px-3 border-bottom">
                <div class="d-flex align-items-center gap-2 overflow-hidden">
                    <span class="rounded-circle p-1 d-inline-flex align-items-center justify-content-center bg-primary-subtle text-primary" style="width:28px;height:28px;">
                        <i class="bi bi-calendar-plus"></i>
                    </span>
                    <h5 class="modal-title fs-6 fw-bold text-truncate mb-0" id="mcFullviewCalModalLabel">Termin in Kalender eintragen</h5>
                </div>
                <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal" aria-label="Schließen"></button>
            </div>
            <form id="mc-fv-cal-form" onsubmit="event.preventDefault(); submitFullviewCalendarImport();">
                <div class="modal-body p-3">
                    <div class="mb-3">
                        <label for="mc-fv-cal-select" class="form-label small fw-semibold mb-1">Ziel-Kalender</label>
                        <select class="form-select form-select-sm" id="mc-fv-cal-select" required>
                            <option value="0">Standard-Kalender</option>
                        </select>
                        <div class="form-text small" style="font-size:0.75rem;">Wählen Sie den Kalender (z. B. Google Kalender oder lokaler Kalender).</div>
                    </div>

                    <div class="mb-3">
                        <label for="mc-fv-cal-title" class="form-label small fw-semibold mb-1">Titel des Termins</label>
                        <input type="text" class="form-control form-control-sm" id="mc-fv-cal-title" required>
                    </div>

                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" role="switch" id="mc-fv-cal-allday">
                        <label class="form-check-label small" for="mc-fv-cal-allday">Ganztägiger Termin</label>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label for="mc-fv-cal-start" class="form-label small fw-semibold mb-1">Beginn</label>
                            <input type="datetime-local" class="form-control form-control-sm" id="mc-fv-cal-start" required>
                        </div>
                        <div class="col-6">
                            <label for="mc-fv-cal-end" class="form-label small fw-semibold mb-1">Ende</label>
                            <input type="datetime-local" class="form-control form-control-sm" id="mc-fv-cal-end">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="mc-fv-cal-location" class="form-label small fw-semibold mb-1">Ort / Konferenzlink</label>
                        <input type="text" class="form-control form-control-sm" id="mc-fv-cal-location" placeholder="Optionaler Ort oder Link">
                    </div>

                    <div class="mb-3">
                        <label for="mc-fv-cal-description" class="form-label small fw-semibold mb-1">Beschreibung / Notizen</label>
                        <textarea class="form-control form-control-sm" id="mc-fv-cal-description" rows="3"></textarea>
                    </div>

                    <input type="hidden" id="mc-fv-cal-rrule" value="">
                    <input type="hidden" id="mc-fv-cal-idx" value="">
                </div>
                <div class="modal-footer py-2 px-3 bg-body-tertiary d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-sm btn-primary" id="mc-fv-cal-submit">
                        <i class="bi bi-calendar-plus me-1"></i> In Kalender speichern
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
let _fvCalendars = null;

async function loadFullviewCalendars() {
    if (_fvCalendars) return;
    try {
        const r = await fetch('/mail-client/api/calendar/calendars', { credentials: 'same-origin' });
        const d = await r.json();
        _fvCalendars = d.calendars || [];
    } catch (_) {
        _fvCalendars = [];
    }
}

async function openFullviewCalendarModal(idx, ev) {
    await loadFullviewCalendars();
    const selectEl = document.getElementById('mc-fv-cal-select');
    if (selectEl) {
        if (_fvCalendars && _fvCalendars.length > 0) {
            selectEl.innerHTML = _fvCalendars.map(c => {
                const src = c.source === 'google' ? ' (Google Calendar)' : ' (Lokal)';
                return `<option value="${c.id}" ${c.is_default ? 'selected' : ''}>${c.name}${src}</option>`;
            }).join('');
        } else {
            selectEl.innerHTML = '<option value="0">Standard-Kalender</option>';
        }
    }

    document.getElementById('mc-fv-cal-idx').value = idx;
    document.getElementById('mc-fv-cal-title').value = ev.title || 'Termin';
    document.getElementById('mc-fv-cal-location').value = ev.location || '';
    document.getElementById('mc-fv-cal-description').value = ev.description || '';
    document.getElementById('mc-fv-cal-rrule').value = ev.recurrence_rule || '';

    const allDayEl = document.getElementById('mc-fv-cal-allday');
    const startEl  = document.getElementById('mc-fv-cal-start');
    const endEl    = document.getElementById('mc-fv-cal-end');
    allDayEl.checked = !!ev.all_day;

    const fmt = (str, allDay) => {
        if (!str) return '';
        const d = new Date(str.replace(' ', 'T'));
        if (isNaN(d.getTime())) return str.substring(0, 16);
        const pad = n => String(n).padStart(2, '0');
        if (allDay) return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}`;
        return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
    };

    const updateTypes = () => {
        if (allDayEl.checked) {
            startEl.type = 'date';
            endEl.type   = 'date';
            startEl.value = fmt(ev.start_at, true);
            endEl.value   = fmt(ev.end_at || ev.start_at, true);
        } else {
            startEl.type = 'datetime-local';
            endEl.type   = 'datetime-local';
            startEl.value = fmt(ev.start_at, false);
            endEl.value   = fmt(ev.end_at || ev.start_at, false);
        }
    };
    allDayEl.onchange = updateTypes;
    updateTypes();

    const modalEl = document.getElementById('mcFullviewCalModal');
    if (window.bootstrap && window.bootstrap.Modal) {
        window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }
}

async function submitFullviewCalendarImport() {
    const modalEl   = document.getElementById('mcFullviewCalModal');
    const selectEl  = document.getElementById('mc-fv-cal-select');
    const titleEl   = document.getElementById('mc-fv-cal-title');
    const allDayEl  = document.getElementById('mc-fv-cal-allday');
    const startEl   = document.getElementById('mc-fv-cal-start');
    const endEl     = document.getElementById('mc-fv-cal-end');
    const locEl     = document.getElementById('mc-fv-cal-location');
    const descEl    = document.getElementById('mc-fv-cal-description');
    const rruleHid  = document.getElementById('mc-fv-cal-rrule');
    const idxHid    = document.getElementById('mc-fv-cal-idx');
    const submitBtn = document.getElementById('mc-fv-cal-submit');

    const calId = parseInt(selectEl.value, 10) || 0;
    const idx   = idxHid.value;

    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Speichere...';

    try {
        const resp = await fetch('/mail-client/api/calendar/add-event', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-Token': <?= json_encode($csrfToken) ?>
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                calendar_id: calId,
                title: titleEl.value,
                description: descEl.value,
                location: locEl.value,
                start_at: startEl.value,
                end_at: endEl.value,
                all_day: allDayEl.checked,
                recurrence_rule: rruleHid.value,
            })
        });
        const res = await resp.json();
        if (!res.success) throw new Error(res.error || 'Fehler beim Speichern');

        if (window.bootstrap && window.bootstrap.Modal) {
            window.bootstrap.Modal.getInstance(modalEl)?.hide();
        }

        const actionsDiv = document.getElementById(`fullview-cal-actions-${idx}`);
        if (actionsDiv) {
            actionsDiv.innerHTML = `
                <span class="badge bg-success-subtle text-success border border-success-subtle py-2 px-3 d-inline-flex align-items-center gap-1">
                    <i class="bi bi-check-circle-fill"></i> Im Kalender eingetragen
                </span>
                <a href="/calendar" target="_blank" class="btn btn-sm btn-link text-decoration-none py-0">Ansehen</a>
            `;
        }
    } catch (err) {
        alert('Speichern fehlgeschlagen: ' + err.message);
    } finally {
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="bi bi-calendar-plus me-1"></i> In Kalender speichern';
    }
}

</script>
