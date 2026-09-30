<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Livewire;

use Asignua\FilamentChat\Data\GroupData;
use Asignua\FilamentChat\Data\MessageData;
use Asignua\FilamentChat\Enums\Reaction;
use Asignua\FilamentChat\Models\Conversation;
use Asignua\FilamentChat\Models\Message;
use Asignua\FilamentChat\Pages\Chat;
use Asignua\FilamentChat\Policies\ConversationPolicy;
use Asignua\FilamentChat\Policies\MessagePolicy;
use Asignua\FilamentChat\Repositories\ConversationRepository;
use Asignua\FilamentChat\Repositories\MessageRepository;
use Asignua\FilamentChat\Repositories\ReactionRepository;
use Asignua\FilamentChat\Services\ChatService;
use Asignua\FilamentChat\Support\ChatConfig;
use Asignua\FilamentChat\Support\ChatManager;
use Asignua\FilamentChat\Support\ChatUsers;
use Asignua\FilamentChat\Support\ReadStatus;
use Asignua\FilamentChat\Support\Realtime;
use Asignua\FilamentChat\Support\References\RecordUrlResolver;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The chat window: conversation list, the open conversation's feed and the
 * composer. Shared by the Chat page (two columns, conversation in `?c=`) and
 * the top-bar slide-over (`compact`: list OR conversation, address untouched).
 *
 * News arrives as `filament-chat.updated` on the person's private channel
 * (Echo); the component only re-reads — policies decide what is visible.
 */
class ChatWindow extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    /** Livewire event "open this conversation" (slide-over, toast, record action). */
    public const string EVENT_OPEN = 'filament-chat-open';

    /** Livewire event "a conversation was opened" — the slide-over remembers which. */
    public const string EVENT_OPENED = 'filament-chat-opened';

    /** Livewire event: the slide-over was shown or hidden. */
    public const string EVENT_VISIBILITY = 'filament-chat-visibility';

    /** Browser event: the (lazy) window in the slide-over is mounted. */
    public const string EVENT_READY = 'filament-chat-window-ready';

    /** Livewire event: something was read — the dock recounts its badge. */
    public const string EVENT_READ = 'filament-chat-read';

    /** Browser event: scroll the feed to the bottom. */
    public const string EVENT_SCROLL = 'filament-chat-scroll';

    /** Browser event: message sent — the composer takes focus back. */
    public const string EVENT_SENT = 'filament-chat-sent';

    /** Browser event: a message was put into the composer for editing. */
    public const string EVENT_EDIT = 'filament-chat-edit';

    /** Livewire event: attach a record to the message being written. */
    public const string EVENT_ATTACH = 'filament-chat-attach';

    #[Locked]
    public bool $compact = false;

    /** ulid of the open conversation. */
    public ?string $conversation = null;

    public string $search = '';

    public string $body = '';

    public ?string $referenceType = null;

    public ?int $referenceId = null;

    /** ulid of the own message being edited in the composer. */
    public ?string $editing = null;

    public int $limit = 0;

    /**
     * The record whose page the slide-over is open on — for "Attach current".
     * Resolved once from the page address on mount (the window is lazy, so
     * its own mount request is /livewire/update, not the page).
     */
    #[Locked]
    public ?string $pageReferenceType = null;

    #[Locked]
    public ?int $pageReferenceId = null;

    /** A hidden window (closed slide-over) does not mark anything as read. */
    public bool $hidden = false;

    public function mount(?string $conversation = null, bool $compact = false, ?string $pageUrl = null): void
    {
        $this->compact = $compact;
        $this->limit = ChatConfig::pageSize();

        $page = $compact && $pageUrl !== null ? RecordUrlResolver::resolve($pageUrl) : null;
        $this->pageReferenceType = $page['type'] ?? null;
        $this->pageReferenceId = $page['id'] ?? null;

        if ($compact) {
            $this->dispatch(self::EVENT_READY);
        }

        if ($conversation !== null) {
            $this->open($conversation);
        }
    }

    /**
     * On the page the conversation lives in the address; in the slide-over it
     * does not — someone else's page address stays untouched.
     *
     * @return array<string, array<string, string>>
     */
    protected function queryString(): array
    {
        return $this->compact ? [] : ['conversation' => ['as' => Chat::QUERY_CONVERSATION]];
    }

    /**
     * @return array<string, string>
     */
    protected function getListeners(): array
    {
        $listeners = [self::EVENT_OPEN => 'open'];
        $user = ChatUsers::current();

        if ($user !== null && ChatConfig::realtime()) {
            $listeners[Realtime::listener($user)] = 'onChatUpdated';
        }

        return $listeners;
    }

    public function open(string $conversation): void
    {
        $record = app(ConversationRepository::class)->findByUlid($conversation);

        if ($record === null || !Gate::allows('view', $record)) {
            $this->conversation = null;

            return;
        }

        if ($this->conversation !== $record->ulid) {
            $this->limit = ChatConfig::pageSize();
            $this->clearReference();
            $this->cancelEdit();
        }

        $this->conversation = $record->ulid;
        $this->markRead($record);
        $this->dispatch(self::EVENT_SCROLL);
        $this->dispatch(self::EVENT_OPENED, conversation: $record->ulid);
    }

    #[On(self::EVENT_VISIBILITY)]
    public function setVisible(bool $visible): void
    {
        $this->hidden = !$visible;
        $current = $this->current();

        if ($visible && $current !== null) {
            $this->markRead($current);
            $this->dispatch(self::EVENT_SCROLL);
        }
    }

    /**
     * Back to the list (slide-over, narrow page).
     */
    public function back(): void
    {
        $this->conversation = null;
        $this->dispatch(self::EVENT_OPENED, conversation: null);
    }

    /**
     * A new message, a read or a reaction. Only a new message scrolls down —
     * otherwise someone's "read" would jerk a feed the person is scrolling.
     *
     * @param array<string, mixed> $payload
     */
    public function onChatUpdated(array $payload): void
    {
        $current = $this->current();

        if ($current === null || ($payload['conversation'] ?? null) !== $current->ulid) {
            return;
        }

        $this->markRead($current);

        if (is_string($payload['message'] ?? null)) {
            $this->dispatch(self::EVENT_SCROLL);
        }
    }

    /**
     * Polling as the fallback without a socket — only with a conversation open.
     */
    public function poll(): void
    {
        $current = $this->current();

        if ($current !== null) {
            $this->markRead($current);
        }
    }

    public function send(): void
    {
        $conversation = $this->current();
        $user = ChatUsers::current();

        if ($conversation === null || $user === null || !Gate::allows(ConversationPolicy::SEND, $conversation)) {
            return;
        }

        if ($this->editing !== null) {
            $this->saveEdit($conversation, $user);

            return;
        }

        try {
            app(ChatService::class)->send($conversation, $user, MessageData::fromArray([
                'body' => $this->body,
                'reference_type' => $this->referenceType,
                'reference_id' => $this->referenceId,
            ]));
        } catch (InvalidArgumentException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        $this->body = '';
        $this->clearReference();
        $this->dispatch(self::EVENT_SCROLL);
        $this->dispatch(self::EVENT_SENT);
    }

    /**
     * A reaction to a message of the open conversation. The ulid comes from
     * the client, so the message is looked up in this conversation only.
     */
    public function react(string $message, string $reaction): void
    {
        $conversation = $this->current();
        $user = ChatUsers::current();
        $choice = ChatConfig::reactions() ? Reaction::tryFrom($reaction) : null;

        if ($conversation === null || $user === null || $choice === null || !Gate::allows(ConversationPolicy::SEND, $conversation)) {
            return;
        }

        $record = app(MessageRepository::class)->findInConversation($conversation, $message);

        if ($record === null) {
            return;
        }

        try {
            app(ChatService::class)->react($conversation, $record, $user, $choice);
        } catch (InvalidArgumentException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    /**
     * Put an own message into the composer for editing.
     */
    public function startEdit(string $message): void
    {
        $conversation = $this->current();
        $record = $conversation !== null ? app(MessageRepository::class)->findInConversation($conversation, $message) : null;

        if ($record === null || !Gate::allows('update', $record)) {
            return;
        }

        $this->editing = $record->ulid;
        $this->body = $record->body;
        $this->clearReference();
        $this->dispatch(self::EVENT_EDIT, body: $record->body);
    }

    /**
     * ↑ in an empty composer: edit one's latest message.
     */
    public function editLast(): void
    {
        $conversation = $this->current();
        $user = ChatUsers::current();

        if ($conversation === null || $user === null) {
            return;
        }

        $leftAt = app(ConversationRepository::class)->participant($conversation, $user)?->left_at;
        $last = app(MessageRepository::class)->lastBy($conversation, $user, $leftAt);

        if ($last !== null) {
            $this->startEdit($last->ulid);
        }
    }

    public function cancelEdit(): void
    {
        if ($this->editing !== null) {
            $this->editing = null;
            $this->body = '';
        }
    }

    public function loadOlder(): void
    {
        $this->limit += ChatConfig::pageSize();
    }

    public function clearReference(): void
    {
        $this->referenceType = null;
        $this->referenceId = null;
    }

    #[On(self::EVENT_ATTACH)]
    public function attach(string $type, int $id): void
    {
        if (app(ChatManager::class)->references->get($type) !== null && $id > 0) {
            $this->referenceType = $type;
            $this->referenceId = $id;
        }
    }

    /**
     * A record page link dropped into the chat.
     */
    public function attachUrl(string $url): void
    {
        $reference = RecordUrlResolver::resolve($url);

        if ($reference === null) {
            $type = RecordUrlResolver::unsupportedResourceLabel($url);

            Notification::make()
                ->title($type !== null
                    ? __('filament-chat::chat.drop_not_allowed', ['type' => $type])
                    : __('filament-chat::chat.drop_unsupported'))
                ->warning()
                ->send();

            return;
        }

        $this->referenceType = $reference['type'];
        $this->referenceId = $reference['id'];
    }

    /**
     * "Attach current": the record of the page the slide-over is open on.
     */
    public function attachPage(): void
    {
        if ($this->pageReferenceType !== null && $this->pageReferenceId !== null) {
            $this->attach($this->pageReferenceType, $this->pageReferenceId);
        }
    }

    public function newDirectAction(): Action
    {
        return Action::make('newDirect')
            ->label(__('filament-chat::chat.new_direct'))
            ->icon('heroicon-o-user-plus')
            ->color('gray')
            ->size('sm')
            ->modalWidth('md')
            ->schema([
                Select::make('user_id')
                    ->label(__('filament-chat::chat.with'))
                    ->options(fn (): array => ChatUsers::choices(ChatUsers::current()))
                    ->searchable()
                    ->native(false)
                    ->required(),
            ])
            ->modalSubmitActionLabel(__('filament-chat::chat.start'))
            ->action(function (array $data): void {
                $me = ChatUsers::current();
                $other = ChatUsers::findChattable($data['user_id'] ?? null);

                if ($me === null || $other === null) {
                    return;
                }

                try {
                    $conversation = app(ChatService::class)->startDirect($me, $other);
                } catch (InvalidArgumentException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                $this->open($conversation->ulid);
            });
    }

    public function newGroupAction(): Action
    {
        return Action::make('newGroup')
            ->visible(fn (): bool => ChatConfig::groups())
            ->label(__('filament-chat::chat.new_group'))
            ->icon('heroicon-o-user-group')
            ->color('gray')
            ->size('sm')
            ->modalWidth('lg')
            ->schema($this->groupFields())
            ->modalSubmitActionLabel(__('filament-chat::chat.create_group'))
            ->action(function (array $data): void {
                $me = ChatUsers::current();

                if ($me === null) {
                    return;
                }

                try {
                    $conversation = app(ChatService::class)->createGroup(GroupData::fromArray($data), $me);
                } catch (InvalidArgumentException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                $this->open($conversation->ulid);
            });
    }

    public function manageGroupAction(): Action
    {
        return Action::make('manageGroup')
            ->label(__('filament-chat::chat.manage_group'))
            ->icon('heroicon-o-cog-6-tooth')
            ->iconButton()
            ->color('gray')
            ->modalHeading(__('filament-chat::chat.manage_group'))
            ->modalWidth('lg')
            ->visible(fn (): bool => ($c = $this->current()) !== null && Gate::allows('update', $c))
            ->fillForm(fn (): array => ($c = $this->current()) === null ? [] : [
                'title' => $c->title,
                // The creator is not in the options — a bare key would show; the repository keeps them anyway.
                'member_ids' => array_values(array_diff(
                    app(ConversationRepository::class)->activeMemberIds($c),
                    [ChatUsers::current()?->getKey()],
                )),
            ])
            ->schema($this->groupFields())
            ->modalSubmitActionLabel(__('filament-chat::chat.save_group'))
            ->action(function (array $data): void {
                $conversation = $this->current();

                if ($conversation === null || !Gate::allows('update', $conversation)) {
                    return;
                }

                try {
                    app(ChatService::class)->updateGroup($conversation, GroupData::fromArray($data));
                } catch (InvalidArgumentException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                }
            });
    }

    public function leaveGroupAction(): Action
    {
        return Action::make('leaveGroup')
            ->label(__('filament-chat::chat.leave_group'))
            ->icon('heroicon-o-arrow-right-start-on-rectangle')
            ->iconButton()
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('filament-chat::chat.leave_group'))
            ->modalDescription(__('filament-chat::chat.leave_group_hint'))
            ->visible(fn (): bool => ($c = $this->current()) !== null && Gate::allows(ConversationPolicy::LEAVE, $c))
            ->action(function (): void {
                $conversation = $this->current();
                $me = ChatUsers::current();

                if ($conversation === null || $me === null || !Gate::allows(ConversationPolicy::LEAVE, $conversation)) {
                    return;
                }

                app(ChatService::class)->leave($conversation, $me);
            });
    }

    public function attachRecordAction(): Action
    {
        $references = app(ChatManager::class)->references;

        return Action::make('attachRecord')
            ->label(__('filament-chat::chat.attach_record'))
            ->icon('heroicon-o-link')
            ->iconButton()
            ->color('gray')
            ->modalHeading(__('filament-chat::chat.attach_record'))
            ->modalWidth('lg')
            ->schema([
                Select::make('reference_type')
                    ->label(__('filament-chat::chat.reference_type'))
                    ->options(fn (): array => $references->options())
                    ->default(fn (): ?string => array_key_first($references->all()))
                    ->native(false)
                    ->live()
                    ->afterStateUpdated(fn (Set $set) => $set('reference_id', null))
                    ->required(),
                Select::make('reference_id')
                    ->label(__('filament-chat::chat.reference_record'))
                    ->searchable()
                    ->native(false)
                    ->getSearchResultsUsing(fn (string $search, Get $get): array => self::searchRecords($get('reference_type'), $search))
                    ->getOptionLabelUsing(fn (mixed $value, Get $get): ?string => self::recordLabel($get('reference_type'), $value))
                    ->required(),
            ])
            ->modalSubmitActionLabel(__('filament-chat::chat.attach'))
            ->action(function (array $data): void {
                if (is_string($data['reference_type'] ?? null) && is_numeric($data['reference_id'] ?? null)) {
                    $this->attach($data['reference_type'], (int) $data['reference_id']);
                }
            });
    }

    public function render(): View
    {
        $user = ChatUsers::current();
        $conversations = $user !== null
            ? app(ConversationRepository::class)->listFor($user, $this->search)
            : new Collection;
        $current = $this->current();
        $messages = $this->loadMessages($current);
        $references = app(ChatManager::class)->references;
        $canSend = $current !== null && Gate::allows(ConversationPolicy::SEND, $current);
        $names = [];

        foreach ($current->participants ?? [] as $participant) {
            if ($participant->user !== null) {
                $names[$participant->user_id] = ChatUsers::name($participant->user);
            }
        }

        return view('filament-chat::livewire.chat-window', [
            'me' => $user,
            'conversations' => $conversations,
            'unread' => $user !== null ? app(ConversationRepository::class)->unreadByConversation($user) : [],
            'current' => $current,
            'messages' => $messages,
            'hasOlder' => $current !== null && $messages->isNotEmpty()
                && app(MessageRepository::class)->hasOlderThan($current, $messages->first()->id),
            // Read pointers come from the participants already loaded, once per render.
            'readStatus' => ChatConfig::readReceipts() && $current !== null && $user !== null
                ? ReadStatus::for($current->participants, (int) $user->getKey())
                : null,
            'reactions' => app(ReactionRepository::class)->forMessages(
                array_values($messages->map(fn (Message $message): int => $message->id)->all()),
            ),
            'canSend' => $canSend,
            // Own messages that may still be edited — the policy rule without a query per message.
            'editable' => $canSend && $user !== null
                ? array_values($messages->filter(fn (Message $message): bool => MessagePolicy::editableBy($message, $user))->map(fn (Message $message): int => $message->id)->all())
                : [],
            // key → name of everyone in the conversation: mentions in messages are highlighted by it.
            'names' => $names,
            // Whom "@" offers in the composer: active members but me.
            'mentionable' => ChatConfig::mentions() && $current !== null && $user !== null
                ? array_values($current->participants
                    ->filter(fn ($participant): bool => $participant->isActive() && $participant->user_id !== $user->getKey() && $participant->user !== null)
                    ->map(fn ($participant): string => $names[$participant->user_id])
                    ->sort()
                    ->all())
                : [],
            // Explicit flags: an action rendered already in mount() is shown disabled
            // by Filament rather than hidden, so the group buttons render only on these.
            'canManage' => $current !== null && Gate::allows('update', $current),
            'canLeave' => $current !== null && Gate::allows(ConversationPolicy::LEAVE, $current),
            'canAttach' => !$references->isEmpty(),
            'members' => $current !== null && $current->isGroup()
                ? app(ConversationRepository::class)->activeMembers($current)
                : collect(),
            'pendingReference' => $references->present($this->referenceType, $this->referenceId),
            'pageReference' => $this->referenceType === null
                ? $references->present($this->pageReferenceType, $this->pageReferenceId)
                : null,
            'realtime' => ChatConfig::realtime(),
            'polling' => ChatConfig::polling(),
            'color' => ChatConfig::color(),
            'groups' => ChatConfig::groups(),
            'reactionsOn' => ChatConfig::reactions(),
        ]);
    }

    private function saveEdit(Conversation $conversation, Model $user): void
    {
        $message = app(MessageRepository::class)->findInConversation($conversation, (string) $this->editing);

        if ($message === null) {
            $this->cancelEdit();

            return;
        }

        try {
            app(ChatService::class)->edit($conversation, $message, $user, $this->body);
        } catch (InvalidArgumentException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        $this->editing = null;
        $this->body = '';
        $this->dispatch(self::EVENT_SENT);
    }

    /**
     * The open conversation — only if the person may see it (the ulid comes from the client).
     */
    protected function current(): ?Conversation
    {
        if ($this->conversation === null) {
            return null;
        }

        $conversation = app(ConversationRepository::class)->findByUlid($this->conversation);

        if ($conversation === null || !Gate::allows('view', $conversation)) {
            return null;
        }

        return $conversation->loadMissing('participants.user');
    }

    /**
     * @return Collection<int, Message>
     */
    private function loadMessages(?Conversation $conversation): Collection
    {
        $user = ChatUsers::current();

        if ($conversation === null || $user === null) {
            return new Collection;
        }

        // Whoever left a group sees the history up to leaving only.
        $leftAt = app(ConversationRepository::class)->participant($conversation, $user)?->left_at;

        return app(MessageRepository::class)->latest($conversation, $this->limit, $leftAt);
    }

    private function markRead(Conversation $conversation): void
    {
        if ($this->hidden) {
            return;
        }

        $user = ChatUsers::current();
        $last = $this->loadMessages($conversation)->last();

        if ($user !== null && $last !== null && app(ChatService::class)->markRead($conversation, $user, $last->id)) {
            $this->dispatch(self::EVENT_READ)->to(ChatDock::class);
        }
    }

    /**
     * @return list<\Filament\Schemas\Components\Component>
     */
    private function groupFields(): array
    {
        return [
            TextInput::make('title')
                ->label(__('filament-chat::chat.group_title'))
                ->required()
                ->maxLength(120),
            Select::make('member_ids')
                ->label(__('filament-chat::chat.members'))
                ->options(fn (): array => ChatUsers::choices(ChatUsers::current()))
                ->multiple()
                ->searchable()
                ->native(false)
                ->required(),
        ];
    }

    /**
     * Records of the chosen type for a reference — only those the person may open.
     *
     * @return array<int|string, string>
     */
    private static function searchRecords(mixed $type, string $search): array
    {
        $reference = app(ChatManager::class)->references->get(is_string($type) ? $type : null);

        if ($reference === null || trim($search) === '') {
            return [];
        }

        $options = [];

        foreach ($reference->searchRecords($search) as $record) {
            /** @var Model $record */
            if ($reference->canView($record)) {
                $options[$record->getKey()] = $reference->getTitle($record);
            }
        }

        return $options;
    }

    private static function recordLabel(mixed $type, mixed $id): ?string
    {
        if (!is_string($type) || !is_numeric($id)) {
            return null;
        }

        return app(ChatManager::class)->references->present($type, (int) $id)['label'] ?? null;
    }
}
