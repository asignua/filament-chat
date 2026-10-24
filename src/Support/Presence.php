<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Support;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Who is online. Both delivery modes use the same heartbeat: whatever the chat already
 * renders on a timer (the dock's badge poll, the open window's poll) stamps
 * `last_seen_at` — at most once per `presence.heartbeat_seconds` per person, the
 * throttle lives in the cache so a poll costs no query. Online = seen within
 * `presence.online_seconds`.
 */
final class Presence
{
    private const string CACHE_PREFIX = 'filament-chat:presence:';

    /**
     * On in the config and the presences migration has been run.
     */
    public static function enabled(): bool
    {
        return ChatConfig::presence() && ChatConfig::presenceAvailable();
    }

    /**
     * The person is active now. Returns whether the row was written (false: throttled or off).
     */
    public static function touch(?Model $user): bool
    {
        if ($user === null || !self::enabled()) {
            return false;
        }

        $key = self::CACHE_PREFIX.$user->getKey();

        if (!Cache::add($key, 1, ChatConfig::presenceHeartbeat())) {
            return false;
        }

        self::table()->upsert(
            [['user_id' => $user->getKey(), 'last_seen_at' => Carbon::now()]],
            ['user_id'],
            ['last_seen_at'],
        );

        return true;
    }

    /**
     * Presence of the given people as `$viewer` may see it, keyed by user key. Everybody the
     * `hidePresenceUsing()` rule hides is left out; so is anyone never seen.
     *
     * @param iterable<Model> $users
     *
     * @return array<int|string, PresenceStatus>
     */
    public static function statuses(iterable $users, ?Model $viewer): array
    {
        if (!self::enabled()) {
            return [];
        }

        $hide = app(ChatManager::class)->hidePresenceUsing;
        $visible = [];

        foreach ($users as $user) {
            if ($viewer !== null && $user->is($viewer)) {
                continue;
            }

            if ($hide === null || !$hide($user, $viewer)) {
                $visible[$user->getKey()] = $user;
            }
        }

        if ($visible === []) {
            return [];
        }

        $seen = self::table()->whereIn('user_id', array_keys($visible))->pluck('last_seen_at', 'user_id');
        $statuses = [];

        foreach ($seen as $id => $moment) {
            if ($moment === null) {
                continue;
            }

            $lastSeen = Carbon::parse($moment);
            $statuses[$id] = new PresenceStatus(self::isOnline($lastSeen), $lastSeen);
        }

        return $statuses;
    }

    public static function isOnline(?CarbonInterface $lastSeen): bool
    {
        return $lastSeen !== null && $lastSeen->greaterThanOrEqualTo(Carbon::now()->subSeconds(ChatConfig::presenceOnlineSeconds()));
    }

    private static function table(): Builder
    {
        $model = ChatConfig::participantModel();

        return (new $model)->getConnection()->table(ChatConfig::presenceTable());
    }
}
