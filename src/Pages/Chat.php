<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Pages;

use Asignua\FilamentChat\FilamentChatPlugin;
use Asignua\FilamentChat\Models\Conversation;
use Asignua\FilamentChat\Repositories\ConversationRepository;
use Asignua\FilamentChat\Support\ChatConfig;
use Asignua\FilamentChat\Support\ChatManager;
use Asignua\FilamentChat\Support\ChatUsers;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * The chat on a full page: conversations on the left, the feed on the right.
 * The same window lives in the top-bar slide-over.
 */
class Chat extends Page
{
    /** The open conversation in the page address: `/{panel}/chat?c={ulid}`. */
    public const string QUERY_CONVERSATION = 'c';

    protected string $view = 'filament-chat::pages.chat';

    public static function icon(): string|BackedEnum
    {
        return Heroicon::OutlinedChatBubbleLeftRight;
    }

    public static function getSlug(?Panel $panel = null): string
    {
        return ChatConfig::slug();
    }

    public static function getNavigationIcon(): string|BackedEnum|null
    {
        return self::plugin()?->getNavigationIcon() ?? self::icon();
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return self::plugin()?->getNavigationGroup() ?? ChatConfig::navigationGroup();
    }

    public static function getNavigationSort(): ?int
    {
        return ChatConfig::navigationSort();
    }

    public static function getNavigationLabel(): string
    {
        return __('filament-chat::chat.chat');
    }

    public function getTitle(): string
    {
        return __('filament-chat::chat.chat');
    }

    public static function getNavigationBadge(): ?string
    {
        $user = ChatUsers::current();

        if ($user === null) {
            return null;
        }

        $count = app(ConversationRepository::class)->unreadTotalFor($user);

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'danger';
    }

    public static function urlFor(Conversation $conversation): string
    {
        return static::getUrl([self::QUERY_CONVERSATION => $conversation->ulid], panel: app(ChatManager::class)->panelId);
    }

    /**
     * The conversation from the address — handed to the window as a parameter.
     */
    public function initialConversation(): ?string
    {
        $ulid = request()->query(self::QUERY_CONVERSATION);

        return is_string($ulid) && $ulid !== '' ? $ulid : null;
    }

    /**
     * @return list<string>
     */
    public function getPageClasses(): array
    {
        return ['fchat-page'];
    }

    private static function plugin(): ?FilamentChatPlugin
    {
        return app(ChatManager::class)->plugin;
    }
}
