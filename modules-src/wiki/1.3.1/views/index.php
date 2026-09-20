<link rel="stylesheet" href="<?= htmlspecialchars((string) ($wiki_asset_base ?? ''), ENT_QUOTES, 'UTF-8') ?>/wiki.css">
<script src="<?= htmlspecialchars((string) ($wiki_asset_base ?? ''), ENT_QUOTES, 'UTF-8') ?>/wiki.js" defer></script>
<?php
$wikiLink = static function (array $item): string {
    $route = (string) ($item['route_path'] ?? '');
    return '/wiki/' . htmlspecialchars($route === 'index' ? '' : $route, ENT_QUOTES, 'UTF-8');
};
$renderGroup = static function (array $group, string $path = '') use (&$renderGroup, $wikiLink): void {
    $groupPath = trim($path . '/' . (string) $group['key'], '/');
    $contentId = 'wiki-nav-group-' . substr(hash('sha256', $groupPath), 0, 12);
    $expanded = !empty($group['is_active']);
    ?>
    <section class="wiki-nav-group<?= $expanded ? ' is-active-group' : '' ?>" data-wiki-nav-group="<?= htmlspecialchars($groupPath, ENT_QUOTES, 'UTF-8') ?>">
        <div class="wiki-nav-group-title">
            <?php if (!empty($group['landing'])): ?>
                <a class="wiki-nav-group-landing<?= !empty($group['landing']['is_active']) ? ' is-active' : '' ?>" href="<?= $wikiLink($group['landing']) ?>"><i class="bi bi-folder2-open" aria-hidden="true"></i><span><?= htmlspecialchars((string) $group['label'], ENT_QUOTES, 'UTF-8') ?></span></a>
                <button class="wiki-nav-chevron-button" type="button" data-wiki-nav-toggle aria-controls="<?= $contentId ?>" aria-expanded="true" aria-label="Ordner <?= htmlspecialchars((string) $group['label'], ENT_QUOTES, 'UTF-8') ?> ein- oder ausklappen"><i class="bi bi-chevron-down wiki-nav-chevron" aria-hidden="true"></i></button>
            <?php else: ?>
                <button class="wiki-nav-group-toggle-row" type="button" data-wiki-nav-toggle aria-controls="<?= $contentId ?>" aria-expanded="true" aria-label="Ordner <?= htmlspecialchars((string) $group['label'], ENT_QUOTES, 'UTF-8') ?> ein- oder ausklappen"><span class="wiki-nav-group-toggle-label"><i class="bi bi-folder2-open" aria-hidden="true"></i><span><?= htmlspecialchars((string) $group['label'], ENT_QUOTES, 'UTF-8') ?></span></span><span class="wiki-nav-group-toggle-cell"><i class="bi bi-chevron-down wiki-nav-chevron" aria-hidden="true"></i></span></button>
            <?php endif; ?>
        </div>
        <ul id="<?= $contentId ?>" class="wiki-nav-list wiki-nav-group-items">
            <?php foreach ($group['pages'] as $item): ?>
                <li><a class="wiki-nav-link<?= !empty($item['is_active']) ? ' is-active' : '' ?>"<?= !empty($item['is_active']) ? ' aria-current="page"' : '' ?> href="<?= $wikiLink($item) ?>"><?= htmlspecialchars((string) $item['title'], ENT_QUOTES, 'UTF-8') ?></a></li>
            <?php endforeach; ?>
            <?php foreach ($group['groups'] as $child): ?>
                <li class="wiki-nav-subgroup"><?php $renderGroup($child, $groupPath); ?></li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php
};
$toc = is_array($toc ?? null) ? $toc : [];
?>
<section class="wiki-layout<?= count($toc) >= 2 ? ' has-wiki-toc' : '' ?>">
  <aside class="wiki-sidebar" aria-label="Wiki-Navigation">
    <nav class="wiki-tree">
      <div class="wiki-nav-title">Wiki</div>
      <form class="wiki-search-form" action="/wiki/search" method="get" role="search" data-wiki-search>
        <label class="visually-hidden" for="wiki-search-query">Wiki durchsuchen</label>
        <input class="form-control form-control-sm" id="wiki-search-query" type="search" name="q" maxlength="120" placeholder="Wiki durchsuchen …" autocomplete="off" data-wiki-search-input aria-autocomplete="list" aria-expanded="false" aria-controls="wiki-search-results">
        <div class="wiki-search-popover" id="wiki-search-results" data-wiki-search-results role="listbox" aria-live="polite" hidden></div>
      </form>
      <ul class="wiki-nav-list wiki-nav-start">
        <li><a class="wiki-nav-link<?= ($page['route_path'] === ($navigation['root_route'] ?? 'index')) ? ' is-active' : '' ?>"<?= ($page['route_path'] === ($navigation['root_route'] ?? 'index')) ? ' aria-current="page"' : '' ?> href="/wiki">Start</a></li>
      </ul>
      <?php foreach ($navigation['groups'] as $group): $renderGroup($group); endforeach; ?>
      <?php if ($navigation['root_pages'] !== []): ?>
        <section class="wiki-nav-root-pages">
          <ul class="wiki-nav-list">
            <?php foreach ($navigation['root_pages'] as $item): ?>
              <li><a class="wiki-nav-link<?= !empty($item['is_active']) ? ' is-active' : '' ?>"<?= !empty($item['is_active']) ? ' aria-current="page"' : '' ?> href="<?= $wikiLink($item) ?>"><?= htmlspecialchars((string) $item['title'], ENT_QUOTES, 'UTF-8') ?></a></li>
            <?php endforeach; ?>
          </ul>
        </section>
      <?php endif; ?>
    </nav>
  </aside>
  <article class="wiki-content">
    <?php if (count($toc) >= 2): ?>
      <details class="wiki-toc wiki-toc-mobile">
        <summary>Auf dieser Seite</summary>
        <ol><?php foreach ($toc as $entry): ?><li class="wiki-toc-level-<?= (int) $entry['level'] ?>"><a href="#<?= htmlspecialchars((string) $entry['id'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $entry['title'], ENT_QUOTES, 'UTF-8') ?></a></li><?php endforeach; ?></ol>
      </details>
    <?php endif; ?>
    <nav class="wiki-breadcrumbs" aria-label="Breadcrumbs"><?php foreach ($breadcrumbs as $crumb): ?><a href="<?= htmlspecialchars($crumb['url'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($crumb['label'], ENT_QUOTES, 'UTF-8') ?></a><?php endforeach; ?></nav>
    <h1 id="<?= htmlspecialchars(\ModulNest\Wiki\WikiMarkdownRenderer::headingId((string) $page['title']), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($page['title'], ENT_QUOTES, 'UTF-8') ?></h1>
    <?= $content_html ?>
    <?php if (!empty($source['last_commit_sha']) || !empty($source['last_sync_at_local'])): ?>
      <p class="wiki-source">
        <?php if (!empty($source['last_commit_sha'])): ?>Stand <?= htmlspecialchars(substr((string)$source['last_commit_sha'], 0, 12), ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
        <?php if (!empty($source['last_commit_sha']) && !empty($source['last_sync_at_local'])): ?> · <?php endif; ?>
        <?php if (!empty($source['last_sync_at_local'])): ?>Synchronisiert am <?= htmlspecialchars((string) $source['last_sync_at_local'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
      </p>
    <?php endif; ?>
  </article>
  <?php if (count($toc) >= 2): ?>
    <aside class="wiki-toc wiki-toc-desktop" aria-label="Auf dieser Seite">
      <div class="wiki-toc-title">Auf dieser Seite</div>
      <ol><?php foreach ($toc as $entry): ?><li class="wiki-toc-level-<?= (int) $entry['level'] ?>"><a href="#<?= htmlspecialchars((string) $entry['id'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $entry['title'], ENT_QUOTES, 'UTF-8') ?></a></li><?php endforeach; ?></ol>
    </aside>
  <?php endif; ?>
</section>
