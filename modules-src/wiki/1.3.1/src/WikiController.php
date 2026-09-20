<?php

declare(strict_types=1);

namespace ModulNest\Wiki;

use Modulon\Core\{DateTimeFormatter, Request, Response, RotatingFileLogger, Session, View};
use DateTimeZone;
use RuntimeException;
use Throwable;

final class WikiController
{
    public function __construct(private readonly Session $session, private readonly WikiRepository $repository, private readonly WikiService $service, private readonly string $basePath, private readonly ?\Modulon\Modules\Auth\AuthService $auth = null, private readonly ?WikiSearchService $searchService = null, private readonly string $assetBase = '') {}
    public function index(Request $request): Response
    {
        $page = $this->repository->rootPage();
        if ($page === null) {
            return $this->emptyState($request);
        }
        return $this->renderPage($page, $request);
    }
    public function page(Request $request): Response { $route=trim(substr($request->path(),strlen('/wiki')),'/'); return $this->show($route===''?'index':rawurldecode($route),$request); }
    private function show(string $route, Request $request): Response
    {
        $page=$this->repository->page($route);
        if($page===null) {
            if ($route === 'index' && $this->repository->visiblePageCount() === 0) return $this->emptyState($request);
            return new Response(View::render('errors/404',['title'=>'Wiki-Seite nicht gefunden','current_path'=>$request->path()]),404);
        }
        return $this->renderPage($page, $request);
    }
    private function emptyState(Request $request): Response
    {
        return new Response(View::render('@modulnest.wiki/empty', ['title'=>'Wiki noch nicht eingerichtet','current_path'=>$request->path(),'is_admin'=>$this->auth?->isAdmin() ?? false,'source'=>$this->repository->source(),'wiki_asset_base'=>$this->assetBase]));
    }
    private function renderPage(array $page, Request $request): Response
    {
        $file=$this->basePath.'/storage/modules/modulnest.wiki/content/'.$page['relative_path'];
        if(!is_file($file)) return new Response(View::render('errors/404',['title'=>'Wiki-Inhalt nicht verfügbar','current_path'=>$request->path()]),404);
        $renderer=new WikiMarkdownRenderer();
        $rendered = $renderer->renderPage((string) file_get_contents($file), (string) $page['relative_path'], (string) $page['title']);
        $rootPage = $this->repository->rootPage();
        $navigation = (new WikiNavigationBuilder())->build(
            $this->repository->pages(),
            (string) $page['route_path'],
            (string) ($rootPage['route_path'] ?? 'index'),
        );
        $toc = $rendered['toc'];
        if (count($toc) >= 2) {
            array_unshift($toc, ['id' => WikiMarkdownRenderer::headingId((string) $page['title']), 'title' => (string) $page['title'], 'level' => 1]);
        }
        return new Response(View::render('@modulnest.wiki/index',['title'=>(string)$page['title'],'current_path'=>$request->path(),'page'=>$page,'content_html'=>$rendered['html'],'toc'=>$toc,'navigation'=>$navigation,'source'=>$this->sourceForView(),'breadcrumbs'=>$this->breadcrumbs((string)$page['route_path']),'wiki_asset_base'=>$this->assetBase]));
    }
    public function asset(Request $request): Response
    {
        $path=rawurldecode(trim(substr($request->path(),strlen('/wiki/assets/')),'/'));
        if($path===''||str_contains($path,'..')||str_contains($path,'\\')) return new Response('',404);
        $asset=$this->repository->asset($path); $file=$this->basePath.'/storage/modules/modulnest.wiki/content/'.$path;
        if($asset===null||!is_file($file)) return new Response('',404);
        return new Response((string)file_get_contents($file),200,['Content-Type'=>(string)$asset['mime_type'],'Content-Length'=>(string)filesize($file),'Cache-Control'=>'private, max-age=3600','X-Content-Type-Options'=>'nosniff','Content-Disposition'=>'inline']);
    }
    public function search(Request $request): Response
    {
        $result = $this->searchService?->search((string) $request->query('q', '')) ?? ['query'=>'','too_short'=>false,'available'=>false,'results'=>[]];
        if ($request->expectsJson()) return new Response((string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 200, ['Content-Type'=>'application/json; charset=UTF-8','Cache-Control'=>'no-store']);
        return new Response(View::render('@modulnest.wiki/search',['title'=>'Wiki durchsuchen','current_path'=>$request->path(),'search'=>$result,'wiki_asset_base'=>$this->assetBase]));
    }
    public function admin(Request $request): Response { $status=$this->searchService?->status()??['status'=>'missing','version'=>WikiSearchIndexer::VERSION,'pages'=>0,'terms'=>0,'updated_at'=>null];$status['updated_at_local']=DateTimeFormatter::formatUserDateTime($status['updated_at']??'', $this->userTimezone());return new Response(View::render('@modulnest.wiki/admin',['title'=>'Wiki verwalten','current_path'=>$request->path(),'source'=>$this->sourceForView(),'page_count'=>$this->repository->pageCount(),'asset_count'=>$this->repository->assetCount(),'search_index'=>$status,'message'=>$this->session->pullFlash('wiki_info'),'error'=>$this->session->pullFlash('wiki_error'),'wiki_asset_base'=>$this->assetBase])); }
    public function adminSave(Request $request): Response
    {
        try { if((string)$request->input('source_type','github')==='local') $this->service->saveLocalConfig((string)$request->input('docs_root',''),(string)$request->input('enabled','')==='1'); else $this->service->saveConfig((string)$request->input('owner',''),(string)$request->input('repository',''),(string)$request->input('ref',''),(string)$request->input('docs_root','docs'),(string)$request->input('enabled','')==='1'); $this->session->flash('wiki_info','Wiki-Quelle gespeichert.'); }
        catch(Throwable $e){$this->session->flash('wiki_error',$e instanceof RuntimeException?$e->getMessage():'Die Wiki-Quelle konnte nicht gespeichert werden.');}
        return Response::redirect('/admin/wiki');
    }
    public function localDirectories(Request $request): Response { try { $data=(new LocalWikiPath($this->basePath))->directories((string)$request->query('path','')); return new Response((string)json_encode($data, JSON_THROW_ON_ERROR),200,['Content-Type'=>'application/json; charset=UTF-8']); } catch (RuntimeException) { return new Response('{"error":"invalid_path"}',422,['Content-Type'=>'application/json; charset=UTF-8']); } }
    public function sync(Request $request): Response
    {
        try {$result=$this->service->sync();$this->session->flash('wiki_info',sprintf('Synchronisierung abgeschlossen: %d neu, %d geändert, %d entfernt.',$result['added'],$result['changed'],$result['deleted']));}
        catch(Throwable){$this->session->flash('wiki_error','Synchronisierung fehlgeschlagen. Der bisherige lokale Stand bleibt verfügbar.');}
        return Response::redirect('/admin/wiki');
    }
    public function rebuildSearch(Request $request): Response
    {
        try { $result=$this->searchService?->rebuild();if($result===null)throw new RuntimeException('Suchindex nicht verfügbar.');$this->session->flash('wiki_info',sprintf('Suchindex neu aufgebaut: %d Seiten, %d Begriffe.',$result['pages'],$result['terms'])); }
        catch(Throwable){(new RotatingFileLogger($this->basePath))->write('wiki',['event'=>'search_rebuild_failed','code'=>'wiki_search_rebuild_failed']);$this->session->flash('wiki_error','Der Suchindex konnte nicht neu aufgebaut werden. Der bisherige Index bleibt erhalten.');}
        return Response::redirect('/admin/wiki');
    }
    private function sourceForView(): ?array
    {
        $source = $this->repository->source();
        if ($source === null) {
            return null;
        }
        $timezone = $this->userTimezone();
        $source['last_sync_at_local'] = DateTimeFormatter::formatUserDateTime($source['last_sync_at'] ?? '', $timezone);
        $source['timezone_name'] = $timezone->getName();
        return $source;
    }
    private function userTimezone(): DateTimeZone
    {
        try {
            $user = $this->auth?->currentUser();
            return DateTimeFormatter::resolveTimezone(is_array($user) ? (string) ($user['timezone'] ?? '') : '');
        } catch (Throwable) {
            return DateTimeFormatter::resolveTimezone();
        }
    }
    /** @return list<array{label:string,url:string}> */ private function breadcrumbs(string $route): array { $items=[['label'=>'Wiki','url'=>'/wiki']];if($route==='index')return $items;$parts=explode('/',$route);$path=[];foreach($parts as $part){$path[]=$part;$items[]=['label'=>ucwords(str_replace(['-','_'],' ',$part)),'url'=>'/wiki/'.implode('/',$path)];}return $items; }
}
