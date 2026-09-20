<?php

declare(strict_types=1);

namespace ModulNest\Wiki;

use Modulon\Core\MarkdownRenderer;

/** Wiki-local Markdown policy: no raw HTML, only local approved images and routed Markdown links. */
final class WikiMarkdownRenderer
{
    public function __construct(private readonly MarkdownRenderer $renderer = new MarkdownRenderer()) {}

    public function render(string $markdown, string $pageRoute): string
    {
        $html=$this->renderer->render($markdown, true);
        $html=preg_replace_callback('/<a href="([^"]*)"/i', fn(array $m): string => '<a href="'.htmlspecialchars($this->link((string)$m[1],$pageRoute),ENT_QUOTES,'UTF-8').'"', $html)??$html;
        return preg_replace_callback('/<img src="([^"]*)"([^>]*)>/i', function(array $m) use ($pageRoute): string { $asset=$this->asset((string)$m[1],$pageRoute); return $asset===null?'':'<img src="'.htmlspecialchars($asset,ENT_QUOTES,'UTF-8').'"'.$m[2].'>'; },$html)??$html;
    }

    /**
     * Renders one Wiki page as the sole owner of Markdown heading semantics.
     * The layout renders the page title as H1, so Markdown H1s are either
     * removed when identical or demoted to H2 when they add distinct content.
     *
     * @return array{html:string,toc:list<array{id:string,title:string,level:int}>}
     */
    public function renderPage(string $markdown, string $pageRoute, string $pageTitle): array
    {
        $html = $this->render($markdown, $pageRoute);
        $toc = [];
        $usedIds = [];
        $html = preg_replace_callback('/<h([1-6])([^>]*)>(.*?)<\/h\1>/is', function (array $match) use (&$toc, &$usedIds, $pageTitle): string {
            $level = (int) $match[1];
            $attributes = (string) $match[2];
            $inner = (string) $match[3];
            $heading = trim(html_entity_decode(strip_tags($inner), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            if ($level === 1) {
                if ($this->sameHeading($heading, $pageTitle)) {
                    return '';
                }
                $level = 2;
            }

            $id = $this->uniqueHeadingId($heading, $usedIds);
            if ($level === 2 || $level === 3) {
                $toc[] = ['id' => $id, 'title' => $heading, 'level' => $level];
            }
            return '<h' . $level . ' id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '"' . $attributes . '>' . $inner . '</h' . $level . '>';
        }, $html) ?? $html;

        return ['html' => $html, 'toc' => $toc];
    }

    private function link(string $target,string $page): string { if($target==='')return $target; if(str_starts_with($target,'#'))return '#'.self::headingId(rawurldecode(substr($target,1))); $parts=parse_url($target); if($parts===false)return '#'; if(isset($parts['scheme']))return in_array(strtolower($parts['scheme']),['http','https'],true)?$target:'#'; if(str_starts_with($target,'/'))return '#'; $path=$this->resolve($target,$page); if($path===null)return '#'; $fragment=isset($parts['fragment'])?'#'.self::headingId(rawurldecode((string)$parts['fragment'])):''; if(!preg_match('/\.(md|markdown)$/i',$path))return '#'; return '/wiki/'.implode('/',array_map('rawurlencode',explode('/',$this->route($path)))).$fragment; }
    private function asset(string $target,string $page): ?string { $parts=parse_url($target);if($parts===false||isset($parts['scheme'])||str_starts_with($target,'/'))return null;$path=$this->resolve($target,$page);if($path===null||!preg_match('/\.(png|jpe?g|gif|webp)$/i',$path))return null;return '/wiki/assets/'.implode('/',array_map('rawurlencode',explode('/',$path))); }
    private function resolve(string $target,string $page): ?string { $path=explode('#',explode('?',$target,2)[0],2)[0];$parts=array_merge(explode('/',dirname($page)),explode('/',$path));$safe=[];foreach($parts as $part){if($part===''||$part==='.')continue;if($part==='..'){if($safe===[])return null;array_pop($safe);continue;}if(!preg_match('/^[A-Za-z0-9._ -]+$/',$part))return null;$safe[]=$part;}return implode('/',$safe); }
    private function route(string $path): string { $path=preg_replace('/\.(md|markdown)$/i','',$path)??$path;$p=explode('/',$path);if(in_array(strtolower((string)end($p)),['readme','index'],true))array_pop($p);return implode('/',$p)?:'index'; }

    private function sameHeading(string $left, string $right): bool
    {
        return self::headingId($left) === self::headingId($right);
    }

    /** Stable ASCII anchors keep links portable across browsers and sources. */
    public static function headingId(string $heading): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $heading);
        $value = strtolower($ascii === false ? $heading : $ascii);
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        $value = trim($value, '-');
        return $value !== '' ? $value : 'section';
    }

    /** @param array<string,int> $usedIds */
    private function uniqueHeadingId(string $heading, array &$usedIds): string
    {
        $base = self::headingId($heading);
        $usedIds[$base] = ($usedIds[$base] ?? 0) + 1;
        return $usedIds[$base] === 1 ? $base : $base . '-' . $usedIds[$base];
    }
}
