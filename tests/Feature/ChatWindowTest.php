<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Tests\Feature;

use Asignua\FilamentChat\Livewire\ChatDock;
use Asignua\FilamentChat\Livewire\ChatWindow;
use Asignua\FilamentChat\Models\Conversation;
use Asignua\FilamentChat\Models\Message;
use Asignua\FilamentChat\Pages\Chat;
use Asignua\FilamentChat\Repositories\ConversationRepository;
use Asignua\FilamentChat\Services\ChatService;
use Asignua\FilamentChat\Tests\TestCase;
use Livewire\Livewire;

class ChatWindowTest extends TestCase
{
    public function test_page_opens_the_conversation_from_the_address(): void
    {
        $me = $this->user();
        $colleague = $this->user(['name' => 'Olga']);
        $conversation = $this->direct($me, $colleague);
        $this->send($conversation, $colleague, 'Hello from the test');

        $this->actingAs($me)
            ->get(Chat::urlFor($conversation))
            ->assertOk()
            ->assertSee('Hello from the test')
            ->assertSee('Olga');
    }

    public function test_opening_marks_read_and_sending_appends(): void
    {
        $me = $this->user();
        $colleague = $this->user();
        $conversation = $this->direct($me, $colleague);
        $this->send($conversation, $colleague, 'Question');
        $this->actingAs($me);

        $this->assertSame(1, app(ConversationRepository::class)->unreadTotalFor($me));

        Livewire::test(ChatWindow::class)
            ->call('open', $conversation->ulid)
            ->assertSee('Question')
            ->set('body', 'Answer')
            ->call('send')
            ->assertSet('body', '')
            ->assertSee('Answer')
            ->assertDispatched(ChatWindow::EVENT_SENT);

        $this->assertSame(0, app(ConversationRepository::class)->unreadTotalFor($me));
        $this->assertSame(2, Message::query()->count());
    }

    public function test_own_message_shows_sent_then_read(): void
    {
        $me = $this->user();
        $colleague = $this->user();
        $conversation = $this->direct($me, $colleague);
        $message = $this->send($conversation, $me, 'Look');
        $this->actingAs($me);

        Livewire::test(ChatWindow::class)
            ->call('open', $conversation->ulid)
            ->assertSeeHtml('title="'.__('filament-chat::chat.status_sent').'"');

        app(ConversationRepository::class)->markRead($conversation, $colleague, $message->id);

        Livewire::test(ChatWindow::class)
            ->call('open', $conversation->ulid)
            ->assertSeeHtml('title="'.__('filament-chat::chat.status_read').'"');
    }

    public function test_refused_message_does_not_report_sent(): void
    {
        $me = $this->user();
        $conversation = $this->direct($me, $this->user());
        $this->actingAs($me);

        Livewire::test(ChatWindow::class)
            ->call('open', $conversation->ulid)
            ->set('body', '   ')
            ->call('send')
            ->assertNotDispatched(ChatWindow::EVENT_SENT)
            ->assertNotified(__('filament-chat::chat.error_empty'));
    }

    public function test_a_foreign_conversation_does_not_open(): void
    {
        $conversation = $this->direct($this->user(), $this->user());
        $this->actingAs($this->user());

        Livewire::test(ChatWindow::class)
            ->call('open', $conversation->ulid)
            ->assertSet('conversation', null);
    }

    public function test_hidden_window_does_not_mark_messages_read(): void
    {
        $me = $this->user();
        $colleague = $this->user();
        $conversation = $this->direct($me, $colleague);
        $this->send($conversation, $colleague, 'Unseen');
        $this->actingAs($me);

        $window = Livewire::test(ChatWindow::class, ['compact' => true])
            ->call('setVisible', false)
            ->call('open', $conversation->ulid);

        $this->assertSame(1, app(ConversationRepository::class)->unreadTotalFor($me));

        $window->call('setVisible', true);

        $this->assertSame(0, app(ConversationRepository::class)->unreadTotalFor($me));
    }

    public function test_older_messages_load_on_demand(): void
    {
        config(['filament-chat.messages.page_size' => 2]);
        $me = $this->user();
        $conversation = $this->direct($me, $this->user());

        foreach (['alpha-msg', 'beta-msg', 'gamma-msg'] as $body) {
            $this->send($conversation, $me, $body);
        }

        $this->actingAs($me);

        Livewire::test(ChatWindow::class)
            ->call('open', $conversation->ulid)
            ->assertDontSee('alpha-msg')
            ->assertSee('gamma-msg')
            ->assertSee(__('filament-chat::chat.load_older'))
            ->call('loadOlder')
            ->assertSee('alpha-msg')
            ->assertDontSee(__('filament-chat::chat.load_older'));
    }

    public function test_new_group_and_direct_from_the_window(): void
    {
        $me = $this->user();
        $colleague = $this->user();
        $inactive = $this->user(['is_active' => false]);
        $this->actingAs($me);

        Livewire::test(ChatWindow::class)
            ->callAction('newDirect', ['user_id' => $colleague->id])
            ->callAction('newGroup', ['title' => 'Team', 'member_ids' => [$colleague->id]])
            ->assertSet('conversation', fn (?string $ulid): bool => $ulid !== null);

        $this->assertSame(2, Conversation::query()->count());

        Livewire::test(ChatWindow::class)->callAction('newDirect', ['user_id' => $inactive->id]);

        $this->assertSame(2, Conversation::query()->count());
    }

    public function test_group_actions_follow_the_policy(): void
    {
        $me = $this->user();
        $colleague = $this->user();
        $group = $this->group($me, 'Team', $colleague);
        $direct = $this->direct($me, $colleague);

        $this->actingAs($colleague);
        Livewire::test(ChatWindow::class)
            ->call('open', $group->ulid)
            ->assertViewHas('canManage', false)
            ->assertViewHas('canLeave', true);

        $this->actingAs($me);
        Livewire::test(ChatWindow::class)
            ->call('open', $group->ulid)
            ->assertViewHas('canManage', true)
            ->mountAction('manageGroup')
            ->assertSchemaStateSet(['member_ids' => [$colleague->id]], 'mountedActionSchema0');

        Livewire::test(ChatWindow::class)
            ->call('open', $direct->ulid)
            ->assertViewHas('canManage', false)
            ->assertViewHas('canLeave', false);
    }

    public function test_member_who_left_sees_the_feed_but_cannot_write_or_react(): void
    {
        $me = $this->user();
        $leaves = $this->user();
        $group = $this->group($me, 'Team', $leaves, $this->user());
        $message = $this->send($group, $me, 'Before leaving');
        app(ChatService::class)->leave($group, $leaves);
        $this->actingAs($leaves);

        Livewire::test(ChatWindow::class)
            ->call('open', $group->ulid)
            ->assertSee('Before leaving')
            ->assertSee(__('filament-chat::chat.left_hint'))
            ->call('react', $message->ulid, 'like');

        $this->assertSame(0, $message->reactions()->count());
    }

    public function test_reaction_chip_appears_and_unknown_reaction_is_ignored(): void
    {
        $me = $this->user();
        $conversation = $this->direct($me, $this->user());
        $message = $this->send($conversation, $me, 'hi');
        $this->actingAs($me);

        Livewire::test(ChatWindow::class)
            ->call('open', $conversation->ulid)
            ->call('react', $message->ulid, 'nope')
            ->call('react', $message->ulid, 'heart')
            ->assertSeeHtml('❤️</span>');

        $this->assertSame(1, $message->reactions()->count());
    }

    public function test_only_a_new_message_scrolls_the_feed(): void
    {
        $me = $this->user();
        $conversation = $this->direct($me, $this->user());
        $this->actingAs($me);

        $window = Livewire::test(ChatWindow::class)->call('open', $conversation->ulid);

        $window->call('onChatUpdated', ['conversation' => $conversation->ulid, 'message' => null])
            ->assertNotDispatched(ChatWindow::EVENT_SCROLL);
        $window->call('onChatUpdated', ['conversation' => $conversation->ulid, 'message' => 'x'])
            ->assertDispatched(ChatWindow::EVENT_SCROLL);
    }

    public function test_composer_blocks_are_keyed_against_browser_extensions(): void
    {
        $me = $this->user();
        $conversation = $this->direct($me, $this->user());
        $this->actingAs($me);

        Livewire::test(ChatWindow::class)
            ->call('open', $conversation->ulid)
            ->assertSeeHtml('wire:key="fchat-list"')
            ->assertSeeHtml('wire:key="fchat-conversation"')
            ->assertSeeHtml('wire:key="fchat-composer"')
            ->assertSeeHtml('data-gramm="false"');
    }

    public function test_panel_window_announces_it_is_ready(): void
    {
        $this->actingAs($this->user());

        Livewire::test(ChatWindow::class, ['compact' => true])->assertDispatched(ChatWindow::EVENT_READY);
        Livewire::test(ChatWindow::class)->assertNotDispatched(ChatWindow::EVENT_READY);
    }

    public function test_dock_shows_the_unread_counter_and_toasts_foreign_messages(): void
    {
        $me = $this->user();
        $colleague = $this->user(['name' => 'Olga']);
        $conversation = $this->direct($me, $colleague);
        $message = $this->send($conversation, $colleague, 'Urgent');
        $this->actingAs($me);

        Livewire::test(ChatDock::class)
            ->assertSet('unread', 1)
            ->call('onChatUpdated', [
                'conversation' => $conversation->ulid,
                'message' => $message->ulid,
                'author' => (string) $colleague->id,
            ])
            ->assertNotified(__('filament-chat::chat.notification_title', ['who' => 'Olga']));
    }

    public function test_dock_stays_quiet_when_the_panel_shows_that_conversation(): void
    {
        $me = $this->user();
        $colleague = $this->user();
        $conversation = $this->direct($me, $colleague);
        $message = $this->send($conversation, $colleague, 'Seen anyway');
        $this->actingAs($me);

        Livewire::test(ChatDock::class)
            ->call('setPanelOpen', true)
            ->call('rememberConversation', $conversation->ulid)
            ->call('onChatUpdated', [
                'conversation' => $conversation->ulid,
                'message' => $message->ulid,
                'author' => (string) $colleague->id,
            ])
            ->assertNotNotified();

        Livewire::test(ChatDock::class, ['quiet' => true])
            ->call('onChatUpdated', [
                'conversation' => $conversation->ulid,
                'message' => $message->ulid,
                'author' => (string) $colleague->id,
            ])
            ->assertNotNotified();
    }

    public function test_dock_and_slide_over_are_not_rendered_on_the_chat_page(): void
    {
        $this->actingAs($this->user())
            ->get('/admin/chat')
            ->assertOk()
            ->assertDontSee('filament-chat-panel-window', false)
            ->assertDontSee('filament-chat-toggle', false);
    }

    public function test_pinned_slide_over_shifts_the_page_before_first_paint(): void
    {
        $this->actingAs($this->user())
            ->get('/admin/notes')
            ->assertOk()
            ->assertSee('filament-chat.pinned', false)
            ->assertSee('fchat-pinned', false)
            ->assertSee('window.filamentChatBadge', false);
    }

    public function test_the_list_does_not_preview_messages_written_after_i_left(): void
    {
        $me = $this->user();
        $olga = $this->user(['name' => 'Olga']);
        $ivan = $this->user(['name' => 'Ivan']);
        $group = $this->group($olga, 'Team', $me, $ivan);
        $this->send($group, $olga, 'Visible before leaving');
        app(ChatService::class)->leave($group, $me);
        $this->travel(1)->minutes();
        $this->send($group, $olga, 'Written after I left');
        $this->actingAs($me);

        Livewire::test(ChatWindow::class)
            ->assertSee('Visible before leaving')
            ->assertDontSee('Written after I left')
            ->call('open', $group->ulid)
            ->assertDontSee('Written after I left');
    }
}
