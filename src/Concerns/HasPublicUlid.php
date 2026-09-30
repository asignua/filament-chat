<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Hybrid keys: the bigint `id` stays internal (joins, foreign keys) and the
 * `ulid` column is what leaves the server — route keys, Livewire state,
 * websocket payloads.
 *
 * @mixin Model
 */
trait HasPublicUlid
{
    public static function bootHasPublicUlid(): void
    {
        static::creating(function (Model $model): void {
            if (empty($model->getAttribute('ulid'))) {
                $model->setAttribute('ulid', (string) Str::ulid());
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }
}
