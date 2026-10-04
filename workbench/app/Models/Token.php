<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A model with a UUID key — references cannot point at it (reference_id is an integer).
 *
 * @property string $id
 */
class Token extends Model
{
    use HasUuids;

    protected $guarded = [];
}
