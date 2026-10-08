<?php

declare(strict_types=1);

namespace Asignua\FilamentChat;

use Asignua\FilamentChat\Enums\ChatHook;
use Asignua\FilamentChat\Livewire\ChatWindow;
use Asignua\FilamentChat\Pages\Chat;
use Asignua\FilamentChat\Support\ChatConfig;
use Asignua\FilamentChat\Support\ChatManager;
use Asignua\FilamentChat\Support\References\AllResources;
use Asignua\FilamentChat\Support\References\ReferenceType;
use BackedEnum;
use Closure;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\Support\Facades\FilamentAsset;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use UnitEnum;

/**
 * Team chat for a Filament panel.
 *
 *     ->plugin(FilamentChatPlugin::make()
 *         ->users(fn (Builder $query) => $query->where('is_active', true))
 *         ->references([ReferenceType::resource(OrderResource::class)]))
 *
 * Every setter overrides the matching key of config/filament-chat.php; what is
 * not set here comes from the config. Closures live here only (config must stay
 * cacheable).
 */
class FilamentChatPlugin implements Plugin
{
    public const string ID = 'filament-chat';

    /** @var (Closure(Builder<Model>): mixed)|null */
    protected ?Closure $users = null;

    /** @var (Closure(Model): string)|null */
    protected ?Closure $userName = null;

    /** @var (Closure(Model): ?string)|null */
    protected ?Closure $avatarUrl = null;

    /** @var (Closure(): list<AllResources|ReferenceType>)|list<AllResources|ReferenceType> */
    protected array|Closure $references = [];

    protected string|BackedEnum|Closure|null $navigationIcon = null;

    protected string|UnitEnum|Closure|null $navigationGroup = null;

    /**
     * Config overrides collected by the setters: dotted key under `filament-chat.` → value.
     *
     * @var array<string, mixed>
     */
    protected array $config = [];

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
     * How a person is called in the chat. Default: users.name_attribute, then
     * Filament's HasName, then `name`.
     *
     * @param Closure(Model): string $name
     */
    public function userName(Closure $name): static
    {
        $this->userName = $name;

        return $this;
    }

    /**
     * Record types a message can point at: explicit types and/or
     * ReferenceType::allResources()->except([...]).
     *
     * @param (Closure(): list<AllResources|ReferenceType>)|list<AllResources|ReferenceType> $references
     */
    public function references(array|Closure $references): static
    {
        $this->references = $references;

        return $this;
    }

    /**
     * Who may see a referenced record: 'policy', 'resource' or false.
     */
    public function authorizeReferences(string|false $mode): static
    {
        return $this->set('references.authorize', $mode);
    }

    public function groups(bool $condition = true): static
    {
        return $this->set('features.groups', $condition);
    }

    public function reactions(bool $condition = true): static
    {
        return $this->set('features.reactions', $condition);
    }

    public function mentions(bool $condition = true): static
    {
        return $this->set('features.mentions', $condition);
    }

    public function readReceipts(bool $condition = true): static
    {
        return $this->set('features.read_receipts', $condition);
    }

    public function avatars(bool $condition = true): static
    {
        return $this->set('features.avatars', $condition);
    }

    public function replies(bool $condition = true): static
    {
        return $this->set('features.replies', $condition);
    }

    /**
     * A person's avatar URL; null — initials. Default: Filament's avatar provider.
     *
     * @param Closure(Model): ?string $url
     */
    public function avatarUsing(Closure $url): static
    {
        $this->avatarUrl = $url;

        return $this;
    }

    /**
     * Authors may edit their messages; `$window` — minutes after sending (null — any time).
     */
    public function editing(bool $condition = true, ?int $window = null): static
    {
        return $this->set('features.editing.enabled', $condition)->set('features.editing.window', $window);
    }

    /**
     * Real-time delivery over a websocket (true) or polling (false).
     */
    public function realtime(bool $condition = true): static
    {
        return $this->set('realtime.enabled', $condition);
    }

    /**
     * The top-bar button with a slide-over.
     */
    public function dock(bool $condition = true): static
    {
        return $this->set('ui.dock', $condition);
    }

    /**
     * "Pin" the slide-over as a split screen on wide screens.
     */
    public function pinnable(bool $condition = true): static
    {
        return $this->set('ui.pinnable', $condition);
    }

    /**
     * Unread count in the browser tab title and favicon.
     */
    public function tabBadge(bool $condition = true): static
    {
        return $this->set('ui.tab_badge', $condition);
    }

    /**
     * Accent colour of group avatars and author names (a Filament colour name).
     */
    public function color(string $color): static
    {
        return $this->set('ui.color', $color);
    }

    public function slug(string $slug): static
    {
        return $this->set('ui.slug', $slug);
    }

    /**
     * Mount your own subclass of ChatWindow everywhere the window appears (the page and the
     * slide-over) — the way an extension overrides canSendWithoutBody(), modifyMessagesQuery() …
     *
     * @param string $component a ChatWindow subclass
     */
    public function windowComponent(string $component): static
    {
        if (!is_a($component, ChatWindow::class, true)) {
            throw new InvalidArgumentException($component.' must be '.ChatWindow::class.' or a subclass of it.');
        }

        return $this->set('ui.window_component', $component);
    }

    /**
     * Add markup to a named place of the chat window. The closure gets the ChatWindow component
     * (so `wire:click` in the markup reaches its methods) and a context array — `conversation`
     * and, for the message hooks, `message` and `mine`; it returns Htmlable, a View, an HTML
     * string (trusted — escape with e()) or null. Several closures per place run in order.
     *
     * @param Closure(ChatWindow, array<string, mixed>): (Htmlable|Renderable|string|null) $render
     */
    public function renderHook(ChatHook $hook, Closure $render): static
    {
        app(ChatManager::class)->hooks->register($hook, $render);

        return $this;
    }

    public function navigationIcon(string|BackedEnum|Closure|null $icon): static
    {
        $this->navigationIcon = $icon;

        return $this;
    }

    /**
     * A closure is resolved per request — e.g. a translated group name.
     */
    public function navigationGroup(string|UnitEnum|Closure|null $group): static
    {
        $this->navigationGroup = $group;

        return $this;
    }

    public function navigationSort(?int $sort): static
    {
        return $this->set('ui.navigation.sort', $sort);
    }

    public function getNavigationIcon(): string|BackedEnum|null
    {
        $icon = value($this->navigationIcon);

        return is_string($icon) || $icon instanceof BackedEnum ? $icon : ChatConfig::navigationIcon();
    }

    public function getNavigationGroup(): string|UnitEnum|null
    {
        $group = value($this->navigationGroup);

        return is_string($group) || $group instanceof UnitEnum ? $group : ChatConfig::navigationGroup();
    }

    public function register(Panel $panel): void
    {
        foreach ($this->config as $key => $value) {
            config(['filament-chat.'.$key => $value]);
        }

        $panel->pages([Chat::class]);

        $manager = app(ChatManager::class);
        $manager->plugin = $this;
        $manager->panelId = $panel->getId();
        $manager->modifyUsersQueryUsing = $this->users;
        $manager->userNameUsing = $this->userName;
        $manager->avatarUsing = $this->avatarUrl;

        foreach (value($this->references) as $type) {
            $manager->references->register($type);
        }

        $panel
            // After the panel's theme, not before it as auto-loaded plugin assets are: a
            // custom theme compiles the same utilities (`.bg-white`), and with equal
            // specificity the later file wins — the theme would beat our `dark:` variants.
            // Ours sit under `.fchat-scope`, so being later cannot hurt the host's own pages.
            ->renderHook(PanelsRenderHook::STYLES_AFTER, fn (): string => '<link rel="stylesheet" href="'
                .e(FilamentAsset::getStyleHref(FilamentChatServiceProvider::STYLESHEET, FilamentChatServiceProvider::PACKAGE)).'" />')
            ->renderHook(PanelsRenderHook::HEAD_START, fn (): string => ChatConfig::dock() && ChatConfig::pinnable() ? view('filament-chat::hooks.pinned')->render() : '')
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): string => ChatConfig::tabBadge() ? view('filament-chat::hooks.tab-badge')->render() : '')
            ->renderHook(PanelsRenderHook::SCRIPTS_AFTER, fn (): string => view('filament-chat::hooks.echo')->render())
            ->renderHook(PanelsRenderHook::USER_MENU_BEFORE, fn (): string => view('filament-chat::hooks.dock-button', ['dock' => ChatConfig::dock()])->render())
            ->renderHook(PanelsRenderHook::BODY_END, fn (): string => ChatConfig::dock() ? view('filament-chat::hooks.dock-panel', ['pinnable' => ChatConfig::pinnable()])->render() : '');
    }

    public function boot(Panel $panel): void {}

    protected function set(string $key, mixed $value): static
    {
        $this->config[$key] = $value;

        return $this;
    }
}
