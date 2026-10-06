<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Support;

use Asignua\FilamentChat\FilamentChatPlugin;
use Asignua\FilamentChat\Support\References\ReferenceRegistry;
use Closure;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Runtime settings the plugin hands over when its panel registers — they are
 * also needed outside a panel request (broadcast auth, notifications built
 * in a service), so they live in a container singleton rather than on the
 * plugin instance.
 */
final class ChatManager
{
    /** @var (Closure(Builder<Model>): mixed)|null */
    public ?Closure $modifyUsersQueryUsing = null;

    /** @var (Closure(Model): string)|null */
    public ?Closure $userNameUsing = null;

    /** @var (Closure(Model): ?string)|null */
    public ?Closure $avatarUsing = null;

    public ?FilamentChatPlugin $plugin = null;

    public ?string $panelId = null;

    /**
     * Whether the messages table has `reply_to_id` — checked once per
     * application instance (see ChatConfig::repliesAvailable()); null — not yet.
     */
    public ?bool $replyColumn = null;

    public readonly ChatHooks $hooks;

    public function __construct(public readonly ReferenceRegistry $references)
    {
        $this->hooks = new ChatHooks;
    }

    /**
     * The panel the plugin is registered on; without it — the current one.
     */
    public function panel(): ?Panel
    {
        return $this->panelId !== null
            ? Filament::getPanel($this->panelId, isStrict: false)
            : Filament::getCurrentOrDefaultPanel();
    }
}
