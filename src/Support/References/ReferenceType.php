<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Support\References;

use Asignua\FilamentChat\Support\ChatConfig;
use BackedEnum;
use Closure;
use Filament\Models\Contracts\HasName;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * A kind of panel record a message can point at ("a deal", "a task"…).
 *
 * The `key` is what is stored in `reference_type` — keep it stable: renaming
 * a model class must not break old messages. The quickest way to register one
 * is from its resource: `ReferenceType::resource(DealResource::class)`.
 */
final class ReferenceType
{
    /** @var class-string<resource>|null */
    private ?string $resource = null;

    private string|Closure|null $label = null;

    private string|BackedEnum|Closure|null $icon = null;

    private string|Closure|null $color = null;

    /** @var (Closure(Model): (Htmlable|string|null))|null */
    private ?Closure $title = null;

    /** @var (Closure(Model): (string|null))|null */
    private ?Closure $url = null;

    /** @var (Closure(string): iterable<Model>)|null */
    private ?Closure $search = null;

    /** @var list<string>|null */
    private ?array $searchColumns = null;

    /** @var (Closure(Model): bool)|null */
    private ?Closure $visible = null;

    /**
     * @param class-string<Model> $model
     */
    private function __construct(
        private readonly string $key,
        private readonly string $model,
    ) {}

    /**
     * @param class-string<Model> $model
     */
    public static function make(string $key, string $model): self
    {
        return new self($key, $model);
    }

    /**
     * Every resource of the panel, apart from the excluded ones.
     */
    public static function allResources(): AllResources
    {
        return AllResources::make();
    }

    /**
     * Label, icon, title, card URL and search — all from the resource.
     *
     * @param class-string<resource> $resource
     */
    public static function resource(string $resource, ?string $key = null): self
    {
        /** @var class-string<Model> $model */
        $model = $resource::getModel();

        $type = new self($key ?? Str::snake(class_basename($model)), $model);
        $type->resource = $resource;

        return $type;
    }

    public function label(string|Closure|null $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function icon(string|BackedEnum|Closure|null $icon): self
    {
        $this->icon = $icon;

        return $this;
    }

    /**
     * A Filament colour name (`primary`, `warning`, `fuchsia`…).
     */
    public function color(string|Closure|null $color): self
    {
        $this->color = $color;

        return $this;
    }

    /**
     * @param Closure(Model): (Htmlable|string|null) $title
     */
    public function title(Closure $title): self
    {
        $this->title = $title;

        return $this;
    }

    /**
     * Where the reference leads; null — no link. Called only for records the
     * viewer may `view`.
     *
     * @param Closure(Model): (string|null) $url
     */
    public function url(Closure $url): self
    {
        $this->url = $url;

        return $this;
    }

    /**
     * This type's own rule of who may see a record — overrides
     * `filament-chat.references.authorize`.
     *
     * @param Closure(Model): bool $visible
     */
    public function visibleUsing(Closure $visible): self
    {
        $this->visible = $visible;

        return $this;
    }

    /**
     * Custom search for the "attach a record" picker.
     *
     * @param Closure(string): iterable<Model> $search
     */
    public function search(Closure $search): self
    {
        $this->search = $search;

        return $this;
    }

    /**
     * Columns the default search looks in (case-insensitive `like`).
     *
     * @param list<string> $columns
     */
    public function searchColumns(array $columns): self
    {
        $this->searchColumns = $columns;

        return $this;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    /**
     * @return class-string<Model>
     */
    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * @return class-string<resource>|null
     */
    public function getResource(): ?string
    {
        return $this->resource;
    }

    public function matches(Model $record): bool
    {
        return $record instanceof $this->model;
    }

    public function getLabel(): string
    {
        if ($this->label !== null) {
            return (string) value($this->label);
        }

        if ($this->resource !== null) {
            return Str::ucfirst($this->resource::getModelLabel());
        }

        return Str::headline(class_basename($this->model));
    }

    public function getIcon(): string|BackedEnum
    {
        $icon = value($this->icon);

        if ($icon === null && $this->resource !== null) {
            $icon = $this->resource::getNavigationIcon();
        }

        return is_string($icon) || $icon instanceof BackedEnum ? $icon : Heroicon::OutlinedLink;
    }

    public function getColor(): string
    {
        $color = value($this->color);

        return is_string($color) && $color !== '' ? $color : 'gray';
    }

    public function getTitle(Model $record): string
    {
        $title = match (true) {
            $this->title !== null => ($this->title)($record),
            // A resource without $recordTitleAttribute titles every record with its model label
            // ("user") — useless on a card, so fall back to the record's own name.
            $this->resource !== null && $this->resource::getRecordTitleAttribute() !== null => $this->resource::getRecordTitle($record),
            $record instanceof HasName => $record->getFilamentName(),
            default => $record->getAttribute('title') ?? $record->getAttribute('name'),
        };

        if ($title instanceof Htmlable) {
            $title = strip_tags($title->toHtml());
        }

        return is_scalar($title) && (string) $title !== '' ? (string) $title : '#'.$record->getKey();
    }

    public function getUrl(Model $record): ?string
    {
        if ($this->url !== null) {
            return ($this->url)($record);
        }

        if ($this->resource === null) {
            return null;
        }

        $resource = $this->resource;

        // The edit page for those who may edit, the view page for the rest.
        if ($resource::hasPage('edit') && $resource::canEdit($record)) {
            return $resource::getUrl('edit', ['record' => $record]);
        }

        if ($resource::hasPage('view') && $resource::canView($record)) {
            return $resource::getUrl('view', ['record' => $record]);
        }

        return null;
    }

    /**
     * May the current user see this record (its title and link)?
     */
    public function canView(Model $record): bool
    {
        if ($this->visible !== null) {
            return (bool) ($this->visible)($record);
        }

        return match (ChatConfig::referenceAuthorization()) {
            'resource' => $this->resource !== null
                ? $this->resource::canView($record)
                : Gate::getPolicyFor($record) === null || Gate::allows('view', $record),
            'policy' => Gate::allows('view', $record),
            default => true,
        };
    }

    public function find(int|string $id): ?Model
    {
        $model = $this->model;

        return $model::query()->find($id);
    }

    /**
     * Several records in one query, keyed by their primary key.
     *
     * @param list<int|string> $ids
     *
     * @return Collection<int|string, Model>
     */
    public function findMany(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        $model = $this->model;

        return $model::query()->whereKey($ids)->get()->keyBy(fn (Model $record): int|string => $record->getKey());
    }

    /**
     * @return Collection<int, Model>
     */
    public function searchRecords(string $search, int $limit = 20): Collection
    {
        if ($this->search !== null) {
            return collect(($this->search)($search))->take($limit)->values();
        }

        $columns = $this->searchColumns
            ?? ($this->resource !== null ? $this->resource::getGloballySearchableAttributes() : [])
            ?: ['name'];
        $columns = array_values(array_filter($columns, fn (string $column): bool => !str_contains($column, '.')));
        $words = preg_split('/\s+/u', trim($search), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($columns === [] || $words === []) {
            return collect();
        }

        $model = $this->model;
        $query = $this->resource !== null ? $this->resource::getGlobalSearchEloquentQuery() : $model::query();

        foreach ($words as $word) {
            $query->where(function (Builder $any) use ($columns, $word): void {
                foreach ($columns as $column) {
                    $any->orWhereLike($any->getModel()->qualifyColumn($column), '%'.$word.'%');
                }
            });
        }

        return $query->limit($limit)->get();
    }
}
