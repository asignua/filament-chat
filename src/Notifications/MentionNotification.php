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
 * "Someone mentioned you" — the bell rings for every mention, not only the
 * first unread message of the conversation.
 */
class MentionNotification extends Notification
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
            ->body($this->message->preview())
            ->icon('heroicon-o-at-symbol')
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
            ? __('filament-chat::chat.mention_group_title', ['who' => $who, 'title' => (string) $conversation->title])
            : __('filament-chat::chat.mention_title', ['who' => $who]);
    }
}
