<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Support\References;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * The record types messages can point at, registered by the plugin
 * (`->references([...])`). Empty — the chat simply has no attachments.
 */
final class ReferenceRegistry
{
    /** @var array<string, ReferenceType> */
    private array $types = [];

    public function register(ReferenceType $type): void
    {
        $this->types[$type->getKey()] = $type;
    }

    public function isEmpty(): bool
    {
        return $this->types === [];
    }

    /**
     * @return array<string, ReferenceType>
     */
    public function all(): array
    {
        return $this->types;
    }

    public function get(?string $key): ?ReferenceType
    {
        return $key !== null ? ($this->types[$key] ?? null) : null;
    }

    /**
     * The type of a record; the most specific class wins (a subclass registered
     * separately beats its parent).
     */
    public function forRecord(Model $record): ?ReferenceType
    {
        $found = null;

        foreach ($this->types as $type) {
            if ($type->matches($record) && ($found === null || is_subclass_of($type->getModel(), $found->getModel()))) {
                $found = $type;
            }
        }

        return $found;
    }

    /**
     * @return array<string, ReferenceType>
     */
    public function forResource(string $resource): array
    {
        return array_filter($this->types, fn (ReferenceType $type): bool => $type->getResource() === $resource);
    }

    /**
     * @return array<string, string>
     */
    public function options(): array
    {
        return array_map(fn (ReferenceType $type): string => $type->getLabel(), $this->types);
    }

    /**
     * How a reference looks to the current viewer: a deleted record keeps only
     * its type, a record they may not view — the type without title or link.
     *
     * @return array{type: string, label: string, url: string|null, icon: BackedEnum|string, color: string}|null
     */
    public function present(?string $key, int|string|null $id): ?array
    {
        $type = $this->get($key);

        if ($type === null || $id === null) {
            return null;
        }

        $record = $type->find($id);
        $visible = $record !== null && Gate::allows('view', $record);

        return [
            'type' => $type->getLabel(),
            'label' => match (true) {
                $record === null => __('filament-chat::chat.reference_deleted'),
                $visible => $type->getTitle($record),
                default => __('filament-chat::chat.reference_hidden'),
            },
            'url' => $visible ? $type->getUrl($record) : null,
            'icon' => $type->getIcon(),
            'color' => $type->getColor(),
        ];
    }
}
