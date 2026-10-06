<?php

declare(strict_types=1);

namespace Workbench\App\Livewire;

use Asignua\FilamentChat\Livewire\ChatWindow;
use Asignua\FilamentChat\Models\Message;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * A ChatWindow the way an extension package subclasses it; the behaviour is
 * switched from the tests through the statics (Livewire builds a new instance per request).
 */
class ExtendedChatWindow extends ChatWindow
{
    public static bool $withoutBody = false;

    public static bool $failInTransaction = false;

    public static bool $withTrashed = false;

    /** @var list<string> */
    public static array $gone = [];

    /** @var list<string> */
    public static array $log = [];

    /** @var list<string> */
    public static array $changes = [];

    public static function resetState(): void
    {
        self::$withoutBody = self::$failInTransaction = self::$withTrashed = false;
        self::$gone = self::$log = self::$changes = [];
    }

    protected function canSendWithoutBody(): bool
    {
        return self::$withoutBody;
    }

    protected function beforeMessageCommit(Message $message): void
    {
        self::$log[] = 'before:'.$message->ulid;

        if (self::$failInTransaction) {
            throw new InvalidArgumentException('Extension refused');
        }
    }

    protected function afterMessageSent(Message $message): void
    {
        self::$log[] = 'after:'.$message->ulid;
    }

    protected function conversationChanged(?string $from, ?string $to): void
    {
        self::$changes[] = ($from ?? '-').'>'.($to ?? '-');
    }

    protected function modifyMessagesQuery(Builder $query): Builder
    {
        self::$log[] = 'query';

        return self::$withTrashed ? $query->withTrashed() : $query;
    }

    protected function isMessageTombstone(Message $message): bool
    {
        return in_array($message->ulid, self::$gone, true);
    }
}
