<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A record type messages can point at.
 *
 * @property int $id
 * @property string $title
 * @property int|null $owner_id
 */
class Note extends Model
{
    protected $guarded = [];
}
