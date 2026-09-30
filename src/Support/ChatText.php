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
        $linked = (string) preg_replace_callback(
            '~\bhttps?://[^\s<]+~u',
            fn (array $match): string => '<a href="'.$match[0].'" target="_blank" rel="noopener noreferrer" class="underline break-all">'.$match[0].'</a>',
            Mentions::highlight(e($body), $mentions, $me),
        );

        return new HtmlString(nl2br($linked, false));
    }
}
