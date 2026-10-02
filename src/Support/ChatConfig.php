<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Support;

use Asignua\FilamentChat\Models\Conversation;
use Asignua\FilamentChat\Models\Message;
use Asignua\FilamentChat\Models\MessageReaction;
use Asignua\FilamentChat\Models\Participant;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;

/**
 * Typed access to config/filament-chat.php. The plugin's fluent setters write
 * into the same keys, so this is the one place every setting is read from.
 */
final class ChatConfig
{
    /** Broadcasters that deliver to the browser (the rest — log, null — do not). */
    public const array SOCKET_BROADCASTERS = ['reverb', 'pusher', 'ably'];

    public const string ECHO_PLUGIN = 'plugin';

    public const string ECHO_FILAMENT = 'filament';

    public const string ECHO_HOST = 'host';

    /**
     * @return class-string<Conversation>
     */
    public static function conversationModel(): string
    {
        /** @var class-string<Conversation> */
        return config('filament-chat.models.conversation', Conversation::class);
    }

    /**
     * @return class-string<Participant>
     */
    public static function participantModel(): string
    {
        /** @var class-string<Participant> */
        return config('filament-chat.models.participant', Participant::class);
    }

    /**
     * @return class-string<Message>
     */
    public static function messageModel(): string
    {
        /** @var class-string<Message> */
        return config('filament-chat.models.message', Message::class);
    }

    /**
     * @return class-string<MessageReaction>
     */
    public static function reactionModel(): string
    {
        /** @var class-string<MessageReaction> */
        return config('filament-chat.models.reaction', MessageReaction::class);
    }

    public static function table(string $key): string
    {
        return (string) config('filament-chat.tables.'.$key);
    }

    // --- Users -----------------------------------------------------------------

    /**
     * @return class-string<Model>
     */
    public static function userModel(): string
    {
        /** @var class-string<Model> */
        return config('filament-chat.users.model') ?? config('auth.providers.users.model');
    }

    public static function userNameAttribute(): ?string
    {
        return self::nonEmptyString(config('filament-chat.users.name_attribute'));
    }

    /**
     * @return list<string>
     */
    public static function userSearchColumns(): array
    {
        return self::stringList(config('filament-chat.users.search_columns', ['name']));
    }

    public static function userBroadcastKey(): ?string
    {
        return self::nonEmptyString(config('filament-chat.users.broadcast_key'));
    }

    // --- Features --------------------------------------------------------------

    public static function groups(): bool
    {
        return (bool) config('filament-chat.features.groups', true);
    }

    public static function reactions(): bool
    {
        return (bool) config('filament-chat.features.reactions', true);
    }

    public static function mentions(): bool
    {
        return (bool) config('filament-chat.features.mentions', true);
    }

    public static function avatars(): bool
    {
        return (bool) config('filament-chat.features.avatars', true);
    }

    /**
     * Replies are on in the config and the reply migration has been run.
     */
    public static function replies(): bool
    {
        return (bool) config('filament-chat.features.replies', true) && self::repliesAvailable();
    }

    /**
     * Whether the messages table has the `reply_to_id` column. A host that
     * updated from 1.1 without running the new migration keeps a working
     * chat — only replies stay off. Checked once per application instance.
     */
    public static function repliesAvailable(): bool
    {
        $manager = app(ChatManager::class);

        if ($manager->replyColumn === null) {
            $model = self::messageModel();
            $manager->replyColumn = (new $model)->getConnection()->getSchemaBuilder()
                ->hasColumn(self::table('messages'), 'reply_to_id');
        }

        return $manager->replyColumn;
    }

    public static function readReceipts(): bool
    {
        return (bool) config('filament-chat.features.read_receipts', true);
    }

    public static function editingEnabled(): bool
    {
        return (bool) config('filament-chat.features.editing.enabled', true);
    }

    /**
     * Minutes after sending a message can still be edited; null — any time.
     */
    public static function editingWindow(): ?int
    {
        $window = config('filament-chat.features.editing.window');

        return is_numeric($window) ? max(0, (int) $window) : null;
    }

    // --- Messages --------------------------------------------------------------

    public static function pageSize(): int
    {
        return max(1, (int) config('filament-chat.messages.page_size', 30));
    }

    public static function maxLength(): int
    {
        return max(1, (int) config('filament-chat.messages.max_length', 5000));
    }

    // --- References ------------------------------------------------------------

    public static function referencesEnabled(): bool
    {
        return (bool) config('filament-chat.references.enabled', true);
    }

    /**
     * How references are authorised: 'policy', 'resource' or null (no check).
     */
    public static function referenceAuthorization(): ?string
    {
        $mode = config('filament-chat.references.authorize', 'policy');

        return in_array($mode, ['policy', 'resource'], true) ? $mode : null;
    }

    public static function allResources(): bool
    {
        return (bool) config('filament-chat.references.all_resources', false);
    }

    /**
     * @return list<class-string<resource>>
     */
    public static function exceptResources(): array
    {
        /** @var list<class-string<resource>> */
        return self::stringList(config('filament-chat.references.except', []));
    }

    // --- Real-time -------------------------------------------------------------

    public static function realtime(): bool
    {
        $enabled = config('filament-chat.realtime.enabled');

        if (is_bool($enabled)) {
            return $enabled;
        }

        // env() gives strings: "true" / "false" / "".
        if (is_string($enabled) && $enabled !== '') {
            return filter_var($enabled, FILTER_VALIDATE_BOOLEAN);
        }

        return in_array(self::broadcaster(), self::SOCKET_BROADCASTERS, true);
    }

    /**
     * The broadcasting connection events go through; null — the default one.
     */
    public static function broadcastConnection(): ?string
    {
        return self::nonEmptyString(config('filament-chat.realtime.connection'));
    }

    /**
     * The driver behind that connection (reverb, pusher, ably, log, null…).
     */
    public static function broadcaster(): ?string
    {
        $connection = self::broadcastConnection() ?? config('broadcasting.default');

        if (!is_string($connection)) {
            return null;
        }

        return self::nonEmptyString(config('broadcasting.connections.'.$connection.'.driver'))
            ?? $connection;
    }

    public static function echoSource(): string
    {
        $source = config('filament-chat.realtime.echo', self::ECHO_PLUGIN);

        return in_array($source, [self::ECHO_PLUGIN, self::ECHO_FILAMENT, self::ECHO_HOST], true)
            ? $source
            : self::ECHO_PLUGIN;
    }

    public static function registerAuthRoute(): bool
    {
        return (bool) config('filament-chat.realtime.register_auth_route', true);
    }

    /**
     * Echo options for the browser when the plugin brings Echo itself: the
     * connection's key, and where the browser connects (`realtime.client`,
     * then the connection's own host settings; empty — the page's own host).
     *
     * @return array{broadcaster: string, key: string, cluster: string|null, host: string|null, port: int|null, scheme: string|null}
     */
    public static function echoOptions(): array
    {
        $connection = self::broadcastConnection() ?? (string) config('broadcasting.default');
        $settings = config('broadcasting.connections.'.$connection);
        $settings = is_array($settings) ? $settings : [];
        $options = is_array($settings['options'] ?? null) ? $settings['options'] : [];
        $client = config('filament-chat.realtime.client');
        $client = is_array($client) ? $client : [];
        $driver = self::broadcaster() ?? 'reverb';

        $port = $client['port'] ?? null;

        return [
            'broadcaster' => $driver === 'reverb' ? 'reverb' : 'pusher',
            'key' => (string) ($settings['key'] ?? ''),
            'cluster' => self::nonEmptyString($options['cluster'] ?? null),
            'host' => self::nonEmptyString($client['host'] ?? null),
            'port' => is_numeric($port) ? (int) $port : null,
            'scheme' => self::nonEmptyString($client['scheme'] ?? null),
        ];
    }

    // --- Polling & notifications ------------------------------------------------

    public static function polling(): int
    {
        return max(1, (int) config('filament-chat.polling.conversation', 15));
    }

    public static function badgePolling(): int
    {
        return max(1, (int) config('filament-chat.polling.badge', 60));
    }

    public static function databaseNotifications(): bool
    {
        return (bool) config('filament-chat.notifications.database', true);
    }

    public static function toasts(): bool
    {
        return (bool) config('filament-chat.notifications.toasts', true);
    }

    // --- Interface -------------------------------------------------------------

    public static function dock(): bool
    {
        return (bool) config('filament-chat.ui.dock', true);
    }

    public static function pinnable(): bool
    {
        return (bool) config('filament-chat.ui.pinnable', true);
    }

    public static function tabBadge(): bool
    {
        return (bool) config('filament-chat.ui.tab_badge', true);
    }

    public static function color(): string
    {
        return self::nonEmptyString(config('filament-chat.ui.color')) ?? 'primary';
    }

    public static function slug(): string
    {
        return self::nonEmptyString(config('filament-chat.ui.slug')) ?? 'chat';
    }

    public static function navigationGroup(): ?string
    {
        return self::nonEmptyString(config('filament-chat.ui.navigation.group'));
    }

    public static function navigationSort(): ?int
    {
        $sort = config('filament-chat.ui.navigation.sort', 90);

        return is_numeric($sort) ? (int) $sort : null;
    }

    public static function navigationIcon(): ?string
    {
        return self::nonEmptyString(config('filament-chat.ui.navigation.icon'));
    }

    private static function nonEmptyString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }
}
