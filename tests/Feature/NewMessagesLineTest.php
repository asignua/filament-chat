<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Tests\Feature;

use Asignua\FilamentChat\Livewire\ChatWindow;
use Asignua\FilamentChat\Tests\TestCase;
use Livewire\Livewire;

class NewMessagesLineTest extends TestCase
{
    public function test_the_line_stands_before_the_first_unread_message(): void
    {
        $me = $this->user();
        $olga = $this->user();
        $direct = $this->direct($me, $olga);
        $this->send($direct, $olga, 'Earlier text');
        $this->actingAs($me);
        Livewire::test(ChatWindow::class)->call('open', $direct->ulid); // reads "Earlier text"
        $first = $this->send($direct, $olga, 'First fresh');
        $this->send($direct, $olga, 'Second fresh');

        // Distinct words: short ones ("old") also occur inside CSS classes ("font-semibold").
        Livewire::test(ChatWindow::class)
            ->call('open', $direct->ulid)
            ->assertSet('unreadMarker', $first->id)
            ->assertSeeHtmlInOrder(['Earlier text', 'data-fchat-unread', 'First fresh', 'Second fresh'])
            ->assertDispatched(ChatWindow::EVENT_SCROLL, unread: true);
    }

    public function test_no_line_when_everything_is_read(): void
    {
        $me = $this->user();
        $olga = $this->user();
        $direct = $this->direct($me, $olga);
        $this->send($direct, $olga, 'hi');
        $this->actingAs($me);
        Livewire::test(ChatWindow::class)->call('open', $direct->ulid);

        Livewire::test(ChatWindow::class)
            ->call('open', $direct->ulid)
            ->assertSet('unreadMarker', null)
            ->assertDontSeeHtml('data-fchat-unread');
    }

    public function test_own_messages_do_not_count_and_sending_clears_the_line(): void
    {
        $me = $this->user();
        $olga = $this->user();
        $direct = $this->direct($me, $olga);
        $this->send($direct, $olga, 'question');
        $this->actingAs($me);

        Livewire::test(ChatWindow::class)
            ->call('open', $direct->ulid)
            ->assertSeeHtml('data-fchat-unread')
            ->set('body', 'answer')
            ->call('send')
            ->assertSet('unreadMarker', null)
            ->assertDontSeeHtml('data-fchat-unread');
    }

    public function test_messages_arriving_while_open_add_no_line(): void
    {
        $me = $this->user();
        $olga = $this->user();
        $direct = $this->direct($me, $olga);
        $this->send($direct, $olga, 'read already');
        $this->actingAs($me);
        Livewire::test(ChatWindow::class)->call('open', $direct->ulid);

        $window = Livewire::test(ChatWindow::class)->call('open', $direct->ulid);
        $this->send($direct, $olga, 'live');

        $window->call('poll')->assertSee('live')->assertDontSeeHtml('data-fchat-unread');
    }

    public function test_marker_raises_the_limit_to_include_the_first_unread(): void
    {
        config(['filament-chat.messages.page_size' => 5]);
        $me = $this->user();
        $olga = $this->user();
        $direct = $this->direct($me, $olga);
        $first = $this->send($direct, $olga, 'unread 1');

        foreach (range(2, 8) as $i) {
            $this->send($direct, $olga, "unread {$i}");
        }
        $this->actingAs($me);

        Livewire::test(ChatWindow::class)
            ->call('open', $direct->ulid)
            ->assertSet('unreadMarker', $first->id)
            ->assertSet('limit', 10)
            ->assertSee('unread 1');
    }

    public function test_too_many_unread_shows_no_marker(): void
    {
        config(['filament-chat.messages.page_size' => 2]);
        $me = $this->user();
        $olga = $this->user();
        $direct = $this->direct($me, $olga);

        foreach (range(1, 11) as $i) {
            $this->send($direct, $olga, "m{$i}");
        }
        $this->actingAs($me);

        Livewire::test(ChatWindow::class)
            ->call('open', $direct->ulid)
            ->assertSet('unreadMarker', null)
            ->assertSet('limit', 2);
    }

    public function test_marker_counts_messages_of_deleted_authors(): void
    {
        $me = $this->user();
        $olga = $this->user();
        $ivan = $this->user();
        $group = $this->group($me, 'Team', $olga, $ivan);
        $this->actingAs($me);
        Livewire::test(ChatWindow::class)->call('open', $group->ulid);
        $gone = $this->send($group, $olga, 'from a deleted account');
        $olga->delete();

        Livewire::test(ChatWindow::class)
            ->call('open', $group->ulid)
            ->assertSet('unreadMarker', $gone->id);
    }
}
