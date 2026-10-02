<?php

namespace App\Support;

class RichText
{
    public static function clean(?string $html): string
    {
        $html = trim((string) $html);
        if ($html === '') {
            return '';
        }

        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $html = str_replace("\u{00A0}", ' ', $html);
        $html = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;
        $html = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $html) ?? $html;
        $html = preg_replace('/\s(?:class|style|lang|align|dir)\s*=\s*("[^"]*"|\'[^\']*\')/i', '', $html) ?? $html;
        $html = preg_replace('/<\/?(?:span|font|o:p)\b[^>]*>/i', '', $html) ?? $html;
        $html = strip_tags($html, '<p><br><strong><b><em><i><u><ul><ol><li><a><h2><h3><blockquote>');
        $html = preg_replace('/<(p|br|strong|b|em|i|u|ul|ol|li|h2|h3|blockquote)\b[^>]*>/i', '<$1>', $html) ?? $html;
        $html = preg_replace_callback('/<a\b[^>]*>/i', function (array $match) {
            if (preg_match('/href\s*=\s*("|\')(https?:\/\/[^"\']+)\1/i', $match[0], $href)) {
                $url = htmlspecialchars($href[2], ENT_QUOTES, 'UTF-8');

                return '<a href="'.$url.'" rel="noopener noreferrer">';
            }

            return '';
        }, $html) ?? $html;
        $html = self::balanceAnchors($html);
        $html = preg_replace('/<p>(?:\s|&nbsp;)*<\/p>/i', '', $html) ?? $html;
        $html = preg_replace('/[ \t]{2,}/', ' ', $html) ?? $html;

        return trim($html);
    }

    private static function balanceAnchors(string $html): string
    {
        $parts = preg_split('/(<\/?a\b[^>]*>)/i', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return $html;
        }

        $open = 0;
        $out = '';
        foreach ($parts as $part) {
            if (preg_match('/^<a\b/i', $part)) {
                $open++;
                $out .= $part;
            } elseif (preg_match('/^<\/a\b/i', $part)) {
                if ($open > 0) {
                    $open--;
                    $out .= '</a>';
                }
            } else {
                $out .= $part;
            }
        }

        return $out;
    }
}
