<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Support\References;

use Asignua\FilamentChat\Support\ChatManager;
use Filament\Resources\Resource;
use Illuminate\Support\Str;

/**
 * A link to a record page dropped into the chat (a table row, a link in a
 * column, a global search result, the address bar) → a reference. The page is
 * `/{panel}/{resource slug}/{record key}[/{page}]`; the resource resolves the
 * record itself (with its own query scope), so a foreign or missing key gives
 * null. Only resources of registered reference types count.
 */
final class RecordUrlResolver
{
    /**
     * @return array{type: string, id: int}|null
     */
    public static function resolve(string $url): ?array
    {
        $match = self::match($url);

        if ($match === null) {
            return null;
        }

        $references = app(ChatManager::class)->references;

        if ($references->forResource($match['resource']) === []) {
            return null;
        }

        $record = $match['resource']::resolveRecordRouteBinding($match['key']);
        $type = $record !== null ? $references->forRecord($record) : null;

        if ($record === null || $type === null || !$type->canView($record)) {
            return null;
        }

        return ['type' => $type->getKey(), 'id' => (int) $record->getKey()];
    }

    /**
     * The label of a record page's resource that is NOT registered for
     * references — "Contents cannot be attached" reads better than "not a link".
     */
    public static function unsupportedResourceLabel(string $url): ?string
    {
        $match = self::match($url);

        if ($match === null || app(ChatManager::class)->references->forResource($match['resource']) !== []) {
            return null;
        }

        return Str::ucfirst($match['resource']::getPluralModelLabel());
    }

    /**
     * @return array{resource: class-string<resource>, key: string}|null
     */
    private static function match(string $url): ?array
    {
        $url = trim(strtok($url, "\r\n") ?: '');
        $parts = parse_url($url);

        if ($parts === false || !isset($parts['path'])) {
            return null;
        }

        // Only this very panel: the same path on another host is not our record.
        if (isset($parts['host']) && $parts['host'] !== request()->getHost()) {
            return null;
        }

        $panel = app(ChatManager::class)->panel();

        if ($panel === null) {
            return null;
        }

        $prefix = '/'.trim($panel->getPath(), '/').'/';
        $prefix = $prefix === '//' ? '/' : $prefix;

        if (!str_starts_with($parts['path'], $prefix)) {
            return null;
        }

        $rest = substr($parts['path'], strlen($prefix));

        foreach ($panel->getResources() as $resource) {
            if (!is_subclass_of($resource, Resource::class)) {
                continue;
            }

            $pattern = '~^'.preg_quote($resource::getSlug($panel), '~').'/([^/]+)(?:/[a-z-]+)?/?$~';

            if (preg_match($pattern, $rest, $found) === 1 && $found[1] !== 'create') {
                return ['resource' => $resource, 'key' => urldecode($found[1])];
            }
        }

        return null;
    }
}
