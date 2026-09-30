<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Repositories;

use Asignua\FilamentChat\Enums\Reaction;
use Asignua\FilamentChat\Models\Message;
use Asignua\FilamentChat\Models\MessageReaction;
use Asignua\FilamentChat\Support\ChatConfig;
use Asignua\FilamentChat\Support\ChatUsers;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Reactions: one per person — the same one again removes it, another replaces it.
 */
class ReactionRepository
{
    /**
     * Returns the reaction that remains, or null if the person removed theirs.
     */
    public function toggle(Message $message, Model $user, Reaction $reaction): ?Reaction
    {
        return DB::transaction(function () use ($message, $user, $reaction): ?Reaction {
            $model = ChatConfig::reactionModel();

            /** @var MessageReaction|null $existing */
            $existing = $model::query()
                ->where('chat_message_id', $message->id)
                ->where('user_id', $user->getKey())
                ->lockForUpdate()
                ->first();

            if ($existing !== null && $existing->reaction === $reaction) {
                $existing->delete();

                return null;
            }

            $row = $existing ?? new $model;
            $row->chat_message_id = $message->id;
            $row->user_id = (int) $user->getKey();
            $row->reaction = $reaction;
            $row->save();

            return $reaction;
        });
    }

    /**
     * Reactions of a page of messages in one query: message → reaction →
     * who (user key → name), in the order they were set.
     *
     * @param list<int> $messageIds
     *
     * @return array<int, array<string, array<int|string, string>>>
     */
    public function forMessages(array $messageIds): array
    {
        if ($messageIds === []) {
            return [];
        }

        $model = ChatConfig::reactionModel();
        $grouped = [];

        $rows = $model::query()
            ->whereIn('chat_message_id', $messageIds)
            ->with('user')
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            $grouped[$row->chat_message_id][$row->reaction->value][$row->user_id] = $row->user !== null
                ? ChatUsers::name($row->user)
                : __('filament-chat::chat.unknown_user');
        }

        return $grouped;
    }
}
