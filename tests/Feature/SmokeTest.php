<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Tests\Feature;

use Asignua\FilamentChat\Tests\TestCase;

class SmokeTest extends TestCase
{
    public function test_the_chat_page_renders_with_the_dock(): void
    {
        $this->actingAs($this->user())
            ->get('/admin/chat')
            ->assertOk()
            ->assertSee(__('filament-chat::chat.pick'));
    }

    public function test_the_dock_is_on_other_pages(): void
    {
        $this->actingAs($this->user())
            ->get('/admin/notes')
            ->assertOk()
            ->assertSee('filament-chat-toggle', false)
            ->assertSee('filament-chat.css', false);
    }
}
