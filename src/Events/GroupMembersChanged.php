<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Events;

use Asignua\FilamentChat\Models\Conversation;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A group was created, its members were changed, or someone left. Fired inside the
 * write transaction — listen to it to keep your own audit trail.
 */
class GroupMembersChanged
{
    use Dispatchable;

    /**
     * @param list<string> $before member names, alphabetical
     * @param list<string> $after  member names, alphabetical
     */
    public function __construct(
        public readonly Conversation $conversation,
        public readonly array $before,
        public readonly array $after,
    ) {}
}
