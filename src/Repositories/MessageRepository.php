<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Repositories;

use Asignua\FilamentChat\Data\MessageData;
use Asignua\FilamentChat\Models\Conversation;
use Asignua\FilamentChat\Models\Message;
use Asignua\FilamentChat\Support\ChatConfig;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Messages: create, edit the text (the author, ChatService::edit) and read — no deletes.
 *
 * The reads take an optional `$scope` — the window's modifyMessagesQuery(): an
 * extension's way to eager-load, or to include what a global scope hides (soft
 * deletes), the same for every query of the feed so counts and pages agree.
 */
class MessageRepository
{
    /**
     * @param list<int> $mentions
     */
    public function create(Conversation $conversation, Model $author, MessageData $data, array $mentions = [], ?int $replyToId = null): Message
    {
        $body = $this->validBody($data->body, $data->referenceType !== null || $data->allowEmpty);

        $model = ChatConfig::messageModel();
        $message = new $model;
        $message->conversation_id = $conversation->id;
        $message->user_id = (int) $author->getKey();
        $message->body = $body;
        $message->reference_type = $data->referenceType;
        $message->reference_id = $data->referenceId;
        $message->mentions = $mentions !== [] ? $mentions : null;

        // Assigned only when set: before the reply migration the column does not exist.
        if ($replyToId !== null) {
            $message->reply_to_id = $replyToId;
        }
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
    public function lastBy(Conversation $conversation, Model $author, ?Carbon $until = null, ?Closure $scope = null): ?Message
    {
        return $this->query($conversation, $scope)
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
    public function latest(Conversation $conversation, int $limit, ?Carbon $until = null, ?Closure $scope = null): Collection
    {
        return $this->query($conversation, $scope)
            ->when($until !== null, fn (Builder $query): Builder => $query->where('created_at', '<=', $until))
            ->with(ChatConfig::repliesAvailable() ? ['author', 'replyTo.author'] : ['author'])
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();
    }

    /**
     * id of the newest message (up to `$until`, see latest()).
     */
    public function latestId(Conversation $conversation, ?Carbon $until = null, ?Closure $scope = null): ?int
    {
        $id = $this->query($conversation, $scope)
            ->when($until !== null, fn (Builder $query): Builder => $query->where('created_at', '<=', $until))
            ->max('id');

        return $id !== null ? (int) $id : null;
    }

    public function hasOlderThan(Conversation $conversation, int $messageId, ?Closure $scope = null): bool
    {
        return $this->query($conversation, $scope)->where('id', '<', $messageId)->exists();
    }

    /**
     * A message by ulid, whichever conversation it is in — for a jump from a
     * search result. The caller checks that the person may see its conversation.
     */
    public function findByUlid(string $ulid, ?Closure $scope = null): ?Message
    {
        $model = ChatConfig::messageModel();
        $query = $model::query();

        if ($scope !== null) {
            $query = $scope($query);
        }

        return $query->where('ulid', $ulid)->with(['author', 'conversation'])->first();
    }

    /**
     * The conversation's messages, shaped by the extension's scope when there is one.
     *
     * @return Builder<Message>
     */
    private function query(Conversation $conversation, ?Closure $scope): Builder
    {
        $query = $conversation->messages()->getQuery();

        return $scope !== null ? $scope($query) : $query;
    }

    /**
     * A message may be just a record reference (or declare itself content-only, `allowEmpty`) — then the text can be empty.
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

    public function findInConversation(Conversation $conversation, string $ulid, ?Closure $scope = null): ?Message
    {
        return $this->query($conversation, $scope)->where('ulid', $ulid)->with('author')->first();
    }

    /**
     * The first message after the reader's read pointer written by someone else
     * (a deleted author counts as someone else).
     */
    public function firstUnread(Conversation $conversation, Model $reader, ?int $after, ?Carbon $until = null, ?Closure $scope = null): ?Message
    {
        return $this->query($conversation, $scope)
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
    public function countFrom(Conversation $conversation, int $messageId, ?Carbon $until = null, ?Closure $scope = null): int
    {
        return $this->query($conversation, $scope)
            ->where('id', '>=', $messageId)
            ->when($until !== null, fn (Builder $query): Builder => $query->where('created_at', '<=', $until))
            ->count();
    }

    /**
     * The latest message up to a moment — what someone who left a group last saw.
     */
    public function lastUntil(Conversation $conversation, Carbon $until, ?Closure $scope = null): ?Message
    {
        return $this->query($conversation, $scope)
            ->where('created_at', '<=', $until)
            ->with('author')
            ->orderByDesc('id')
            ->first();
    }
}
