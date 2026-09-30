<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Policies;

use Asignua\FilamentChat\Models\Conversation;
use Asignua\FilamentChat\Repositories\ConversationRepository;
use Illuminate\Database\Eloquent\Model;

/**
 * A conversation is seen by its members only — admins included. Whoever left
 * a group sees the history up to leaving but cannot write. A group's title and
 * members are managed by its creator.
 */
class ConversationPolicy
{
    /** Ability: write into the conversation. */
    public const string SEND = 'send';

    /** Ability: leave a group. */
    public const string LEAVE = 'leave';

    public function __construct(private readonly ConversationRepository $conversations) {}

    public function viewAny(Model $user): bool
    {
        return true;
    }

    public function view(Model $user, Conversation $conversation): bool
    {
        return $this->conversations->participant($conversation, $user) !== null;
    }

    public function create(Model $user): bool
    {
        return true;
    }

    public function send(Model $user, Conversation $conversation): bool
    {
        return $this->conversations->participant($conversation, $user)?->isActive() ?? false;
    }

    public function update(Model $user, Conversation $conversation): bool
    {
        return $conversation->isGroup()
            && $conversation->created_by_user_id === $user->getKey()
            && $this->send($user, $conversation);
    }

    public function leave(Model $user, Conversation $conversation): bool
    {
        return $conversation->isGroup() && $this->send($user, $conversation);
    }

    /**
     * Conversations are not deleted: they belong to several people.
     */
    public function delete(Model $user, Conversation $conversation): bool
    {
        return false;
    }
}
