<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Events;

use Asignua\FilamentChat\Support\ChatConfig;
use Illuminate\Broadcasting\InteractsWithBroadcasting;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * "Something changed in a conversation" — a new message, members, a read
 * pointer or a reaction; `message` is set only for a new message. Goes to
 * every member's private channel right away (no queue: without a worker a
 * queued event would silently never arrive).
 *
 * Identifiers only: the components re-read the data from the server, so
 * policies — not the socket payload — decide who sees what.
 */
class ChatUpdated implements ShouldBroadcastNow
{
    use InteractsWithBroadcasting;

    /** Event name for Echo (the listener is `.filament-chat.updated`). */
    public const string NAME = 'filament-chat.updated';

    /** Private channel prefix; the key is ChatUsers::broadcastKey(). */
    public const string CHANNEL = 'filament-chat.user.';

    /**
     * @param list<string> $recipients broadcast keys of the recipients
     */
    public function __construct(
        public readonly array $recipients,
        public readonly string $conversation,
        public readonly ?string $message = null,
        public readonly ?string $author = null,
    ) {
        // `realtime.connection` — a connection other than the app's default one.
        $this->broadcastVia(ChatConfig::broadcastConnection());
    }

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return array_map(
            fn (string $key): PrivateChannel => new PrivateChannel(self::channelFor($key)),
            $this->recipients,
        );
    }

    public function broadcastAs(): string
    {
        return self::NAME;
    }

    /**
     * @return array{conversation: string, message: string|null, author: string|null}
     */
    public function broadcastWith(): array
    {
        return [
            'conversation' => $this->conversation,
            'message' => $this->message,
            'author' => $this->author,
        ];
    }

    public static function channelFor(string $key): string
    {
        return self::CHANNEL.$key;
    }
}
