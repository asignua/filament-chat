<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Support;

use Asignua\FilamentChat\FilamentChatPlugin;
use Asignua\FilamentChat\Support\References\ReferenceRegistry;
use Closure;
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

    public ?FilamentChatPlugin $plugin = null;

    public ?string $panelId = null;

    public string $color = 'primary';

    public function __construct(public readonly ReferenceRegistry $references) {}
}
