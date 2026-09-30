<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Support;

/**
 * "@Name" mentions. Recognised on the server by the members' names, so a
 * mention typed by hand works as well as one picked from the list. The
 * longest name wins: "@Olga Green" is Olga Green, not a second "Olga".
 */
final class Mentions
{
    /**
     * Keys of the people mentioned in the text.
     *
     * @param array<int, string> $people key → name
     *
     * @return list<int>
     */
    public static function find(string $body, array $people): array
    {
        $found = [];

        foreach (self::byLength($people) as $id => $name) {
            $pattern = self::pattern([$name]);
            $count = 0;
            $body = (string) preg_replace($pattern, ' ', $body, -1, $count);

            if ($count > 0) {
                $found[] = $id;
            }
        }

        sort($found);

        return $found;
    }

    /**
     * Wraps the mentions in already escaped HTML; one's own name gets an extra class.
     *
     * @param array<int, string> $people key → name (the message's mentions)
     */
    public static function highlight(string $html, array $people, ?int $me = null): string
    {
        if ($people === []) {
            return $html;
        }

        $escaped = [];

        foreach (self::byLength($people) as $id => $name) {
            $escaped[mb_strtolower(e($name))] = $id;
        }

        return (string) preg_replace_callback(
            self::pattern(array_keys($escaped)),
            function (array $match) use ($escaped, $me): string {
                $id = $escaped[mb_strtolower(mb_substr($match[0], 1))] ?? null;
                $class = $id !== null && $id === $me ? 'fchat-mention fchat-mention-me' : 'fchat-mention';

                return '<span class="'.$class.'">'.$match[0].'</span>';
            },
            $html,
        );
    }

    /**
     * @param array<int, string> $people
     *
     * @return array<int, string>
     */
    private static function byLength(array $people): array
    {
        $people = array_filter($people, fn (string $name): bool => trim($name) !== '');
        uasort($people, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return $people;
    }

    /**
     * "@" + one of the names, case-insensitive; neither glued to a word before
     * (an e-mail address) nor followed by a letter or digit.
     *
     * @param list<string> $names
     */
    private static function pattern(array $names): string
    {
        $alternatives = implode('|', array_map(fn (string $name): string => preg_quote($name, '~'), $names));

        return '~(?<![\p{L}\p{N}_])@(?:'.$alternatives.')(?![\p{L}\p{N}_])~iu';
    }
}
