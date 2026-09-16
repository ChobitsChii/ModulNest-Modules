<?php
declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$enabled = (bool) ($enabled ?? false);
$clientId = (string) ($client_id ?? '');
$clientSecret = (string) ($client_secret ?? '');
$redirectUri = (string) ($redirect_uri ?? '');
$message = (string) ($message ?? '');
$error = (string) ($error ?? '');
?>

<div class="row g-4 calendar-admin">
    <div class="col-12">
        <div class="card shadow-sm border-0 app-card">
            <div class="card-body p-4">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                    <div>
                        <p class="text-uppercase text-body-secondary small fw-semibold mb-1">Module &amp; Integrationen</p>
                        <h1 class="h4 mb-1"><i class="bi bi-calendar3 me-2 text-primary"></i>Kalender Administration</h1>
                        <p class="text-body-secondary mb-0">Verwalte die Google Calendar OAuth 2.0 Schnittstelle zur bidirektionalen Terminsynchronisation.</p>
                    </div>
                    <div>
                        <a href="/calendar" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-arrow-left me-1"></i> Zum Kalender
                        </a>
                    </div>
                </div>

                <?php if ($message !== ''): ?>
                    <div class="alert alert-success mt-3 mb-0" role="status"><?= $e($message) ?></div>
                <?php endif; ?>
                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger mt-3 mb-0" role="alert"><?= $e($error) ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-7">
        <div class="card shadow-sm border-0 app-card">
            <div class="card-body p-4">
                <h2 class="h5 mb-3"><i class="bi bi-gear me-2 text-primary"></i>Google OAuth 2.0 Konfiguration</h2>
                <p class="text-body-secondary small mb-4">
                    Hier konfigurierst du die zentralen Zugangsdaten für alle Benutzer deiner ModulNest-Instanz. Nach der Aktivierung können Benutzer in ihrem Kalender ihr persönliches Google-Konto verknüpfen.
                </p>

                <form method="post" action="/admin/calendar/settings">
                    <div class="form-check form-switch mb-4">
                        <input class="form-check-input" type="checkbox" role="switch" id="google_enabled" name="google_enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
                        <label class="form-check-label fw-semibold" for="google_enabled">Google Calendar Integration aktivieren</label>
                        <div class="form-text">Aktiviert die Verknüpfungsschaltfläche und Synchronisation im Benutzerkalender.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="google_client_id">Google Client ID <span class="text-danger">*</span></label>
                        <input type="text" class="form-control font-monospace" id="google_client_id" name="google_client_id"
                               value="<?= $e($clientId) ?>" placeholder="z. B. 123456789-abcdefg.apps.googleusercontent.com" spellcheck="false" autocomplete="off">
                        <div class="form-text">Aus deiner Google Cloud Console unter APIs &amp; Dienste &gt; Anmeldedaten.</div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold" for="google_client_secret">Google Client Secret <span class="text-danger">*</span></label>
                        <input type="password" class="form-control font-monospace" id="google_client_secret" name="google_client_secret"
                               value="<?= $e($clientSecret) ?>" placeholder="GOCSPX-..." spellcheck="false" autocomplete="new-password">
                        <div class="form-text">Der geheime Clientschlüssel der Google OAuth-Webanwendung.</div>
                    </div>

                    <div class="d-flex justify-content-end gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i> Einstellungen speichern
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-5">
        <div class="card shadow-sm border-0 app-card">
            <div class="card-body p-4">
                <h2 class="h5 mb-3"><i class="bi bi-info-circle me-2 text-primary"></i>Einrichtungsanleitung</h2>

                <div class="mb-3">
                    <label class="form-label small fw-semibold text-uppercase text-body-secondary mb-1">Autorisierte Weiterleitungs-URI</label>
                    <div class="input-group input-group-sm">
                        <input type="text" class="form-control font-monospace" id="redirect-uri-input" value="<?= $e($redirectUri) ?>" readonly>
                        <button class="btn btn-outline-secondary" type="button" id="copy-uri-btn" title="URI kopieren">
                            <i class="bi bi-clipboard" id="copy-uri-icon"></i>
                        </button>
                    </div>
                    <div class="form-text small">Diese URI muss in der Google Cloud Console exakt so eingetragen werden.</div>
                </div>

                <hr class="my-3">

                <div class="small text-body-secondary">
                    <h6 class="fw-bold text-body mb-2">Schritt-für-Schritt Anleitung:</h6>
                    <ol class="ps-3 mb-3 d-flex flex-column gap-2">
                        <li>
                            Öffne die <a href="https://console.cloud.google.com/" target="_blank" rel="noopener noreferrer" class="fw-semibold text-decoration-none">Google Cloud Console <i class="bi bi-box-arrow-up-right small"></i></a> und erstelle ein neues Projekt.
                        </li>
                        <li>
                            Gehe zu <strong>APIs &amp; Dienste &gt; Bibliothek</strong>, suche nach <strong>Google Calendar API</strong> und klicke auf <strong>Aktivieren</strong>.
                        </li>
                        <li>
                            Wechsle zu <strong>OAuth-Zustimmungsbildschirm</strong>:
                            <ul class="ps-3 mt-1">
                                <li>Nutzertyp: <em>Extern</em> (oder Intern bei Google Workspace).</li>
                                <li>App-Name: z. B. <em>ModulNest Kalender</em> und Support-Mail angeben.</li>
                                <li>Bereiche (Scopes): <code>.../auth/calendar</code> und <code>.../auth/userinfo.email</code>.</li>
                            </ul>
                        </li>
                        <li>
                            Gehe zu <strong>Anmeldedaten &gt; Anmeldedaten erstellen &gt; OAuth-Client-ID</strong>:
                            <ul class="ps-3 mt-1">
                                <li>Anwendungstyp: <strong>Webanwendung</strong></li>
                                <li>Name: z. B. <em>ModulNest Web-Client</em></li>
                                <li><strong>Autorisierte Weiterleitungs-URIs:</strong> Trage die oben stehende Weiterleitungs-URI ein!</li>
                            </ul>
                        </li>
                        <li>
                            Kopiere die erstellte <strong>Client-ID</strong> und das <strong>Client-Secret</strong> in das Formular links und speichere.
                        </li>
                    </ol>
                    <div class="alert alert-info py-2 px-3 small mb-0">
                        <i class="bi bi-lightbulb me-1"></i> <strong>Tipp:</strong> Im Entwicklungs- / Testmodus von Google müssen Testbenutzer (Google-Mail-Adressen) im OAuth-Zustimmungsbildschirm hinterlegt werden, um sich anzumelden.
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const copyBtn = document.getElementById('copy-uri-btn');
    const input = document.getElementById('redirect-uri-input');
    const icon = document.getElementById('copy-uri-icon');

    if (copyBtn && input) {
        copyBtn.addEventListener('click', async () => {
            try {
                await navigator.clipboard.writeText(input.value);
                icon.className = 'bi bi-check2 text-success';
                setTimeout(() => {
                    icon.className = 'bi bi-clipboard';
                }, 2000);
            } catch (err) {
                input.select();
                document.execCommand('copy');
                icon.className = 'bi bi-check2 text-success';
                setTimeout(() => {
                    icon.className = 'bi bi-clipboard';
                }, 2000);
            }
        });
    }
});
</script>
