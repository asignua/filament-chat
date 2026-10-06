<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Asignua\FilamentChat\Models\Message;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * What an extension's message model looks like: the stock one plus soft deletes.
 */
class SoftMessage extends Message
{
    use SoftDeletes;
}
