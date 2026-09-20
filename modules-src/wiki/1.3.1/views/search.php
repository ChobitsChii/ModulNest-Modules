<?php
declare(strict_types=1);
$e=static fn(mixed $value):string=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
$search=is_array($search??null)?$search:['query'=>'','too_short'=>false,'available'=>false,'results'=>[]];
?>
<link rel="stylesheet" href="<?= htmlspecialchars((string) ($wiki_asset_base ?? ''), ENT_QUOTES, 'UTF-8') ?>/wiki.css">
<script src="<?= htmlspecialchars((string) ($wiki_asset_base ?? ''), ENT_QUOTES, 'UTF-8') ?>/wiki.js" defer></script>
<section class="wiki-search-page">
  <nav class="wiki-breadcrumbs" aria-label="Breadcrumbs"><a href="/wiki">Wiki</a></nav>
  <h1>Wiki durchsuchen</h1>
  <form class="wiki-search-form wiki-search-form-page" action="/wiki/search" method="get" role="search" data-wiki-search>
    <label class="visually-hidden" for="wiki-search-page-query">Wiki durchsuchen</label>
    <div class="input-group"><input class="form-control" id="wiki-search-page-query" type="search" name="q" value="<?= $e($search['query']) ?>" maxlength="120" placeholder="Wiki durchsuchen …" autocomplete="off" data-wiki-search-input aria-controls="wiki-search-page-results"><button class="btn btn-primary" type="submit">Suchen</button></div>
  </form>
  <div id="wiki-search-page-results" class="wiki-search-page-results" data-wiki-search-results aria-live="polite">
    <?php if (!$search['available']): ?><div class="alert alert-secondary mt-3">Der Suchindex ist noch nicht verfügbar. Ein Administrator kann ihn nach einer Synchronisierung neu aufbauen.</div>
    <?php elseif ($search['too_short'] && $search['query']!==''): ?><p class="text-body-secondary mt-3">Bitte mindestens zwei Zeichen eingeben.</p>
    <?php elseif ($search['query']!=='' && $search['results']===[]): ?><p class="text-body-secondary mt-3">Keine passenden Wiki-Seiten gefunden.</p>
    <?php else: foreach($search['results'] as $result): $route=$result['route_path']==='index'?'':implode('/',array_map('rawurlencode',explode('/',(string)$result['route_path'])));$terms=is_array($result['matched_terms']??null)?$result['matched_terms']:[]; ?><article class="wiki-search-result"><a href="/wiki/<?= $e($route) ?>"><?= \ModulNest\Wiki\WikiSearchHighlighter::html((string)$result['title'],$terms) ?></a><div class="wiki-search-context"><?= \ModulNest\Wiki\WikiSearchHighlighter::html((string)$result['context'],$terms) ?></div><p><?= \ModulNest\Wiki\WikiSearchHighlighter::html((string)$result['snippet'],$terms) ?></p></article><?php endforeach; endif; ?>
  </div>
</section>
