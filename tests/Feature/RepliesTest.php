<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Tests\Feature;

use Asignua\FilamentChat\Data\MessageData;
use Asignua\FilamentChat\Livewire\ChatWindow;
use Asignua\FilamentChat\Services\ChatService;
use Asignua\FilamentChat\Support\ChatConfig;
use Asignua\FilamentChat\Tests\TestCase;
use Livewire\Livewire;

class RepliesTest extends TestCase
{
    public function test_a_reply_points_at_the_original(): void
    {
        $me = $this->user();
        $olga = $this->user();
        $direct = $this->direct($me, $olga);
        $original = $this->send($direct, $olga, 'Can you check the order?');

        $reply = app(ChatService::class)->send($direct, $me, MessageData::fromArray(['body' => 'Sure', 'reply_to' => $original->ulid]));

        $this->assertSame($original->id, $reply->fresh()->reply_to_id);
        $this->assertTrue($reply->fresh()->replyTo->is($original));
    }

    public function test_reply_to_a_message_of_another_conversation_is_dropped(): void
    {
        $me = $this->user();
        $olga = $this->user();
        $ivan = $this->user();
        $foreign = $this->send($this->direct($olga, $ivan), $olga, 'secret');
        $direct = $this->direct($me, $olga);

        $reply = app(ChatService::class)->send($direct, $me, MessageData::fromArray(['body' => 'Hi', 'reply_to' => $foreign->ulid]));

        $this->assertNull($reply->fresh()->reply_to_id);
    }

    public function test_replies_off_ignores_reply_to(): void
    {
        config(['filament-chat.features.replies' => false]);
        $me = $this->user();
        $olga = $this->user();
        $direct = $this->direct($me, $olga);
        $original = $this->send($direct, $olga, 'Question');

        $reply = app(ChatService::class)->send($direct, $me, MessageData::fromArray(['body' => 'Answer', 'reply_to' => $original->ulid]));

        $this->assertNull($reply->fresh()->reply_to_id);
    }

    public function test_editing_keeps_the_reply(): void
    {
        $me = $this->user();
        $olga = $this->user();
        $direct = $this->direct($me, $olga);
        $original = $this->send($direct, $olga, 'Question');
        $reply = app(ChatService::class)->send($direct, $me, MessageData::fromArray(['body' => 'Answer', 'reply_to' => $original->ulid]));

        app(ChatService::class)->edit($direct, $reply, $me, 'Better answer');

        $this->assertSame($original->id, $reply->fresh()->reply_to_id);
    }

    public function test_reply_from_the_window_is_sent_with_a_quote(): void
    {
        $me = $this->user();
        $olga = $this->user(['name' => 'Olga']);
        $direct = $this->direct($me, $olga);
        $original = $this->send($direct, $olga, 'Can you check the order?');
        $this->actingAs($me);

        Livewire::test(ChatWindow::class)
            ->call('open', $direct->ulid)
            ->call('startReply', $original->ulid)
            ->assertSet('replyingTo', $original->ulid)
            ->assertSee(__('filament-chat::chat.replying_to'))
            ->set('body', 'Sure')
            ->call('send')
            ->assertSet('replyingTo', null)
            ->assertSeeHtml('data-fchat-quote="'.$original->ulid.'"');
    }

    public function test_start_reply_ignores_a_foreign_message(): void
    {
        $me = $this->user();
        $olga = $this->user();
        $foreign = $this->send($this->direct($olga, $this->user()), $olga, 'secret');
        $direct = $this->direct($me, $olga);
        $this->actingAs($me);

        Livewire::test(ChatWindow::class)
            ->call('open', $direct->ulid)
            ->call('startReply', $foreign->ulid)
            ->assertSet('replyingTo', null);
    }

    public function test_editing_and_switching_cancel_the_reply(): void
    {
        $me = $this->user();
        $olga = $this->user();
        $direct = $this->direct($me, $olga);
        $original = $this->send($direct, $olga, 'Question');
        $mine = $this->send($direct, $me, 'Mine');
        $other = $this->direct($me, $this->user());
        $this->actingAs($me);

        Livewire::test(ChatWindow::class)
            ->call('open', $direct->ulid)
            ->call('startReply', $original->ulid)
            ->call('startEdit', $mine->ulid)
            ->assertSet('replyingTo', null)
            ->call('cancelEdit')
            ->call('startReply', $original->ulid)
            ->call('open', $other->ulid)
            ->assertSet('replyingTo', null);
    }

    public function test_show_message_loads_enough_history_for_an_old_original(): void
    {
        config(['filament-chat.messages.page_size' => 5]);
        $me = $this->user();
        $olga = $this->user();
        $direct = $this->direct($me, $olga);
        $original = $this->send($direct, $olga, 'Very old');

        foreach (range(1, 12) as $i) {
            $this->send($direct, $olga, "filler {$i}");
        }
        $this->actingAs($me);

        Livewire::test(ChatWindow::class)
            ->call('open', $direct->ulid)
            ->assertDontSee('Very old')
            ->call('showMessage', $original->ulid)
            ->assertSet('limit', 15)
            ->assertSee('Very old')
            ->assertDispatched(ChatWindow::EVENT_HIGHLIGHT, message: $original->ulid);
    }

    public function test_show_message_does_not_reach_past_leaving(): void
    {
        $me = $this->user();
        $olga = $this->user();
        $ivan = $this->user();
        $group = $this->group($olga, 'Team', $me, $ivan);
        $this->send($group, $olga, 'before');
        app(ChatService::class)->leave($group, $me);
        $this->travel(1)->minutes();
        $after = $this->send($group, $olga, 'after I left');
        $this->actingAs($me);

        // The list preview leak is covered by the left-group preview test.
        Livewire::test(ChatWindow::class)
            ->call('open', $group->ulid)
            ->call('showMessage', $after->ulid)
            ->assertNotDispatched(ChatWindow::EVENT_HIGHLIGHT)
            ->assertSet('limit', ChatConfig::pageSize());
    }

    public function test_quote_of_a_deleted_author_renders(): void
    {
        $me = $this->user();
        $olga = $this->user(['name' => 'Olga']);
        $ivan = $this->user(['name' => 'Ivan']);
        $group = $this->group($me, 'Team', $olga, $ivan);
        $original = $this->send($group, $olga, 'Question');
        app(ChatService::class)->send($group, $me, MessageData::fromArray(['body' => 'Answer', 'reply_to' => $original->ulid]));
        $olga->delete();
        $this->actingAs($me);

        Livewire::test(ChatWindow::class)
            ->call('open', $group->ulid)
            ->assertSeeHtml('data-fchat-quote="'.$original->ulid.'"')
            ->assertSee(__('filament-chat::chat.unknown_user'));
    }
}
