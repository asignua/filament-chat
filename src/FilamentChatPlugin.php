<?php

declare(strict_types=1);

namespace Asignua\FilamentChat;

use Asignua\FilamentChat\Pages\Chat;
use Asignua\FilamentChat\Support\ChatManager;
use Asignua\FilamentChat\Support\References\ReferenceType;
use BackedEnum;
use Closure;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\Support\Facades\FilamentAsset;
use Filament\View\PanelsRenderHook;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Team chat for a Filament panel.
 *
 *     ->plugin(FilamentChatPlugin::make()
 *         ->users(fn (Builder $query) => $query->where('is_active', true))
 *         ->references([ReferenceType::resource(OrderResource::class)]))
 */
class FilamentChatPlugin implements Plugin
{
    public const string ID = 'filament-chat';

    /** @var (Closure(Builder<Model>): mixed)|null */
    protected ?Closure $users = null;

    /** @var (Closure(Model): string)|null */
    protected ?Closure $userName = null;

    /** @var Closure(): list<ReferenceType>|list<ReferenceType> */
    protected array|Closure $references = [];

    protected bool $dock = true;

    protected bool $pinnable = true;

    protected bool $tabBadge = true;

    protected string $color = 'primary';

    protected string|BackedEnum|null $navigationIcon = null;

    protected string|UnitEnum|null $navigationGroup = null;

    protected ?int $navigationSort = null;

    protected ?string $slug = null;

    public static function make(): static
    {
        return app(static::class);
    }

    public static function get(): static
    {
        /** @var static */
        return filament(static::ID);
    }

    public function getId(): string
    {
        return static::ID;
    }

    /**
     * Narrow down who can be written to and added to groups.
     *
     * @param Closure(Builder<Model>): mixed $query
     */
    public function users(Closure $query): static
    {
        $this->users = $query;

        return $this;
    }

    /**
     * How a person is called in the chat. Default: Filament's HasName, then `name`.
     *
     * @param Closure(Model): string $name
     */
    public function userName(Closure $name): static
    {
        $this->userName = $name;

        return $this;
    }

    /**
     * Record types a message can point at.
     *
     * @param Closure(): list<ReferenceType>|list<ReferenceType> $references
     */
    public function references(array|Closure $references): static
    {
        $this->references = $references;

        return $this;
    }

    /**
     * The chat button in the top bar with a slide-over panel.
     */
    public function dock(bool $condition = true): static
    {
        $this->dock = $condition;

        return $this;
    }

    /**
     * "Pin" the slide-over as a split screen on wide screens.
     */
    public function pinnable(bool $condition = true): static
    {
        $this->pinnable = $condition;

        return $this;
    }

    /**
     * Unread count in the browser tab title and favicon.
     */
    public function tabBadge(bool $condition = true): static
    {
        $this->tabBadge = $condition;

        return $this;
    }

    /**
     * Accent colour of group avatars and author names (a Filament colour name).
     */
    public function color(string $color): static
    {
        $this->color = $color;

        return $this;
    }

    public function navigationIcon(string|BackedEnum|null $icon): static
    {
        $this->navigationIcon = $icon;

        return $this;
    }

    public function navigationGroup(string|UnitEnum|null $group): static
    {
        $this->navigationGroup = $group;

        return $this;
    }

    public function navigationSort(?int $sort): static
    {
        $this->navigationSort = $sort;

        return $this;
    }

    public function slug(?string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    public function hasDock(): bool
    {
        return $this->dock;
    }

    public function isPinnable(): bool
    {
        return $this->pinnable;
    }

    public function hasTabBadge(): bool
    {
        return $this->tabBadge;
    }

    public function getNavigationIcon(): string|BackedEnum|null
    {
        return $this->navigationIcon;
    }

    public function getNavigationGroup(): string|UnitEnum|null
    {
        return $this->navigationGroup;
    }

    public function getNavigationSort(): ?int
    {
        return $this->navigationSort;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function register(Panel $panel): void
    {
        $panel->pages([Chat::class]);

        $manager = app(ChatManager::class);
        $manager->plugin = $this;
        $manager->panelId = $panel->getId();
        $manager->modifyUsersQueryUsing = $this->users;
        $manager->userNameUsing = $this->userName;
        $manager->color = $this->color;

        foreach (value($this->references) as $type) {
            $manager->references->register($type);
        }

        $panel
            // After the panel's theme, not before it as auto-loaded plugin assets are: a
            // custom theme compiles the same utilities (`.bg-white`), and with equal
            // specificity the later file wins — the theme would beat our `dark:` variants.
            ->renderHook(PanelsRenderHook::STYLES_AFTER, fn (): string => '<link rel="stylesheet" href="'
                .e(FilamentAsset::getStyleHref(FilamentChatServiceProvider::STYLESHEET, FilamentChatServiceProvider::PACKAGE)).'" />')
            ->renderHook(PanelsRenderHook::HEAD_START, fn (): string => $this->dock && $this->pinnable ? view('filament-chat::hooks.pinned')->render() : '')
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): string => $this->tabBadge ? view('filament-chat::hooks.tab-badge')->render() : '')
            ->renderHook(PanelsRenderHook::USER_MENU_BEFORE, fn (): string => view('filament-chat::hooks.dock-button', ['dock' => $this->dock])->render())
            ->renderHook(PanelsRenderHook::BODY_END, fn (): string => $this->dock ? view('filament-chat::hooks.dock-panel', ['pinnable' => $this->pinnable])->render() : '');
    }

    public function boot(Panel $panel): void {}
}
