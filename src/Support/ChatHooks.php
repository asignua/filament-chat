<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Support;

use Asignua\FilamentChat\Enums\ChatHook;
use Asignua\FilamentChat\Livewire\ChatWindow;
use Closure;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\HtmlString;

/**
 * Markup extensions add to the chat window's named places (see ChatHook). Lives
 * in ChatManager, so it works wherever the window is mounted and survives the
 * plugin instance — an extension may register from its own service provider.
 */
final class ChatHooks
{
    /** @var array<string, list<Closure(ChatWindow, array<string, mixed>): (Htmlable|Renderable|string|null)>> */
    private array $hooks = [];

    /**
     * @param Closure(ChatWindow, array<string, mixed>): (Htmlable|Renderable|string|null) $render
     */
    public function register(ChatHook $hook, Closure $render): void
    {
        $this->hooks[$hook->value][] = $render;
    }

    public function has(ChatHook $hook): bool
    {
        return ($this->hooks[$hook->value] ?? []) !== [];
    }

    /**
     * Everything registered for the place, in registration order. A string is
     * trusted HTML (escape user data with e()), like in Filament's render hooks.
     *
     * @param array<string, mixed> $context
     */
    public function render(ChatHook $hook, ChatWindow $window, array $context = []): HtmlString
    {
        $html = '';

        foreach ($this->hooks[$hook->value] ?? [] as $render) {
            $result = $render($window, $context);

            $html .= match (true) {
                $result instanceof Htmlable => $result->toHtml(),
                $result instanceof Renderable => $result->render(),
                default => (string) $result,
            };
        }

        return new HtmlString($html);
    }
}
