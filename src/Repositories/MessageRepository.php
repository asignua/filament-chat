<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Repositories;

use Asignua\FilamentChat\Data\MessageData;
use Asignua\FilamentChat\Models\Conversation;
use Asignua\FilamentChat\Models\Message;
use Asignua\FilamentChat\Support\ChatConfig;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Messages: create, edit the text (the author, ChatService::edit) and read — no deletes.
 */
class MessageRepository
{
    /**
     * @param list<int> $mentions
     */
    public function create(Conversation $conversation, Model $author, MessageData $data, array $mentions = [], ?int $replyToId = null): Message
    {
        $body = $this->validBody($data->body, $data->referenceType !== null);

        $model = ChatConfig::messageModel();
        $message = new $model;
        $message->conversation_id = $conversation->id;
        $message->user_id = (int) $author->getKey();
        $message->body = $body;
        $message->reference_type = $data->referenceType;
        $message->reference_id = $data->referenceId;
        $message->mentions = $mentions !== [] ? $mentions : null;
        $message->reply_to_id = $replyToId;
        $message->save();

        return $message;
    }

    /**
     * New text of a message; the reference stays.
     *
     * @param list<int> $mentions
     */
    public function update(Message $message, string $body, array $mentions = []): Message
    {
        $body = $this->validBody($body, $message->reference_type !== null);

        if ($body === $message->body) {
            return $message;
        }

        $message->body = $body;
        $message->mentions = $mentions !== [] ? $mentions : null;
        $message->edited_at = now();
        $message->save();

        return $message;
    }

    /**
     * The author's latest message in the conversation — "edit last" (↑ in an empty composer).
     */
    public function lastBy(Conversation $conversation, Model $author, ?Carbon $until = null): ?Message
    {
        return $conversation->messages()
            ->where('user_id', $author->getKey())
            ->when($until !== null, fn (Builder $query): Builder => $query->where('created_at', '<=', $until))
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The latest `$limit` messages in chronological order. `$until` is the
     * moment someone left a group: later messages are no longer theirs.
     *
     * @return Collection<int, Message>
     */
    public function latest(Conversation $conversation, int $limit, ?Carbon $until = null): Collection
    {
        return $conversation->messages()
            ->when($until !== null, fn (Builder $query): Builder => $query->where('created_at', '<=', $until))
            ->with(['author', 'replyTo.author'])
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();
    }

    public function hasOlderThan(Conversation $conversation, int $messageId): bool
    {
        return $conversation->messages()->where('id', '<', $messageId)->exists();
    }

    /**
     * A message may be just a record reference — then the text can be empty.
     */
    private function validBody(string $body, bool $hasReference): string
    {
        $body = trim($body);

        if ($body === '' && !$hasReference) {
            throw new InvalidArgumentException(__('filament-chat::chat.error_empty'));
        }

        if (mb_strlen($body) > ChatConfig::maxLength()) {
            throw new InvalidArgumentException(__('filament-chat::chat.error_too_long', ['max' => ChatConfig::maxLength()]));
        }

        return $body;
    }

    public function findInConversation(Conversation $conversation, string $ulid): ?Message
    {
        return $conversation->messages()->where('ulid', $ulid)->with('author')->first();
    }

    /**
     * The first message after the reader's read pointer written by someone else
     * (a deleted author counts as someone else).
     */
    public function firstUnread(Conversation $conversation, Model $reader, ?int $after, ?Carbon $until = null): ?Message
    {
        return $conversation->messages()
            ->where(fn (Builder $query): Builder => $query->whereNull('user_id')->orWhere('user_id', '!=', $reader->getKey()))
            ->when($after !== null, fn (Builder $query): Builder => $query->where('id', '>', $after))
            ->when($until !== null, fn (Builder $query): Builder => $query->where('created_at', '<=', $until))
            ->orderBy('id')
            ->first();
    }

    /**
     * How many messages, from this one to the newest (within `$until`) — how much
     * the feed must load for the message to be in it.
     */
    public function countFrom(Conversation $conversation, int $messageId, ?Carbon $until = null): int
    {
        return $conversation->messages()
            ->where('id', '>=', $messageId)
            ->when($until !== null, fn (Builder $query): Builder => $query->where('created_at', '<=', $until))
            ->count();
    }
}
