<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Notifications;

use Asignua\FilamentChat\Models\Conversation;
use Asignua\FilamentChat\Models\Message;
use Asignua\FilamentChat\Pages\Chat;
use Asignua\FilamentChat\Support\ChatUsers;
use Filament\Actions\Action;
use Filament\Notifications\Notification as PanelNotification;
use Illuminate\Notifications\Notification;

/**
 * "Someone wrote to you" — the panel bell. Sent only for the first unread
 * message of a conversation (ChatService); the rest is counted by the badge.
 */
class NewMessageNotification extends Notification
{
    public function __construct(
        public readonly Conversation $conversation,
        public readonly Message $message,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return PanelNotification::make()
            ->title(self::title($this->conversation, $this->message))
            ->body(self::body($this->message))
            ->icon(Chat::icon())
            ->actions([
                Action::make('open')
                    ->label(__('filament-chat::chat.open'))
                    ->url(Chat::urlFor($this->conversation))
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();
    }

    /**
     * Ready for Filament: it renders the title as HTML (sanitized, but links,
     * images and inline styles survive), so the user-supplied name and group
     * title are escaped — they must stay plain text, as in the feed.
     */
    public static function title(Conversation $conversation, Message $message): string
    {
        $who = e($message->author !== null ? ChatUsers::name($message->author) : __('filament-chat::chat.unknown_user'));

        return $conversation->isGroup()
            ? __('filament-chat::chat.notification_group_title', ['who' => $who, 'title' => e((string) $conversation->title)])
            : __('filament-chat::chat.notification_title', ['who' => $who]);
    }

    /**
     * The message preview, escaped for the same reason as the title: Filament
     * renders the body as HTML, a chat message is plain text.
     */
    public static function body(Message $message): string
    {
        return e($message->preview());
    }
}
