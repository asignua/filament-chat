<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Tests\Feature;

use Asignua\FilamentChat\Support\ChatTime;
use Asignua\FilamentChat\Tests\TestCase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;

class TranslationsTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function locales(): array
    {
        $locales = [];

        foreach (glob(dirname(__DIR__, 2).'/resources/lang/*/chat.php') ?: [] as $file) {
            $locale = basename(dirname($file));
            $locales[$locale] = [$locale];
        }

        return $locales;
    }

    #[DataProvider('locales')]
    public function test_every_locale_has_the_english_keys_and_placeholders(string $locale): void
    {
        $english = $this->strings('en');
        $strings = $this->strings($locale);

        $this->assertSame([], array_values(array_diff(array_keys($english), array_keys($strings))), 'missing keys');
        $this->assertSame([], array_values(array_diff(array_keys($strings), array_keys($english))), 'extra keys');

        foreach ($english as $key => $text) {
            $this->assertNotSame('', trim($strings[$key]), $key);
            $this->assertSame($this->placeholders($text), $this->placeholders($strings[$key]), $key);
        }
    }

    #[DataProvider('locales')]
    public function test_dates_render_in_every_locale(string $locale): void
    {
        app()->setLocale($locale);
        Carbon::setLocale($locale);

        $this->assertStringContainsString('2026', ChatTime::date(Carbon::create(2026, 10, 2, 9, 30)));
    }

    /**
     * @return array<string, string>
     */
    private function strings(string $locale): array
    {
        /** @var array<string, string> $strings */
        $strings = require dirname(__DIR__, 2)."/resources/lang/{$locale}/chat.php";

        return $strings;
    }

    /**
     * @return list<string>
     */
    private function placeholders(string $text): array
    {
        preg_match_all('/:[a-z_]+/', $text, $matches);
        $found = $matches[0];
        sort($found);

        return $found;
    }
}
