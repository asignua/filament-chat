<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Support;

use Asignua\FilamentChat\Models\Conversation;
use Asignua\FilamentChat\Models\Message;
use Asignua\FilamentChat\Models\MessageReaction;
use Asignua\FilamentChat\Models\Participant;
use Illuminate\Database\Eloquent\Model;

/**
 * Typed access to config/filament-chat.php.
 */
final class ChatConfig
{
    /** Broadcasters that deliver to the browser (the rest — log, null — do not). */
    private const array SOCKET_BROADCASTERS = ['reverb', 'pusher', 'ably'];

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

    /**
     * @return class-string<Model>
     */
    public static function userModel(): string
    {
        /** @var class-string<Model> */
        return config('filament-chat.user_model') ?? config('auth.providers.users.model');
    }

    public static function table(string $key): string
    {
        return (string) config('filament-chat.tables.'.$key);
    }

    public static function realtime(): bool
    {
        $configured = config('filament-chat.realtime');

        if (is_bool($configured)) {
            return $configured;
        }

        return in_array(config('broadcasting.default'), self::SOCKET_BROADCASTERS, true);
    }

    public static function polling(): int
    {
        return max(1, (int) config('filament-chat.polling', 15));
    }

    public static function badgePolling(): int
    {
        return max(1, (int) config('filament-chat.badge_polling', 60));
    }

    public static function pageSize(): int
    {
        return max(1, (int) config('filament-chat.page_size', 30));
    }

    public static function maxLength(): int
    {
        return max(1, (int) config('filament-chat.max_length', 5000));
    }

    public static function editingEnabled(): bool
    {
        return (bool) config('filament-chat.editing.enabled', true);
    }

    /**
     * Minutes after sending a message can still be edited; null — any time.
     */
    public static function editingWindow(): ?int
    {
        $window = config('filament-chat.editing.window');

        return is_numeric($window) ? max(0, (int) $window) : null;
    }

    public static function databaseNotifications(): bool
    {
        return (bool) config('filament-chat.database_notifications', true);
    }
}
