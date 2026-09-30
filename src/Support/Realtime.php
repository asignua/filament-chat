<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Support;

use Asignua\FilamentChat\Events\ChatUpdated;
use Illuminate\Database\Eloquent\Model;

/**
 * Livewire listener of a person's private chat channel.
 */
final class Realtime
{
    public static function listener(Model $user): string
    {
        return 'echo-private:'.ChatUpdated::channelFor(ChatUsers::broadcastKey($user)).',.'.ChatUpdated::NAME;
    }
}
