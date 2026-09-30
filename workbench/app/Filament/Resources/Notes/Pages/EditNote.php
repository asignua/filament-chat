<?php

declare(strict_types=1);

namespace Workbench\App\Filament\Resources\Notes\Pages;

use Asignua\FilamentChat\Actions\DiscussInChatAction;
use Filament\Resources\Pages\EditRecord;
use Workbench\App\Filament\Resources\Notes\NoteResource;

class EditNote extends EditRecord
{
    protected static string $resource = NoteResource::class;

    protected function getHeaderActions(): array
    {
        return [DiscussInChatAction::make()];
    }
}
