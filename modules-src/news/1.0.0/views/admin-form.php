<?php
declare(strict_types=1);

$message = (string) ($message ?? '');
$error = (string) ($error ?? '');
$entry = is_array($entry ?? null) ? $entry : null;
$types = is_array($types ?? null) ? $types : ['news', 'update', 'release', 'note'];
$statuses = is_array($statuses ?? null) ? $statuses : ['draft', 'published'];
$adminSection = (string) ($admin_section ?? 'news');

$isEdit = is_array($entry);
$entryId = (int) ($entry['id'] ?? 0);
$title = (string) ($entry['title'] ?? '');
$slug = (string) ($entry['slug'] ?? '');
$excerpt = (string) ($entry['excerpt'] ?? '');
$content = (string) ($entry['content'] ?? '');
$type = (string) ($entry['type'] ?? 'news');
$version = (string) ($entry['version'] ?? '');
$status = (string) ($entry['status'] ?? 'draft');
$publishedAtRaw = (string) ($entry['published_at'] ?? '');
$publishedAtInput = $publishedAtRaw !== '' ? substr(str_replace(' ', 'T', $publishedAtRaw), 0, 16) : '';
$csrfToken = (string) ($csrf_token ?? '');
?>
<div class="d-flex align-items-center justify-content-between mb-4">
    <h1 class="h4 mb-0"><?= $isEdit ? 'News bearbeiten' : 'News erstellen' ?></h1>
    <a href="/admin/news" class="btn btn-outline-secondary btn-sm">Zur Übersicht</a>
</div>

<?php if ($message !== ''): ?>
    <div class="alert alert-success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<div class="card shadow-sm border-0 app-card">
    <div class="card-body">
        <form method="post" action="<?= $isEdit ? '/admin/news/update' : '/admin/news/create' ?>" class="row g-3">
            <?= \Modulon\Core\View::csrfField($csrfToken) ?>
            <?php if ($isEdit): ?>
                <input type="hidden" name="entry_id" value="<?= $entryId ?>">
            <?php endif; ?>

            <div class="col-12 col-md-6">
                <label class="form-label mb-1" for="news_title">Titel</label>
                <input id="news_title" class="form-control" type="text" name="title" required value="<?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="col-12 col-md-6">
                <label class="form-label mb-1" for="news_slug">Slug</label>
                <input id="news_slug" class="form-control" type="text" name="slug" value="<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?>" placeholder="wird-aus-titel-erzeugt">
            </div>
            <div class="col-12">
                <label class="form-label mb-1" for="news_excerpt">Kurzbeschreibung</label>
                <input id="news_excerpt" class="form-control" type="text" name="excerpt" maxlength="400" required value="<?= htmlspecialchars($excerpt, ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="col-12">
                <label class="form-label mb-1" for="news_content">Inhalt</label>
                <textarea id="news_content" class="form-control" name="content" rows="10" required><?= htmlspecialchars($content, ENT_QUOTES, 'UTF-8') ?></textarea>
                <div class="form-text">
                    News-Inhalte unterstützen Markdown: <strong>fett</strong>, <em>kursiv</em>, <code>Code</code>, Listen, Links und Überschriften.
                    HTML wird aus Sicherheitsgründen entfernt.
                </div>
                <details class="markdown-help mt-2">
                    <summary>Markdown-Beispiele anzeigen</summary>
                    <pre><code>**fett**
*kursiv*
`app/Config/version.php`

- Punkt 1
- Punkt 2

[Linktext](https://example.com)

## Überschrift</code></pre>
                </details>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label mb-1" for="news_type">Typ</label>
                <select id="news_type" class="form-select" name="type">
                    <?php foreach ($types as $opt): ?>
                        <option value="<?= htmlspecialchars((string) $opt, ENT_QUOTES, 'UTF-8') ?>"<?= $type === $opt ? ' selected' : '' ?>><?= htmlspecialchars((string) $opt, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label mb-1" for="news_version">Version</label>
                <input id="news_version" class="form-control" type="text" name="version" placeholder="0.1.0" value="<?= htmlspecialchars($version, ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label mb-1" for="news_status">Status</label>
                <select id="news_status" class="form-select" name="status">
                    <?php foreach ($statuses as $opt): ?>
                        <option value="<?= htmlspecialchars((string) $opt, ENT_QUOTES, 'UTF-8') ?>"<?= $status === $opt ? ' selected' : '' ?>><?= htmlspecialchars((string) $opt, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label mb-1" for="news_published_at">Veröffentlicht am</label>
                <input id="news_published_at" class="form-control" type="datetime-local" name="published_at" value="<?= htmlspecialchars($publishedAtInput, ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-primary btn-sm"><?= $isEdit ? 'Speichern' : 'Eintrag erstellen' ?></button>
                <a href="/admin/news" class="btn btn-outline-secondary btn-sm ms-1">Abbrechen</a>
            </div>
        </form>
    </div>
</div>
