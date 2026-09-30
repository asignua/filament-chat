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
use Illuminate\Support\Str;

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
            ->body(Str::limit($this->message->body, 140))
            ->icon(Chat::icon())
            ->actions([
                Action::make('open')
                    ->label(__('filament-chat::chat.open'))
                    ->url(Chat::urlFor($this->conversation))
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();
    }

    public static function title(Conversation $conversation, Message $message): string
    {
        $who = $message->author !== null ? ChatUsers::name($message->author) : __('filament-chat::chat.unknown_user');

        return $conversation->isGroup()
            ? __('filament-chat::chat.notification_group_title', ['who' => $who, 'title' => (string) $conversation->title])
            : __('filament-chat::chat.notification_title', ['who' => $who]);
    }
}
