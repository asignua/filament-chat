<?php

declare(strict_types=1);

namespace Workbench\App\Filament\Resources\Tokens;

use Filament\Resources\Resource;
use Workbench\App\Models\Token;

/**
 * A resource of a UUID-keyed model: "all resources" must leave it out.
 */
class TokenResource extends Resource
{
    protected static ?string $model = Token::class;

    public static function getPages(): array
    {
        return [];
    }
}
