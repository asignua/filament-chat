<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Support;

use Carbon\CarbonInterface;

/**
 * One person's presence as the viewer may see it: online right now, or last seen at a moment.
 */
final readonly class PresenceStatus
{
    public function __construct(
        public bool $online,
        public ?CarbonInterface $lastSeen,
    ) {}

    /**
     * "Online" / "Last seen 5 minutes ago"; null — never seen.
     */
    public function label(): ?string
    {
        if ($this->online) {
            return (string) __('filament-chat::chat.online');
        }

        return $this->lastSeen !== null
            ? (string) __('filament-chat::chat.last_seen', ['time' => $this->lastSeen->diffForHumans()])
            : null;
    }
}
