<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Policies;

use Asignua\FilamentChat\Models\Message;
use Asignua\FilamentChat\Repositories\ConversationRepository;
use Asignua\FilamentChat\Support\ChatConfig;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * A message is visible to whoever sees its conversation. Its author may edit
 * it while still in the conversation and within the editing window. Messages
 * are never deleted.
 */
class MessagePolicy
{
    public function __construct(private readonly ConversationRepository $conversations) {}

    public function viewAny(Model $user): bool
    {
        return true;
    }

    public function view(Model $user, Message $message): bool
    {
        return Gate::forUser($user)->allows('view', $message->conversation);
    }

    public function update(Model $user, Message $message): bool
    {
        return self::editableBy($message, $user)
            && ($this->conversations->participant($message->conversation, $user)?->isActive() ?? false);
    }

    /**
     * The part of the rule that needs no query: editing on, own message,
     * within the window. The chat window combines it with "may write here".
     */
    public static function editableBy(Message $message, Model $user): bool
    {
        if (!ChatConfig::editingEnabled() || $message->user_id !== (int) $user->getKey()) {
            return false;
        }

        $window = ChatConfig::editingWindow();

        return $window === null || ($message->created_at !== null && $message->created_at->gte(now()->subMinutes($window)));
    }

    public function delete(Model $user, Message $message): bool
    {
        return false;
    }
}
