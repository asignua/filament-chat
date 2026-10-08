<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Support;

use Illuminate\Support\HtmlString;

/**
 * Message text for display: escaped, with highlighted mentions, clickable
 * links and line breaks.
 * No HTML from the author — only what this class adds.
 */
final class ChatText
{
    /**
     * @param array<int, string> $mentions key → name of the people mentioned in the message
     */
    public static function toHtml(string $body, array $mentions = [], ?int $me = null): HtmlString
    {
        // The text is already escaped: a link ends before an escaped quote or angle bracket
        // ("https://x.com", <https://x.com>), and what follows it as punctuation stays outside.
        $linked = (string) preg_replace_callback(
            '~\bhttps?://(?:(?!&(?:quot|\#039|lt|gt);)[^\s<])+~u',
            function (array $match): string {
                [$url, $tail] = self::splitTrailingPunctuation($match[0]);

                return '<a href="'.$url.'" target="_blank" rel="noopener noreferrer" class="underline break-all">'.$url.'</a>'.$tail;
            },
            Mentions::highlight(e($body), $mentions, $me),
        );

        return new HtmlString(nl2br($linked, false));
    }

    /**
     * "(see https://x.com/a)." → the link without ")." — a closing bracket stays when the URL
     * itself opens one (Wikipedia-style addresses), a ";" stays when it ends an entity (&amp;).
     *
     * @return array{0: string, 1: string} the link and the stripped tail
     */
    private static function splitTrailingPunctuation(string $url): array
    {
        $tail = '';

        while (strlen($url) > 8) {
            $last = substr($url, -1);
            $rest = substr($url, 0, -1);

            $strip = match (true) {
                $last === ';' => preg_match('~&(?:[a-z]+|\#\d+)$~i', $rest) !== 1,
                in_array($last, ['.', ',', ':', '!', '?'], true) => true,
                $last === ')' => substr_count($url, ')') > substr_count($url, '('),
                $last === ']' => substr_count($url, ']') > substr_count($url, '['),
                default => false,
            };

            if (!$strip) {
                break;
            }

            $tail = $last.$tail;
            $url = $rest;
        }

        return [$url, $tail];
    }
}
