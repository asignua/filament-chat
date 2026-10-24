<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Tests\Feature;

use Asignua\FilamentChat\Pages\Chat;
use Asignua\FilamentChat\Tests\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Right-to-left languages (Persian…): Filament sets `dir="rtl"` on the page, the chat has to follow it.
 */
class RtlTest extends TestCase
{
    /**
     * Physical `left`/`right` utilities do not follow the document direction. The views use logical ones
     * (`ms-*`, `pe-*`, `start-*`, `end-*`, `text-start`, `border-s-*`, `rounded-s-*`) — and `rtl:` for what has no
     * logical twin (a translate, an icon that points somewhere).
     */
    public function test_views_use_no_physical_left_right_utilities(): void
    {
        $offenders = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../resources/views')) as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            preg_match_all('/class="([^"]*)"|\'([^\']*)\'|"([^"]*)"/', (string) file_get_contents($file->getPathname()), $found);

            foreach (array_merge($found[1], $found[2], $found[3]) as $value) {
                foreach (preg_split('/\s+/', trim($value)) ?: [] as $token) {
                    $utility = ltrim((string) preg_replace('/^.*:/', '', $token), '-');

                    if (preg_match('/^(?:(?:m|p)(?:l|r)-|left-|right-|float-(?:left|right)$|text-(?:left|right)$|rounded-(?:l|r|tl|tr|bl|br)(?:-|$)|border-(?:l|r)(?:-|$))/', $utility)) {
                        $offenders[] = $file->getFilename().': '.$token;
                    }
                }
            }
        }

        $this->assertSame([], array_values(array_unique($offenders)), 'use logical utilities (ms/me/ps/pe/start/end/border-s/rounded-s) instead');
    }

    public function test_message_bodies_and_the_composer_decide_their_own_direction(): void
    {
        $me = $this->user();
        $colleague = $this->user();
        $conversation = $this->direct($me, $colleague);
        $this->send($conversation, $colleague, 'سلام, hello');

        $this->actingAs($me)
            ->get(Chat::urlFor($conversation))
            ->assertOk()
            ->assertSee('<div dir="auto" class="wrap-anywhere break-words">', false)
            ->assertSee('dir="auto"', false);
    }

    public function test_direction_dependent_icons_and_the_dock_transition_flip_in_rtl(): void
    {
        $window = (string) file_get_contents(__DIR__.'/../../resources/views/livewire/chat-window.blade.php');
        $dock = (string) file_get_contents(__DIR__.'/../../resources/views/hooks/dock-panel.blade.php');
        $css = (string) file_get_contents(__DIR__.'/../../resources/dist/filament-chat.css');

        $this->assertSame(4, substr_count($window, 'rtl:-scale-x-100'), 'back, paper airplane and two reply arrows');
        $this->assertStringContainsString('translate-x-full rtl:-translate-x-full', $dock);
        $this->assertStringContainsString('rtl\\:-translate-x-full', $css, 'the build must contain the rtl utilities — run npm run build');
        $this->assertStringContainsString('rtl\\:-scale-x-100', $css);
    }
}
