<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * A direct conversation (exactly two people) or a group (a title and members).
 */
enum ConversationType: string implements HasLabel
{
    case Direct = 'direct';
    case Group = 'group';

    public function getLabel(): string
    {
        return __('filament-chat::chat.type_'.$this->value);
    }
}
