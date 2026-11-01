<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A tenant of the workbench panel (only used when WORKBENCH_TENANCY is set, and by the tenancy tests).
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 */
class Team extends Model
{
    protected $guarded = [];

    /**
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }
}
