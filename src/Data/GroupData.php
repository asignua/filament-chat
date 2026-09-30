<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Data;

/**
 * A group payload: title and member keys. The creator is added by the
 * repository — the form may well leave them out.
 */
final readonly class GroupData
{
    /**
     * @param list<int> $memberIds
     */
    public function __construct(
        public string $title,
        public array $memberIds,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $title = $data['title'] ?? null;
        $members = $data['member_ids'] ?? [];

        return new self(
            title: is_string($title) ? trim($title) : '',
            memberIds: is_array($members)
                ? array_values(array_unique(array_map(intval(...), array_filter($members, is_numeric(...)))))
                : [],
        );
    }
}
