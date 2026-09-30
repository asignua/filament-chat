<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Support;

use Asignua\FilamentChat\Models\Participant;

/**
 * Read status of one's OWN message: ✓ sent, ✓✓ read. Direct — the other
 * person read it; group — ALL active members except the author did.
 *
 * No extra queries: the rule sits on the `last_read_message_id` pointers the
 * chat window has already loaded with the participants.
 */
final readonly class ReadStatus
{
    /**
     * @param array<int, int>    $pointers user key → last read message (0 — nothing yet)
     * @param array<int, string> $names    user key → name for the hint
     */
    private function __construct(
        private array $pointers,
        private array $names,
    ) {}

    /**
     * @param iterable<Participant> $participants with `user` loaded
     */
    public static function for(iterable $participants, int $authorId): self
    {
        $pointers = [];
        $names = [];

        foreach ($participants as $participant) {
            if ($participant->user_id === $authorId || !$participant->isActive()) {
                continue;
            }

            $pointers[$participant->user_id] = $participant->last_read_message_id ?? 0;
            $names[$participant->user_id] = $participant->user !== null
                ? ChatUsers::name($participant->user)
                : __('filament-chat::chat.unknown_user');
        }

        return new self($pointers, $names);
    }

    /**
     * Nobody but the author — not "read": ✓✓ from an empty group would mislead.
     */
    public function isRead(int $messageId): bool
    {
        return $this->pointers !== [] && $this->unreadBy($messageId) === [];
    }

    /**
     * @return list<string>
     */
    public function unreadBy(int $messageId): array
    {
        $names = [];

        foreach ($this->pointers as $userId => $pointer) {
            if ($pointer < $messageId) {
                $names[] = $this->names[$userId];
            }
        }

        return $names;
    }

    public function hint(int $messageId, bool $group): string
    {
        if (!$group) {
            return __($this->isRead($messageId) ? 'filament-chat::chat.status_read' : 'filament-chat::chat.status_sent');
        }

        if ($this->isRead($messageId)) {
            return __('filament-chat::chat.status_read_all');
        }

        $unread = $this->unreadBy($messageId);

        return $unread === []
            ? __('filament-chat::chat.status_sent')
            : __('filament-chat::chat.status_unread_by', ['names' => implode(', ', $unread)]);
    }
}
