<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Livewire;

use Asignua\FilamentChat\Notifications\NewMessageNotification;
use Asignua\FilamentChat\Pages\Chat;
use Asignua\FilamentChat\Repositories\ConversationRepository;
use Asignua\FilamentChat\Repositories\MessageRepository;
use Asignua\FilamentChat\Support\ChatConfig;
use Asignua\FilamentChat\Support\ChatUsers;
use Asignua\FilamentChat\Support\Presence;
use Asignua\FilamentChat\Support\Realtime;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Renderless;
use Livewire\Component;

/**
 * The chat button in the top bar: unread counter and a "Reply" toast when a
 * message lands in a conversation other than the one open in the slide-over.
 *
 * The slide-over itself is a separate Blade/Alpine block without Livewire
 * (hooks/dock-panel): this button re-renders on every message and poll, and
 * if the window lived inside it every render would re-initialise the window
 * — the composer would lose focus.
 */
class ChatDock extends Component
{
    /** Browser event "open the slide-over on a conversation" (toast, record action). */
    public const string EVENT_OPEN_DOCK = 'filament-chat-open-dock';

    /** The slide-over was shown or hidden (sent by the slide-over). */
    public const string EVENT_PANEL_VISIBILITY = 'filament-chat-panel-visibility';

    /** Browser event: show / hide the slide-over (the top-bar button). */
    public const string EVENT_TOGGLE = 'filament-chat-toggle';

    /**
     * Browser event: a new message from someone else — `{conversation, message, open}`; `open` — that conversation is on the
     * screen (the browser alone knows whether the tab is visible). Without a socket: the counter grew, all three empty.
     */
    public const string EVENT_INCOMING = 'filament-chat-incoming';

    /** localStorage: the slide-over is pinned (split screen). */
    public const string STORAGE_PINNED = 'filament-chat.pinned';

    /** localStorage: the conversation last open in the slide-over. */
    public const string STORAGE_CONVERSATION = 'filament-chat.conversation';

    /** Class on <html> while pinned — the page makes room for the slide-over. */
    public const string PINNED_CLASS = 'fchat-pinned';

    public bool $panelOpen = false;

    /**
     * No button and no toasts (on the Chat page itself, or with the dock
     * turned off) — only the counter for the tab badge.
     */
    #[Locked]
    public bool $quiet = false;

    public int $unread = 0;

    /** The counter has been shown once: a later growth is news (polling without a socket). */
    #[Locked]
    public bool $counted = false;

    /** ulid of the conversation open in the slide-over (decides about toasts). */
    public ?string $conversation = null;

    /**
     * @return array<string, string>
     */
    protected function getListeners(): array
    {
        $user = ChatUsers::current();

        return $user !== null && ChatConfig::realtime()
            ? [Realtime::listener($user) => 'onChatUpdated']
            : [];
    }

    #[On(ChatWindow::EVENT_OPENED)]
    #[Renderless]
    public function rememberConversation(?string $conversation): void
    {
        $this->conversation = $conversation;
    }

    #[On(self::EVENT_PANEL_VISIBILITY)]
    #[Renderless]
    public function setPanelOpen(bool $open): void
    {
        $this->panelOpen = $open;
    }

    /**
     * The window read something — the counter is recounted in render().
     */
    #[On(ChatWindow::EVENT_READ)]
    public function refreshUnread(): void {}

    /**
     * @param array<string, mixed> $payload
     */
    public function onChatUpdated(array $payload): void
    {
        $user = ChatUsers::current();
        $reader = $payload['reader'] ?? null;

        // Somebody else's read moves nobody's counter but theirs; the button needs no round trip for it.
        // The person's own reads come through EVENT_READ (this tab) or this very event (another tab).
        if (is_string($reader) && ($user === null || $reader !== ChatUsers::broadcastKey($user))) {
            $this->skipRender();

            return;
        }

        $ulid = $payload['conversation'] ?? null;
        $messageUlid = $payload['message'] ?? null;

        if ($user === null || !is_string($ulid) || !is_string($messageUlid) || ($payload['author'] ?? null) === ChatUsers::broadcastKey($user)) {
            return;
        }

        // On the screen: in the open slide-over, or in the Chat page's own window (the counter is quiet there).
        $onScreen = $this->conversation === $ulid && ($this->panelOpen || $this->quiet);
        $this->dispatch(self::EVENT_INCOMING, conversation: $ulid, message: $messageUlid, open: $onScreen);

        if ($this->quiet || !ChatConfig::toasts() || $onScreen) {
            return;
        }

        $conversation = app(ConversationRepository::class)->findByUlid($ulid);

        if ($conversation === null || !Gate::allows('view', $conversation)) {
            return;
        }

        $message = app(MessageRepository::class)->findInConversation($conversation, $messageUlid);

        if ($message === null) {
            return;
        }

        Notification::make()
            ->title(NewMessageNotification::title($conversation, $message))
            ->body(NewMessageNotification::body($message))
            ->icon(Chat::icon())
            ->actions([
                Action::make('reply')
                    ->label(__('filament-chat::chat.reply'))
                    ->dispatch(self::EVENT_OPEN_DOCK, ['conversation' => $conversation->ulid])
                    ->close(),
            ])
            ->send();
    }

    public function render(): View
    {
        $user = ChatUsers::current();
        // The badge poll is the heartbeat of presence on every page of the panel.
        Presence::touch($user);
        $previous = $this->unread;
        $this->unread = $user !== null ? app(ConversationRepository::class)->unreadTotalFor($user) : 0;

        // Without a socket the poll is the only news: a grown counter is a new message (the socket announces its own).
        if ($this->counted && $this->unread > $previous && !ChatConfig::realtime()) {
            $this->dispatch(self::EVENT_INCOMING, conversation: null, message: null, open: false);
        }

        $this->counted = true;

        return view('filament-chat::livewire.chat-dock', [
            'polling' => ChatConfig::badgePolling(),
        ]);
    }
}
