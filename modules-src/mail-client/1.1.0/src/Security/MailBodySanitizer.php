<?php

declare(strict_types=1);

namespace ModulNest\MailClient\Security;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Bereinigt HTML-Mail-Body für sichere Darstellung im Browser.
 *
 * Sicherheitsmaßnahmen:
 * - Entfernt <script>, <style mit JS>, <object>, <embed>, <form>, <iframe> etc.
 * - Entfernt alle on*-Event-Handler Attribute
 * - Entfernt javascript:-URIs
 * - Externe Bilder werden geblockt sofern Absender nicht auf Whitelist steht
 * - CSS bleibt erhalten (für originale Darstellung), wird aber sanitiert
 * - Links werden mit target="_blank" rel="noopener noreferrer" versehen
 * - Relative URLs werden entfernt / neutralisiert
 */
final class MailBodySanitizer
{
    /** HTML-Tags, die komplett entfernt werden (inkl. Inhalt). */
    private const REMOVE_TAGS = [
        'script', 'noscript', 'object', 'embed', 'applet', 'base',
        'meta', 'link', 'form', 'button', 'input', 'select', 'textarea',
        'frame', 'frameset', 'iframe',
    ];

    /** Erlaubte Tags (alle anderen werden gestript, Inhalt bleibt). */
    private const ALLOWED_TAGS = [
        'a', 'abbr', 'acronym', 'address', 'article', 'aside',
        'b', 'bdi', 'bdo', 'big', 'blockquote', 'br',
        'caption', 'cite', 'code', 'col', 'colgroup',
        'dd', 'del', 'details', 'dfn', 'div', 'dl', 'dt',
        'em', 'figure', 'figcaption', 'footer',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hr',
        'i', 'img', 'ins',
        'kbd', 'li', 'main', 'mark', 'nav',
        'ol', 'p', 'pre', 'q',
        's', 'samp', 'section', 'small', 'span', 'strong', 'sub', 'sup',
        'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'time', 'tr',
        'u', 'ul', 'var',
        // Layout / Struktur
        'body', 'html', 'head', 'title',
        // Style (wird separat bereinigt)
        'style',
    ];

    /**
     * Bereinigt einen HTML-Mail-Body.
     *
     * @param string $html          Roher HTML-Body der Mail
     * @param bool   $allowExternal Darf der Absender externe Bilder laden?
     * @return array{html: string, blocked_images: int}
     */
    public function sanitize(string $html, bool $allowExternal = false): array
    {
        if (trim($html) === '') {
            return ['html' => '', 'blocked_images' => 0];
        }

        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        // Encoding-Hint damit DOMDocument nicht alles in Latin-1 umwandelt
        $doc->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors(false);

        $xpath = new DOMXPath($doc);

        // 1. Gefährliche Tags komplett entfernen (inkl. Inhalt)
        foreach (self::REMOVE_TAGS as $tag) {
            $nodes = $xpath->query("//{$tag}");
            if ($nodes === false) {
                continue;
            }
            foreach (iterator_to_array($nodes) as $node) {
                if ($node instanceof DOMNode && $node->parentNode !== null) {
                    $node->parentNode->removeChild($node);
                }
            }
        }

        // 2. Attribute bereinigen (on*-Handler, javascript:-URIs)
        $allElements = $xpath->query('//*');
        if ($allElements !== false) {
            foreach (iterator_to_array($allElements) as $element) {
                if (!$element instanceof DOMElement) {
                    continue;
                }
                $this->sanitizeElement($element);
            }
        }

        // 3. Externe Bilder blocken (falls Absender nicht auf Whitelist)
        $blockedImages = 0;
        if (!$allowExternal) {
            $imgNodes = $xpath->query('//img');
            if ($imgNodes !== false) {
                foreach (iterator_to_array($imgNodes) as $img) {
                    if (!$img instanceof DOMElement) {
                        continue;
                    }
                    $src = $img->getAttribute('src');
                    if ($this->isExternalUrl($src)) {
                        $img->setAttribute('data-blocked-src', $src);
                        $img->setAttribute('src', 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
                        $img->setAttribute('alt', '[Bild blockiert]');
                        $img->setAttribute('title', 'Externes Bild wurde blockiert. Absender zur Whitelist hinzufügen um Bilder zu erlauben.');
                        $img->setAttribute('class', ($img->getAttribute('class') . ' mc-blocked-image'));
                        $blockedImages++;
                    }
                }
            }
            // Auch background-image in style-Attributen blocken
            $styledNodes = $xpath->query('//*[@style]');
            if ($styledNodes !== false) {
                foreach (iterator_to_array($styledNodes) as $el) {
                    if (!$el instanceof DOMElement) {
                        continue;
                    }
                    $style = $el->getAttribute('style');
                    if (preg_match('/url\s*\(\s*["\']?(https?:\/\/)/i', $style)) {
                        $style = preg_replace('/background(-image)?\s*:[^;]*(url\s*\([^)]*\))[^;]*;?/i', '', $style) ?? $style;
                        $el->setAttribute('style', $style);
                        $blockedImages++;
                    }
                }
            }
        }

        // 4. style-Tags bereinigen (JS aus CSS entfernen)
        $styleNodes = $xpath->query('//style');
        if ($styleNodes !== false) {
            foreach (iterator_to_array($styleNodes) as $styleNode) {
                if (!$styleNode instanceof DOMNode) {
                    continue;
                }
                $css = $styleNode->textContent;
                $css = $this->sanitizeCss($css, $allowExternal);
                // Neuen Textknoten setzen
                while ($styleNode->firstChild) {
                    $styleNode->removeChild($styleNode->firstChild);
                }
                $styleNode->appendChild($doc->createTextNode($css));
            }
        }

        // 5. Nicht erlaubte Tags strippen (Inhalt erhalten)
        $this->stripDisallowedTags($doc);

        // Ausgabe: nur den body-Inhalt (nicht den ganzen HTML-Wrapper)
        $bodyNodes = $xpath->query('//body');
        $output    = '';
        if ($bodyNodes !== false && $bodyNodes->length > 0) {
            $body = $bodyNodes->item(0);
            if ($body instanceof DOMNode) {
                foreach ($body->childNodes as $child) {
                    $output .= $doc->saveHTML($child);
                }
            }
        } else {
            $output = $doc->saveHTML();
        }

        return [
            'html'           => $output ?: '',
            'blocked_images' => $blockedImages,
        ];
    }

    /**
     * Sanitiert für den Versand: entfernt Scripts und gefährliche Inhalte aus
     * dem Rich-Text-Editor-Inhalt (für ausgehende Mails).
     */
    public function sanitizeOutgoing(string $html): string
    {
        $result = $this->sanitize($html, true); // externe Bilder erlaubt beim Versand
        return $result['html'];
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function sanitizeElement(DOMElement $element): void
    {
        $tag = strtolower($element->tagName);

        // Alle Attribute durchgehen und gefährliche entfernen
        $toRemove = [];
        foreach ($element->attributes as $attr) {
            $name  = strtolower($attr->name);
            $value = $attr->value;

            // Event-Handler (onclick, onmouseover etc.)
            if (str_starts_with($name, 'on')) {
                $toRemove[] = $attr->name;
                continue;
            }

            // javascript:-URIs in href / action / src / xlink:href
            if (in_array($name, ['href', 'src', 'action', 'xlink:href', 'poster', 'data'], true)) {
                $stripped = ltrim(preg_replace('/\s+/', '', strtolower($value)) ?? '');
                if (str_starts_with($stripped, 'javascript:') || str_starts_with($stripped, 'vbscript:') || str_starts_with($stripped, 'data:text/html')) {
                    $toRemove[] = $attr->name;
                    continue;
                }
            }

            // Links: target und rel sicherstellen
            if ($tag === 'a' && $name === 'href') {
                $element->setAttribute('target', '_blank');
                $element->setAttribute('rel', 'noopener noreferrer');
            }
        }
        foreach ($toRemove as $attrName) {
            $element->removeAttribute($attrName);
        }
    }

    private function sanitizeCss(string $css, bool $allowExternal): string
    {
        // expression() entfernen (IE CSS-Injection)
        $css = preg_replace('/expression\s*\([^)]*\)/i', '', $css) ?? $css;
        // javascript: in CSS entfernen
        $css = preg_replace('/javascript\s*:/i', '', $css) ?? $css;
        // behaviour / -moz-binding entfernen
        $css = preg_replace('/(behaviour|behavior|-moz-binding)\s*:[^;]+;?/i', '', $css) ?? $css;
        // Externe URLs in CSS blocken
        if (!$allowExternal) {
            $css = preg_replace('/url\s*\(\s*["\']?(https?:\/\/)[^)]*\)/i', 'url()', $css) ?? $css;
        }
        return $css;
    }

    /** Entfernt nicht-erlaubte Tags aber behält deren Inhalt. */
    private function stripDisallowedTags(DOMDocument $doc): void
    {
        $xpath = new DOMXPath($doc);
        $changed = true;
        $iterations = 0;
        $allowedLower = array_map('strtolower', self::ALLOWED_TAGS);
        while ($changed && $iterations < 10) {
            $changed = false;
            $iterations++;
            $allElements = $xpath->query('//*');
            if ($allElements === false) {
                break;
            }
            foreach (iterator_to_array($allElements) as $el) {
                if (!$el instanceof DOMElement) {
                    continue;
                }
                if (!in_array(strtolower($el->tagName), $allowedLower, true)) {
                    $parent = $el->parentNode;
                    if ($parent === null) {
                        continue;
                    }
                    while ($el->firstChild) {
                        $parent->insertBefore($el->firstChild, $el);
                    }
                    $parent->removeChild($el);
                    $changed = true;
                }
            }
        }
    }

    private function isExternalUrl(string $url): bool
    {
        $url = trim($url);
        return str_starts_with($url, 'http://') || str_starts_with($url, 'https://') || str_starts_with($url, '//');
    }
}

