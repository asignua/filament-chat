<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Events;

use Asignua\FilamentChat\Models\Message;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A message was stored by ChatService::send() — a plain Laravel event (not
 * broadcast: ChatUpdated is the socket's), dispatched once the transaction
 * has committed. For extensions: indexing, attachments, webhooks.
 */
class MessageSent
{
    use Dispatchable;

    public function __construct(public readonly Message $message) {}
}
