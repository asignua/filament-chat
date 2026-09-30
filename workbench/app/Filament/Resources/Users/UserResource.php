<?php

declare(strict_types=1);

namespace Workbench\App\Filament\Resources\Users;

use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Workbench\App\Filament\Resources\Users\Pages\EditUser;
use Workbench\App\Filament\Resources\Users\Pages\ListUsers;
use Workbench\App\Models\User;

/**
 * A resource that is NOT registered for references — unless "all resources" is on.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('name')->required()]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
