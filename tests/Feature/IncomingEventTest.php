<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Tests\Feature;

use Asignua\FilamentChat\Livewire\ChatDock;
use Asignua\FilamentChat\Tests\TestCase;
use Livewire\Livewire;

/**
 * `filament-chat-incoming` — the browser hears about a new message from someone else (an extension plays a sound on it).
 * `open` says the conversation is on the screen; the browser alone knows whether the tab is visible.
 */
class IncomingEventTest extends TestCase
{
    public function test_a_message_from_someone_else_is_announced(): void
    {
        $me = $this->user();
        $colleague = $this->user();
        $conversation = $this->direct($me, $colleague);
        $message = $this->send($conversation, $colleague, 'Hi');
        $this->actingAs($me);

        Livewire::test(ChatDock::class)
            ->call('onChatUpdated', ['conversation' => $conversation->ulid, 'message' => $message->ulid, 'author' => (string) $colleague->id])
            ->assertDispatched(ChatDock::EVENT_INCOMING, conversation: $conversation->ulid, message: $message->ulid, open: false);
    }

    public function test_own_messages_and_read_events_are_not_announced(): void
    {
        $me = $this->user();
        $colleague = $this->user();
        $conversation = $this->direct($me, $colleague);
        $message = $this->send($conversation, $me, 'Mine');
        $this->actingAs($me);

        Livewire::test(ChatDock::class)
            ->call('onChatUpdated', ['conversation' => $conversation->ulid, 'message' => $message->ulid, 'author' => (string) $me->id])
            ->assertNotDispatched(ChatDock::EVENT_INCOMING)
            ->call('onChatUpdated', ['conversation' => $conversation->ulid, 'reader' => (string) $colleague->id])
            ->assertNotDispatched(ChatDock::EVENT_INCOMING);
    }

    public function test_the_conversation_on_the_screen_is_marked_open(): void
    {
        $me = $this->user();
        $colleague = $this->user();
        $conversation = $this->direct($me, $colleague);
        $message = $this->send($conversation, $colleague, 'Seen');
        $this->actingAs($me);
        $payload = ['conversation' => $conversation->ulid, 'message' => $message->ulid, 'author' => (string) $colleague->id];

        Livewire::test(ChatDock::class)
            ->call('setPanelOpen', true)
            ->call('rememberConversation', $conversation->ulid)
            ->call('onChatUpdated', $payload)
            ->assertDispatched(ChatDock::EVENT_INCOMING, open: true);

        // The Chat page: a quiet counter, the page's own window shows the conversation.
        Livewire::test(ChatDock::class, ['quiet' => true])
            ->call('rememberConversation', $conversation->ulid)
            ->call('onChatUpdated', $payload)
            ->assertDispatched(ChatDock::EVENT_INCOMING, open: true);
    }

    public function test_the_event_does_not_depend_on_toasts(): void
    {
        config(['filament-chat.notifications.toasts' => false]);
        $me = $this->user();
        $colleague = $this->user();
        $conversation = $this->direct($me, $colleague);
        $message = $this->send($conversation, $colleague, 'Hi');
        $this->actingAs($me);

        Livewire::test(ChatDock::class)
            ->call('onChatUpdated', ['conversation' => $conversation->ulid, 'message' => $message->ulid, 'author' => (string) $colleague->id])
            ->assertNotNotified()
            ->assertDispatched(ChatDock::EVENT_INCOMING);
    }

    public function test_without_a_socket_a_growing_counter_is_announced(): void
    {
        config(['filament-chat.realtime.enabled' => false]);
        $me = $this->user();
        $colleague = $this->user();
        $conversation = $this->direct($me, $colleague);
        $this->send($conversation, $colleague, 'Before the page opened');
        $this->actingAs($me);

        $dock = Livewire::test(ChatDock::class)->assertNotDispatched(ChatDock::EVENT_INCOMING);

        $dock->call('$refresh')->assertNotDispatched(ChatDock::EVENT_INCOMING);

        $this->send($conversation, $colleague, 'While it is open');

        $dock->call('$refresh')->assertDispatched(ChatDock::EVENT_INCOMING, conversation: null, message: null, open: false);
    }

    public function test_with_a_socket_polling_announces_nothing(): void
    {
        $me = $this->user();
        $colleague = $this->user();
        $conversation = $this->direct($me, $colleague);
        $this->actingAs($me);
        $dock = Livewire::test(ChatDock::class);

        $this->send($conversation, $colleague, 'Comes with the socket event');

        $dock->call('$refresh')->assertNotDispatched(ChatDock::EVENT_INCOMING);
    }
}
