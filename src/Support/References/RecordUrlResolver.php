<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Support\References;

use Asignua\FilamentChat\Support\ChatManager;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Support\Facades\Gate;

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
        $manager = app(ChatManager::class);

        if ($manager->references->isEmpty()) {
            return null;
        }

        $url = trim(strtok($url, "\r\n") ?: '');
        $parts = parse_url($url);

        if ($parts === false || !isset($parts['path'])) {
            return null;
        }

        // Only this very panel: the same path on another host is not our record.
        if (isset($parts['host']) && $parts['host'] !== request()->getHost()) {
            return null;
        }

        $panel = self::panel($manager);

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
            $types = $manager->references->forResource($resource);

            if ($types === []) {
                continue;
            }

            $pattern = '~^'.preg_quote($resource::getSlug($panel), '~').'/([^/]+)(?:/[a-z-]+)?/?$~';

            if (preg_match($pattern, $rest, $match) !== 1) {
                continue;
            }

            $record = $resource::resolveRecordRouteBinding(urldecode($match[1]));
            $type = $record !== null ? $manager->references->forRecord($record) : null;

            if ($record === null || $type === null || !Gate::allows('view', $record)) {
                return null;
            }

            return ['type' => $type->getKey(), 'id' => (int) $record->getKey()];
        }

        return null;
    }

    private static function panel(ChatManager $manager): ?Panel
    {
        if ($manager->panelId !== null) {
            return Filament::getPanel($manager->panelId, isStrict: false);
        }

        return Filament::getCurrentOrDefaultPanel();
    }
}
