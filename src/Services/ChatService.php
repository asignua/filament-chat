<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Services;

use Asignua\FilamentChat\Data\GroupData;
use Asignua\FilamentChat\Data\MessageData;
use Asignua\FilamentChat\Enums\Reaction;
use Asignua\FilamentChat\Events\ChatUpdated;
use Asignua\FilamentChat\Models\Conversation;
use Asignua\FilamentChat\Models\Message;
use Asignua\FilamentChat\Notifications\MentionNotification;
use Asignua\FilamentChat\Notifications\NewMessageNotification;
use Asignua\FilamentChat\Repositories\ConversationRepository;
use Asignua\FilamentChat\Repositories\MessageRepository;
use Asignua\FilamentChat\Repositories\ReactionRepository;
use Asignua\FilamentChat\Support\ChatConfig;
use Asignua\FilamentChat\Support\ChatUsers;
use Asignua\FilamentChat\Support\Mentions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Throwable;

/**
 * The chat with its side effects: after a write — an event into the members'
 * sockets and a bell notification for whom it is the first unread message in
 * the conversation (after that only the counter grows, so the bell is not
 * flooded). Columns are written by the repositories.
 */
class ChatService
{
    public function __construct(
        private readonly ConversationRepository $conversations,
        private readonly MessageRepository $messages,
        private readonly ReactionRepository $reactions,
    ) {}

    public function send(Conversation $conversation, Model $author, MessageData $data): Message
    {
        if (!($this->conversations->participant($conversation, $author)?->isActive() ?? false)) {
            throw new InvalidArgumentException(__('filament-chat::chat.error_not_member'));
        }

        $mentions = $this->mentionsIn($conversation, $author, $data->body);

        // Only a message of this very conversation can be answered — the ulid comes from the client.
        $replyTo = ChatConfig::replies() && $data->replyTo !== null
            ? $this->messages->findInConversation($conversation, $data->replyTo)
            : null;

        $message = DB::transaction(function () use ($conversation, $author, $data, $mentions, $replyTo): Message {
            $message = $this->messages->create($conversation, $author, $data, $mentions, $replyTo?->id);
            $this->conversations->touchLastMessage($conversation, $message);
            $this->conversations->markRead($conversation, $author, $message->id);

            return $message;
        });

        $members = $this->conversations->activeMembers($conversation);

        $this->broadcast(
            $conversation,
            array_values($members->map(fn (Model $member): string => ChatUsers::broadcastKey($member))->all()),
            $message,
            $author,
        );

        if (ChatConfig::databaseNotifications()) {
            foreach ($members as $member) {
                if ($member->getKey() === $author->getKey() || !method_exists($member, 'notify')) {
                    continue;
                }

                // A mention always rings; otherwise only the first unread message does.
                if (in_array((int) $member->getKey(), $mentions, true)) {
                    $member->notify(new MentionNotification($conversation, $message));
                } elseif ($this->conversations->unreadIn($conversation, $member) === 1) {
                    $member->notify(new NewMessageNotification($conversation, $message));
                }
            }
        }

        return $message;
    }

    /**
     * The author edits the text (MessagePolicy::update decides whether they
     * still may). People newly mentioned by the edit are notified.
     */
    public function edit(Conversation $conversation, Message $message, Model $user, string $body): Message
    {
        if ($message->conversation_id !== $conversation->id || !Gate::forUser($user)->allows('update', $message)) {
            throw new InvalidArgumentException(__('filament-chat::chat.error_cannot_edit'));
        }

        $before = $message->mentionIds();
        $mentions = $this->mentionsIn($conversation, $user, $body);

        $this->messages->update($message, $body, $mentions);

        $this->broadcast($conversation, $this->conversations->activeMemberKeys($conversation));

        $added = array_diff($mentions, $before);

        if ($added !== [] && ChatConfig::databaseNotifications()) {
            foreach ($this->conversations->activeMembers($conversation) as $member) {
                if (in_array((int) $member->getKey(), $added, true) && method_exists($member, 'notify')) {
                    $member->notify(new MentionNotification($conversation, $message));
                }
            }
        }

        return $message;
    }

    /**
     * Active members (but the author) mentioned in the text by name.
     *
     * @return list<int>
     */
    private function mentionsIn(Conversation $conversation, Model $author, string $body): array
    {
        if (!ChatConfig::mentions() || !str_contains($body, '@')) {
            return [];
        }

        $people = [];

        foreach ($this->conversations->activeMembers($conversation) as $member) {
            if ($member->getKey() !== $author->getKey()) {
                $people[(int) $member->getKey()] = ChatUsers::name($member);
            }
        }

        return Mentions::find($body, $people);
    }

    /**
     * The person has read up to `$messageId`. Members are told only when the
     * pointer moved: the author gets ✓✓, and reading again sends nothing, so
     * there is no "read → event → read" loop.
     */
    public function markRead(Conversation $conversation, Model $user, int $messageId): bool
    {
        if (!$this->conversations->markRead($conversation, $user, $messageId)) {
            return false;
        }

        $this->broadcast($conversation, $this->conversations->activeMemberKeys($conversation));

        return true;
    }

    public function startDirect(Model $me, Model $other): Conversation
    {
        return $this->conversations->findOrCreateDirect($me, $other);
    }

    public function createGroup(GroupData $data, Model $creator): Conversation
    {
        if (!ChatConfig::groups()) {
            throw new InvalidArgumentException(__('filament-chat::chat.error_groups_off'));
        }

        $conversation = $this->conversations->createGroup($data, $creator);

        $this->broadcast($conversation, $this->conversations->activeMemberKeys($conversation));

        return $conversation;
    }

    public function updateGroup(Conversation $conversation, GroupData $data): Conversation
    {
        // Whoever was removed must see the change too — notify members before and after.
        $before = $this->conversations->activeMemberKeys($conversation);

        $this->conversations->updateGroup($conversation, $data);

        $this->broadcast($conversation, [...$before, ...$this->conversations->activeMemberKeys($conversation)]);

        return $conversation;
    }

    public function leave(Conversation $conversation, Model $user): void
    {
        $this->conversations->leave($conversation, $user);

        $this->broadcast($conversation, [...$this->conversations->activeMemberKeys($conversation), ChatUsers::broadcastKey($user)]);
    }

    /**
     * A reaction (the same one removes it, another replaces it). Active
     * members only; no bell — members get the event and re-render the feed.
     */
    public function react(Conversation $conversation, Message $message, Model $user, Reaction $reaction): ?Reaction
    {
        if (!ChatConfig::reactions()) {
            throw new InvalidArgumentException(__('filament-chat::chat.error_reactions_off'));
        }

        if ($message->conversation_id !== $conversation->id
            || !($this->conversations->participant($conversation, $user)?->isActive() ?? false)) {
            throw new InvalidArgumentException(__('filament-chat::chat.error_not_member'));
        }

        $result = $this->reactions->toggle($message, $user, $reaction);

        $this->broadcast($conversation, $this->conversations->activeMemberKeys($conversation));

        return $result;
    }

    /**
     * The socket is not the source of truth: if it is down the message is
     * already stored and the badge catches up by polling — so a failure is
     * only reported.
     *
     * @param list<string> $keys
     */
    private function broadcast(Conversation $conversation, array $keys, ?Message $message = null, ?Model $author = null): void
    {
        $recipients = array_values(array_unique($keys));

        if ($recipients === [] || !ChatConfig::realtime()) {
            return;
        }

        try {
            event(new ChatUpdated(
                $recipients,
                $conversation->ulid,
                $message?->ulid,
                $author !== null ? ChatUsers::broadcastKey($author) : null,
            ));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
