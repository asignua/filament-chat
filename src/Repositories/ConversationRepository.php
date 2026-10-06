<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Repositories;

use Asignua\FilamentChat\Data\GroupData;
use Asignua\FilamentChat\Enums\ConversationType;
use Asignua\FilamentChat\Events\GroupMembersChanged;
use Asignua\FilamentChat\Models\Conversation;
use Asignua\FilamentChat\Models\Message;
use Asignua\FilamentChat\Models\Participant;
use Asignua\FilamentChat\Support\ChatConfig;
use Asignua\FilamentChat\Support\ChatUsers;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Conversations and their members: creation, group membership, the read
 * pointer and everything the conversation list and unread badges read.
 *
 * Unread = someone else's message with an id above the member's
 * `last_read_message_id`, for a member who has not left.
 */
class ConversationRepository
{
    /**
     * @return Builder<Conversation>
     */
    public function query(): Builder
    {
        $model = ChatConfig::conversationModel();

        return $model::query();
    }

    public function findByUlid(string $ulid): ?Conversation
    {
        return $this->query()->where('ulid', $ulid)->first();
    }

    /**
     * One direct conversation per pair. A parallel creation trips over the
     * unique `direct_key` — then the one that made it first is returned.
     */
    public function findOrCreateDirect(Model $a, Model $b): Conversation
    {
        $aId = (int) $a->getKey();
        $bId = (int) $b->getKey();

        if ($aId === $bId) {
            throw new InvalidArgumentException(__('filament-chat::chat.error_self'));
        }

        $key = min($aId, $bId).':'.max($aId, $bId);
        $existing = $this->query()->where('direct_key', $key)->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($aId, $bId, $key): Conversation {
                $conversation = $this->newConversation();
                $conversation->type = ConversationType::Direct;
                $conversation->direct_key = $key;
                $conversation->created_by_user_id = $aId;
                $conversation->save();

                $this->addParticipant($conversation, $aId);
                $this->addParticipant($conversation, $bId);

                return $conversation;
            });
        } catch (UniqueConstraintViolationException) {
            return $this->query()->where('direct_key', $key)->firstOrFail();
        }
    }

    /**
     * A group needs a title; the creator is always a member, and there must
     * be someone besides them.
     */
    public function createGroup(GroupData $data, Model $creator): Conversation
    {
        $creatorId = (int) $creator->getKey();
        $members = $this->groupMembers($data, $creatorId);

        return DB::transaction(function () use ($data, $creatorId, $members): Conversation {
            $conversation = $this->newConversation();
            $conversation->type = ConversationType::Group;
            $conversation->title = $data->title;
            $conversation->created_by_user_id = $creatorId;
            $conversation->save();

            foreach ($members as $userId) {
                $this->addParticipant($conversation, $userId);
            }

            $this->membersChanged($conversation, [], $this->memberNames($conversation));

            return $conversation;
        });
    }

    /**
     * Title and members. Whoever is no longer on the list has "left" (the
     * history up to now stays visible to them); whoever appears or returns is
     * in again. The creator is never removed — they can only leave themselves.
     */
    public function updateGroup(Conversation $conversation, GroupData $data): Conversation
    {
        if (!$conversation->isGroup()) {
            throw new InvalidArgumentException(__('filament-chat::chat.error_not_group'));
        }

        $members = $this->groupMembers($data, (int) $conversation->created_by_user_id);

        return DB::transaction(function () use ($conversation, $data, $members): Conversation {
            $before = $this->memberNames($conversation);

            $conversation->title = $data->title;
            $conversation->save();

            foreach ($conversation->participants()->get() as $participant) {
                if (!in_array($participant->user_id, $members, true) && $participant->isActive()) {
                    $participant->left_at = now();
                    $participant->save();
                }
            }

            foreach ($members as $userId) {
                $this->addParticipant($conversation, $userId);
            }

            $this->membersChanged($conversation, $before, $this->memberNames($conversation));

            return $conversation;
        });
    }

    /**
     * Leave a group. A direct conversation cannot be left — just not opened.
     */
    public function leave(Conversation $conversation, Model $user): void
    {
        if (!$conversation->isGroup()) {
            throw new InvalidArgumentException(__('filament-chat::chat.error_not_group'));
        }

        $participant = $this->participant($conversation, $user);

        if ($participant === null || !$participant->isActive()) {
            return;
        }

        DB::transaction(function () use ($conversation, $participant): void {
            $before = $this->memberNames($conversation);

            $participant->left_at = now();
            $participant->save();

            $this->membersChanged($conversation, $before, $this->memberNames($conversation));
        });
    }

    public function participant(Conversation $conversation, Model $user): ?Participant
    {
        return $conversation->participants()->where('user_id', $user->getKey())->first();
    }

    /**
     * Who is in the conversation now — the recipients of a message.
     *
     * @return \Illuminate\Support\Collection<int, Model>
     */
    public function activeMembers(Conversation $conversation): \Illuminate\Support\Collection
    {
        $model = ChatConfig::userModel();

        return $model::query()
            ->whereIn((new $model)->getKeyName(), $conversation->participants()->whereNull('left_at')->select('user_id'))
            ->get()
            ->sortBy(fn (Model $user): string => mb_strtolower(ChatUsers::name($user)))
            ->values();
    }

    /**
     * Broadcast keys of the active members — the socket recipients.
     *
     * @return list<string>
     */
    public function activeMemberKeys(Conversation $conversation): array
    {
        return array_values($this->activeMembers($conversation)
            ->map(fn (Model $user): string => ChatUsers::broadcastKey($user))
            ->all());
    }

    /**
     * @return list<int>
     */
    public function activeMemberIds(Conversation $conversation): array
    {
        return array_values(array_map(
            intval(...),
            $conversation->participants()->whereNull('left_at')->pluck('user_id')->all(),
        ));
    }

    /**
     * A person's conversations (including those they left — the history
     * stays), freshest first. Search by group title or any member's name.
     *
     * @return Collection<int, Conversation>
     */
    public function listFor(Model $user, string $search = ''): Collection
    {
        $words = preg_split('/\s+/u', trim($search), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $columns = ChatUsers::searchColumns();

        return $this->query()
            ->whereHas('participants', fn (Builder $query): Builder => $query->where('user_id', $user->getKey()))
            ->when($words !== [], function (Builder $query) use ($words, $user, $columns): void {
                foreach ($words as $word) {
                    $like = '%'.$word.'%';

                    $query->where(fn (Builder $any): Builder => $any
                        ->whereLike('title', $like)
                        ->when($columns !== [], fn (Builder $any): Builder => $any->orWhereHas('participants.user', fn (Builder $users): Builder => $users
                            ->whereKeyNot($user->getKey())
                            ->where(function (Builder $name) use ($columns, $like): void {
                                foreach ($columns as $column) {
                                    $name->orWhereLike($name->getModel()->qualifyColumn($column), $like);
                                }
                            }))));
                }
            })
            ->with(['participants.user', 'latestMessage.author'])
            ->orderByRaw('last_message_at is null')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Groups the person is in now — "where to write" from a record page.
     *
     * @return Collection<int, Conversation>
     */
    public function activeGroupsFor(Model $user): Collection
    {
        return $this->query()
            ->where('type', ConversationType::Group)
            ->whereHas('participants', fn (Builder $query): Builder => $query->where('user_id', $user->getKey())->whereNull('left_at'))
            ->orderBy('title')
            ->get();
    }

    /**
     * Unread per conversation: conversation id → count (no zeros).
     *
     * @return array<int, int>
     */
    public function unreadByConversation(Model $user): array
    {
        $counts = [];

        $rows = $this->unreadQuery($user)
            ->selectRaw('m.conversation_id, count(*) as aggregate')
            ->groupBy('m.conversation_id')
            ->get();

        foreach ($rows as $row) {
            $counts[(int) $row->conversation_id] = (int) $row->aggregate;
        }

        return $counts;
    }

    /**
     * Unread in total — the navigation and dock badges.
     */
    public function unreadTotalFor(Model $user): int
    {
        return $this->unreadQuery($user)->count();
    }

    public function unreadIn(Conversation $conversation, Model $user): int
    {
        return $this->unreadQuery($user)->where('m.conversation_id', $conversation->id)->count();
    }

    /**
     * The read pointer only moves forward. Returns whether it actually moved —
     * that decides whether members are told (ChatService::markRead).
     */
    public function markRead(Conversation $conversation, Model $user, int $messageId): bool
    {
        return $conversation->participants()
            ->where('user_id', $user->getKey())
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('last_read_message_id')
                ->orWhere('last_read_message_id', '<', $messageId))
            ->toBase()
            ->update(['last_read_message_id' => $messageId]) > 0;
    }

    /**
     * The conversation rises in the list to its latest message.
     */
    public function touchLastMessage(Conversation $conversation, Message $message): void
    {
        $conversation->last_message_at = $message->created_at;
        $conversation->save();
    }

    private function newConversation(): Conversation
    {
        $model = ChatConfig::conversationModel();

        return new $model;
    }

    /**
     * Other people's unread messages in conversations the person is in now.
     */
    private function unreadQuery(Model $user): QueryBuilder
    {
        $model = new (ChatConfig::messageModel());
        // A message model with soft deletes (an extension's): a deleted message is nothing to read.
        $deletedAt = method_exists($model, 'getDeletedAtColumn') ? $model->getDeletedAtColumn() : null;

        return DB::table(ChatConfig::table('messages').' as m')
            ->when($deletedAt !== null, fn (QueryBuilder $query): QueryBuilder => $query->whereNull('m.'.$deletedAt))
            ->join(ChatConfig::table('participants').' as p', function (JoinClause $join) use ($user): void {
                $join->on('p.conversation_id', '=', 'm.conversation_id')
                    ->where('p.user_id', '=', $user->getKey())
                    ->whereNull('p.left_at');
            })
            ->where(fn (QueryBuilder $query): QueryBuilder => $query->whereNull('m.user_id')->orWhere('m.user_id', '!=', $user->getKey()))
            ->where(fn (QueryBuilder $query): QueryBuilder => $query
                ->whereNull('p.last_read_message_id')
                ->orWhereColumn('m.id', '>', 'p.last_read_message_id'));
    }

    /**
     * Group members from the form: only people one can write to, the creator
     * always.
     *
     * @return list<int>
     */
    private function groupMembers(GroupData $data, int $creatorId): array
    {
        if ($data->title === '') {
            throw new InvalidArgumentException(__('filament-chat::chat.error_title'));
        }

        $members = array_values(array_unique([$creatorId, ...ChatUsers::chattableIds($data->memberIds)]));

        if (count($members) < 2) {
            throw new InvalidArgumentException(__('filament-chat::chat.error_members'));
        }

        return $members;
    }

    /**
     * Add a member or bring back one who left.
     */
    private function addParticipant(Conversation $conversation, int $userId): void
    {
        $participant = $conversation->participants()->where('user_id', $userId)->first();

        if ($participant !== null) {
            if (!$participant->isActive()) {
                $participant->left_at = null;
                $participant->joined_at = now();
                // Coming back is joining again: what was written while away is history, not unread.
                $participant->last_read_message_id = $conversation->messages()->max('id');
                $participant->save();
            }

            return;
        }

        $model = ChatConfig::participantModel();
        $participant = new $model;
        $participant->conversation_id = $conversation->id;
        $participant->user_id = $userId;
        $participant->joined_at = now();
        // History before joining is not "unread": the pointer starts at the latest message.
        $participant->last_read_message_id = $conversation->messages()->max('id');
        $participant->save();
    }

    /**
     * Active member names, alphabetical.
     *
     * @return list<string>
     */
    private function memberNames(Conversation $conversation): array
    {
        $names = array_values($this->activeMembers($conversation)->map(fn (Model $user): string => ChatUsers::name($user))->all());

        sort($names);

        return $names;
    }

    /**
     * @param list<string> $before
     * @param list<string> $after
     */
    private function membersChanged(Conversation $conversation, array $before, array $after): void
    {
        if ($before !== $after) {
            event(new GroupMembersChanged($conversation, $before, $after));
        }
    }
}
