<?php

declare(strict_types=1);

namespace ModulNest\Wiki;

/** Creates safe semantic highlighting from plain search-result text. */
final class WikiSearchHighlighter
{
    /** @param list<string> $terms */
    public static function html(string $text, array $terms): string
    {
        $terms = array_values(array_unique(array_filter(array_map('strval', $terms), static fn (string $term): bool => $term !== '')));
        usort($terms, static fn (string $left, string $right): int => mb_strlen($right) <=> mb_strlen($left));
        if ($terms === []) return self::escape($text);
        $pattern = '/(' . implode('|', array_map(static fn (string $term): string => preg_quote($term, '/'), $terms)) . ')/iu';
        $parts = preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts)) return self::escape($text);
        $html = '';
        foreach ($parts as $index => $part) $html .= $index % 2 === 1 ? '<mark>' . self::escape($part) . '</mark>' : self::escape($part);
        return $html;
    }

    private static function escape(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
}
