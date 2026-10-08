<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Events;

use Asignua\FilamentChat\Support\ChatConfig;
use Illuminate\Broadcasting\InteractsWithBroadcasting;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * "Something changed in a conversation" — a new message, members, a read
 * pointer or a reaction; `message` is set only for a new message. Goes to
 * every member's private channel right away (no queue: without a worker a
 * queued event would silently never arrive).
 *
 * Identifiers only: the components re-read the data from the server, so
 * policies — not the socket payload — decide who sees what.
 *
 * Inside a caller's transaction it waits for the commit (ShouldDispatchAfterCommit): the
 * recipients re-read the data on the event and would miss a row that is not committed yet.
 */
class ChatUpdated implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use InteractsWithBroadcasting;

    /** Event name for Echo (the listener is `.filament-chat.updated`). */
    public const string NAME = 'filament-chat.updated';

    /** Private channel prefix; the key is ChatUsers::broadcastKey(). */
    public const string CHANNEL = 'filament-chat.user.';

    /**
     * @param list<string> $recipients broadcast keys of the recipients
     * @param string|null  $reader     broadcast key of the person whose read pointer moved (a read event
     *                                 changes nobody else's unread counter)
     */
    public function __construct(
        public readonly array $recipients,
        public readonly string $conversation,
        public readonly ?string $message = null,
        public readonly ?string $author = null,
        public readonly ?string $reader = null,
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
     * @return array{conversation: string, message: string|null, author: string|null, reader?: string}
     */
    public function broadcastWith(): array
    {
        return [
            'conversation' => $this->conversation,
            'message' => $this->message,
            'author' => $this->author,
            // Only a read event carries it; a message event keeps the old payload.
            ...($this->reader !== null ? ['reader' => $this->reader] : []),
        ];
    }

    public static function channelFor(string $key): string
    {
        return self::CHANNEL.$key;
    }
}
