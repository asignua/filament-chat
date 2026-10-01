<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Support\References;

use Asignua\FilamentChat\Support\ChatConfig;
use Asignua\FilamentChat\Support\ChatManager;
use BackedEnum;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;

/**
 * The record types messages can point at, registered by the plugin
 * (`->references([...])`) or `filament-chat.references.all_resources`.
 * Empty — the chat simply has no attachments.
 *
 * "All resources" is expanded lazily, on first use: the panel may still be
 * collecting its resources when the plugin registers.
 */
final class ReferenceRegistry
{
    /** @var array<string, ReferenceType> */
    private array $types = [];

    private ?AllResources $all = null;

    private bool $expanded = false;

    public function register(ReferenceType|AllResources $type): void
    {
        if ($type instanceof AllResources) {
            $this->all = $type;
            $this->expanded = false;

            return;
        }

        $this->types[$type->getKey()] = $type;
    }

    public function isEmpty(): bool
    {
        return $this->all() === [];
    }

    /**
     * @return array<string, ReferenceType>
     */
    public function all(): array
    {
        if (!ChatConfig::referencesEnabled()) {
            return [];
        }

        $this->expand();

        return $this->types;
    }

    public function get(?string $key): ?ReferenceType
    {
        return $key !== null ? ($this->all()[$key] ?? null) : null;
    }

    /**
     * The type of a record; the most specific class wins (a subclass registered
     * separately beats its parent).
     */
    public function forRecord(Model $record): ?ReferenceType
    {
        $found = null;

        foreach ($this->all() as $type) {
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
        // A type registered without a resource (ReferenceType::make) matches every resource
        // of its model — e.g. one "geography" type for country, region and city resources.
        $model = is_subclass_of($resource, Resource::class) ? $resource::getModel() : null;

        return array_filter($this->all(), fn (ReferenceType $type): bool => $type->getResource() === $resource
            || ($type->getResource() === null && $type->getModel() === $model));
    }

    /**
     * @return array<string, string>
     */
    public function options(): array
    {
        return array_map(fn (ReferenceType $type): string => $type->getLabel(), $this->all());
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
        $visible = $record !== null && $type->canView($record);

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

    /**
     * Adds a type per panel resource (config `references.all_resources` or
     * AllResources from the plugin); explicit types and their models win.
     */
    private function expand(): void
    {
        if ($this->expanded) {
            return;
        }

        $this->expanded = true;
        $all = $this->all ?? (ChatConfig::allResources() ? AllResources::make() : null);

        if ($all === null) {
            return;
        }

        $panel = app(ChatManager::class)->panel();

        if ($panel === null) {
            return;
        }

        $except = [...$all->getExcept(), ...ChatConfig::exceptResources()];
        $models = array_map(fn (ReferenceType $type): string => $type->getModel(), $this->types);

        foreach ($panel->getResources() as $resource) {
            if (!is_subclass_of($resource, Resource::class)) {
                continue;
            }

            $model = $resource::getModel();
            $key = $resource::getSlug($panel);

            if (in_array($resource, $except, true) || in_array($model, $models, true) || isset($this->types[$key])) {
                continue;
            }

            $this->types[$key] = ReferenceType::resource($resource, $key);
        }
    }
}
