<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Data;

use Asignua\FilamentChat\Support\ChatManager;

/**
 * A message payload: text and, optionally, a registered record it is about.
 * Half a reference (a type without an id or an unknown type) is dropped whole.
 * `allowEmpty` — the body may be empty without a reference (an extension adds the
 * content some other way, e.g. attachments).
 */
final readonly class MessageData
{
    public function __construct(
        public string $body,
        public ?string $referenceType = null,
        public ?int $referenceId = null,
        public ?string $replyTo = null,
        public bool $allowEmpty = false,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $body = $data['body'] ?? null;
        $type = $data['reference_type'] ?? null;
        $id = $data['reference_id'] ?? null;
        $replyTo = $data['reply_to'] ?? null;

        $type = is_string($type) && app(ChatManager::class)->references->get($type) !== null ? $type : null;
        $id = is_numeric($id) && (int) $id > 0 ? (int) $id : null;
        $complete = $type !== null && $id !== null;

        return new self(
            body: is_string($body) ? trim($body) : '',
            referenceType: $complete ? $type : null,
            referenceId: $complete ? $id : null,
            replyTo: is_string($replyTo) && $replyTo !== '' ? $replyTo : null,
            allowEmpty: ($data['allow_empty'] ?? false) === true,
        );
    }
}
