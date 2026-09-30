<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * A reaction to a message — a fixed set of six, in display order.
 */
enum Reaction: string implements HasLabel
{
    case Heart = 'heart';
    case Like = 'like';
    case Laugh = 'laugh';
    case Wow = 'wow';
    case Sad = 'sad';
    case Pray = 'pray';

    public function emoji(): string
    {
        return match ($this) {
            self::Heart => '❤️',
            self::Like => '👍',
            self::Laugh => '😂',
            self::Wow => '😮',
            self::Sad => '😢',
            self::Pray => '🙏',
        };
    }

    public function getLabel(): string
    {
        return __('filament-chat::chat.reaction_'.$this->value);
    }
}
