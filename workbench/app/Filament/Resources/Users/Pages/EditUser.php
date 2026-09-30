<?php

declare(strict_types=1);

namespace Workbench\App\Filament\Resources\Users\Pages;

use Filament\Resources\Pages\EditRecord;
use Workbench\App\Filament\Resources\Users\UserResource;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;
}
