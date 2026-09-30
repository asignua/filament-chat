<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Support;

use Carbon\CarbonInterface;
use Filament\Support\Facades\FilamentTimezone;

/**
 * Message times in the panel's timezone (FilamentTimezone), formats from
 * the translation file.
 */
final class ChatTime
{
    public static function time(?CarbonInterface $moment): string
    {
        return self::format($moment, 'format_time');
    }

    /**
     * Today — the time, otherwise the short date (the conversation list).
     */
    public static function short(?CarbonInterface $moment): string
    {
        if ($moment === null) {
            return '';
        }

        return self::local($moment)->isToday() ? self::time($moment) : self::format($moment, 'format_day');
    }

    public static function date(?CarbonInterface $moment): string
    {
        return self::format($moment, 'format_date');
    }

    private static function format(?CarbonInterface $moment, string $key): string
    {
        return $moment !== null ? self::local($moment)->translatedFormat(__('filament-chat::chat.'.$key)) : '';
    }

    private static function local(CarbonInterface $moment): CarbonInterface
    {
        return $moment->copy()->setTimezone(FilamentTimezone::get());
    }
}
