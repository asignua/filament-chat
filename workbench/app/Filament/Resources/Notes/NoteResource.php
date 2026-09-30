<?php

declare(strict_types=1);

namespace Workbench\App\Filament\Resources\Notes;

use Asignua\FilamentChat\Actions\DiscussInChatAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Workbench\App\Filament\Resources\Notes\Pages\EditNote;
use Workbench\App\Filament\Resources\Notes\Pages\ListNotes;
use Workbench\App\Models\Note;

class NoteResource extends Resource
{
    protected static ?string $model = Note::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('title')->required()]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([TextColumn::make('title')->searchable()])
            ->recordActions([EditAction::make(), DiscussInChatAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNotes::route('/'),
            'edit' => EditNote::route('/{record}/edit'),
        ];
    }
}
