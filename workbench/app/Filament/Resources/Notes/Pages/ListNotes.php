<?php

declare(strict_types=1);

namespace Workbench\App\Filament\Resources\Notes\Pages;

use Filament\Resources\Pages\ListRecords;
use Workbench\App\Filament\Resources\Notes\NoteResource;

class ListNotes extends ListRecords
{
    protected static string $resource = NoteResource::class;
}
