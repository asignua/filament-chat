<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Support\References;

use Filament\Resources\Resource;

/**
 * "Every resource of the panel can be referenced" — for `->references([...])`,
 * next to explicit types (those win). The key of each type is the resource
 * slug, so renaming a slug loses the link of old messages (they show
 * "record deleted"); register a type explicitly where that matters.
 */
final class AllResources
{
    /** @var list<class-string<resource>> */
    private array $except = [];

    public static function make(): self
    {
        return new self;
    }

    /**
     * @param list<class-string<resource>> $resources
     */
    public function except(array $resources): self
    {
        $this->except = array_values(array_unique([...$this->except, ...$resources]));

        return $this;
    }

    /**
     * @return list<class-string<resource>>
     */
    public function getExcept(): array
    {
        return $this->except;
    }
}
