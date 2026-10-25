<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Support;

use Asignua\FilamentChat\Models\Conversation;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Who is online. Both delivery modes use the same heartbeat: whatever the chat already
 * renders on a timer (the dock's badge poll, the open window's poll) stamps
 * `last_seen_at` — at most once per `presence.heartbeat_seconds` per person, the
 * throttle lives in the cache so a poll costs no query. Online = seen within
 * `presence.online_seconds`.
 */
final class Presence
{
    /** Seconds of slack on top of the heartbeat interval. */
    public const int MARGIN = 15;

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

        // The user model is part of the key: two panels with different user models may share a key value.
        $key = self::CACHE_PREFIX.$user::class.':'.$user->getKey();

        // Presence is decoration: a broken cache or table must never take the panel down with it.
        try {
            if (!Cache::add($key, 1, ChatConfig::presenceHeartbeat())) {
                return false;
            }

            self::table()->upsert(
                [['user_id' => $user->getKey(), 'last_seen_at' => Carbon::now()]],
                ['user_id'],
                ['last_seen_at'],
            );
        } catch (Throwable $e) {
            Log::warning('filament-chat: presence heartbeat failed: '.$e->getMessage());

            return false;
        }

        return true;
    }

    /**
     * Presence of the given people as `$viewer` may see it, keyed by user key. Everybody the
     * `hidePresenceUsing()` rule hides is left out; so is anyone never seen.
     *
     * @param iterable<Model>   $users
     * @param Conversation|null $context the conversation the people are shown in (handed to the privacy rule)
     *
     * @return array<int|string, PresenceStatus>
     */
    public static function statuses(iterable $users, ?Model $viewer, ?Conversation $context = null): array
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

            if ($hide === null || !$hide($user, $viewer, $context)) {
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

    /**
     * A fingerprint of what the dots and texts say, to skip re-rendering when nothing changed.
     *
     * @param array<int|string, PresenceStatus> $statuses
     */
    public static function signature(array $statuses): string
    {
        return md5((string) json_encode(array_map(fn (PresenceStatus $status): ?string => $status->label(), $statuses)));
    }

    /**
     * Why people would flicker offline between two heartbeats, or null. The heartbeat is the badge
     * poll, so it lands every ceil(heartbeat / badge) * badge seconds; online_seconds needs a margin on top.
     */
    public static function misconfiguration(): ?string
    {
        if (!ChatConfig::presence()) {
            return null;
        }

        $badge = ChatConfig::badgePolling();
        $interval = (int) ceil(ChatConfig::presenceHeartbeat() / $badge) * $badge;
        $needed = $interval + self::MARGIN;

        return ChatConfig::presenceOnlineSeconds() < $needed
            ? 'filament-chat: presence.online_seconds ('.ChatConfig::presenceOnlineSeconds().") is below {$needed} (heartbeat lands every {$interval} s + ".self::MARGIN.' s margin): people will flicker offline. Raise online_seconds or lower heartbeat_seconds / polling.badge.'
            : null;
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
