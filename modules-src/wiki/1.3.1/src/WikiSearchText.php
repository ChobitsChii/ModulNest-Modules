<?php

declare(strict_types=1);

namespace ModulNest\Wiki;

final class WikiSearchText
{
    /** @return array{title:string,headings:string,body:string,code:string,path:string} */
    public static function extract(string $title, string $markdown, string $path): array
    {
        $code = '';
        $markdown = preg_replace_callback('/```[^\n]*\n(.*?)```/s', static function (array $match) use (&$code): string {
            $code .= "\n" . $match[1];
            return "\n";
        }, $markdown) ?? $markdown;
        $headings = [];
        if (preg_match_all('/^#{1,6}\s+(.+)$/mu', $markdown, $matches)) {
            $headings = $matches[1];
        }
        $body = preg_replace('/^#{1,6}\s+/mu', '', $markdown) ?? $markdown;
        $body = preg_replace('/!\[([^\]]*)\]\([^)]*\)/u', '$1', $body) ?? $body;
        $body = preg_replace('/\[([^\]]+)\]\([^)]*\)/u', '$1', $body) ?? $body;
        $body = preg_replace('/<[^>]+>/u', ' ', $body) ?? $body;
        $body = preg_replace('/[`*_~>|#-]+/u', ' ', $body) ?? $body;
        $pathText = str_replace(['/', '-', '_', '.'], ' ', preg_replace('/\.(md|markdown)$/i', '', $path) ?? $path);
        return [
            'title' => self::clean($title),
            'headings' => self::clean(implode("\n", $headings)),
            'body' => self::clean($body),
            'code' => self::clean($code),
            'path' => self::clean($pathText),
        ];
    }

    public static function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        return preg_replace('/\s+/u', ' ', $value) ?? $value;
    }

    /** @return list<string> */
    public static function terms(string $value): array
    {
        preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}._\\\\-]{1,79}/u', self::normalize($value), $matches);
        return array_values(array_filter($matches[0] ?? [], static fn (string $term): bool => mb_strlen($term) >= 2));
    }

    /** @return list<string> */
    public static function trigrams(string $term): array
    {
        if (mb_strlen($term) < 4) return [];
        $wrapped = '^' . $term . '$';
        $result = [];
        for ($i = 0, $length = mb_strlen($wrapped); $i <= $length - 3; $i++) $result[] = mb_substr($wrapped, $i, 3);
        return array_values(array_unique($result));
    }

    private static function clean(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }
}
