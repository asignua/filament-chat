<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Tests\Feature;

use Asignua\FilamentChat\Data\MessageData;
use Asignua\FilamentChat\Enums\Reaction;
use Asignua\FilamentChat\Events\ChatUpdated;
use Asignua\FilamentChat\Models\Message;
use Asignua\FilamentChat\Notifications\NewMessageNotification;
use Asignua\FilamentChat\Policies\ConversationPolicy;
use Asignua\FilamentChat\Repositories\ConversationRepository;
use Asignua\FilamentChat\Repositories\ReactionRepository;
use Asignua\FilamentChat\Services\ChatService;
use Asignua\FilamentChat\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Workbench\App\Models\Note;

class ChatServiceTest extends TestCase
{
    public function test_message_is_saved_with_reference_and_lifts_the_conversation(): void
    {
        $me = $this->user();
        $conversation = $this->direct($me, $this->user());
        $note = Note::query()->create(['title' => 'Spec']);

        $message = $this->send($conversation, $me, '  Look at this  ', $note);

        $this->assertSame('Look at this', $message->body);
        $this->assertSame('note', $message->reference_type);
        $this->assertSame($note->id, $message->reference_id);
        $this->assertNotNull($conversation->refresh()->last_message_at);
        $this->assertSame($message->id, app(ConversationRepository::class)->participant($conversation, $me)?->last_read_message_id);
    }

    public function test_half_or_unknown_reference_is_dropped(): void
    {
        $this->assertNull(MessageData::fromArray(['body' => 'x', 'reference_type' => 'note'])->referenceType);
        $this->assertNull(MessageData::fromArray(['body' => 'x', 'reference_type' => 'invoice', 'reference_id' => 5])->referenceId);
        $this->assertSame('note', MessageData::fromArray(['body' => 'x', 'reference_type' => 'note', 'reference_id' => '5'])->referenceType);
    }

    public function test_empty_or_too_long_message_is_refused(): void
    {
        $me = $this->user();
        $conversation = $this->direct($me, $this->user());

        try {
            $this->send($conversation, $me, '   ');
            $this->fail('An empty message was sent.');
        } catch (InvalidArgumentException) {
        }

        config(['filament-chat.max_length' => 5]);

        $this->expectException(InvalidArgumentException::class);

        $this->send($conversation, $me, 'too long');
    }

    public function test_event_goes_to_every_active_member_but_not_to_who_left(): void
    {
        $me = $this->user();
        $stays = $this->user();
        $leaves = $this->user();
        $group = $this->group($me, 'Team', $stays, $leaves);
        app(ChatService::class)->leave($group, $leaves);

        Event::fake([ChatUpdated::class]);

        $message = $this->send($group, $me, 'hi');

        Event::assertDispatched(ChatUpdated::class, fn (ChatUpdated $event): bool => $event->message === $message->ulid
            && $event->author === (string) $me->id
            && collect($event->recipients)->sort()->values()->all() === collect([(string) $me->id, (string) $stays->id])->sort()->values()->all());
    }

    public function test_payload_carries_only_identifiers(): void
    {
        $event = new ChatUpdated(['1'], 'conv', 'msg', '2');

        $this->assertSame(['conversation' => 'conv', 'message' => 'msg', 'author' => '2'], $event->broadcastWith());
        $this->assertSame('private-filament-chat.user.1', $event->broadcastOn()[0]->name);
    }

    public function test_broadcast_key_can_be_another_attribute(): void
    {
        config(['filament-chat.users.broadcast_key' => 'email']);
        $me = $this->user(['email' => 'me@example.com']);
        $conversation = $this->direct($me, $this->user());

        Event::fake([ChatUpdated::class]);
        $this->send($conversation, $me, 'hi');

        Event::assertDispatched(ChatUpdated::class, fn (ChatUpdated $event): bool => in_array('me@example.com', $event->recipients, true));
    }

    public function test_no_event_without_realtime(): void
    {
        config(['filament-chat.realtime' => false]);
        $me = $this->user();
        $conversation = $this->direct($me, $this->user());

        Event::fake([ChatUpdated::class]);
        $this->send($conversation, $me, 'hi');

        Event::assertNotDispatched(ChatUpdated::class);
    }

    public function test_bell_rings_only_for_the_first_unread_message(): void
    {
        Notification::fake();
        $me = $this->user();
        $colleague = $this->user();
        $conversation = $this->direct($me, $colleague);

        $this->send($conversation, $me, 'one');
        $this->send($conversation, $me, 'two');

        Notification::assertSentToTimes($colleague, NewMessageNotification::class, 1);
        Notification::assertNotSentTo($me, NewMessageNotification::class);
    }

    public function test_bell_can_be_turned_off(): void
    {
        Notification::fake();
        config(['filament-chat.database_notifications' => false]);
        $me = $this->user();
        $colleague = $this->user();

        $this->send($this->direct($me, $colleague), $me, 'one');

        Notification::assertNothingSent();
    }

    public function test_notification_renders_for_the_bell(): void
    {
        $me = $this->user(['name' => 'Anna']);
        $colleague = $this->user();
        $conversation = $this->direct($me, $colleague);
        $message = $this->send($conversation, $me, 'hello');

        $data = (new NewMessageNotification($conversation, $message->load('author')))->toDatabase($colleague);

        $this->assertSame(__('filament-chat::chat.notification_title', ['who' => 'Anna']), $data['title']);
        $this->assertStringContainsString('/admin/chat?c='.$conversation->ulid, (string) json_encode($data['actions'], JSON_UNESCAPED_SLASHES));
    }

    public function test_only_members_see_a_conversation(): void
    {
        $me = $this->user();
        $colleague = $this->user();
        $stranger = $this->user();
        $conversation = $this->direct($me, $colleague);

        $this->assertTrue(Gate::forUser($me)->allows('view', $conversation));
        $this->assertFalse(Gate::forUser($stranger)->allows('view', $conversation));
        $this->assertFalse(Gate::forUser($me)->allows('delete', $conversation));
    }

    public function test_who_left_reads_but_cannot_write(): void
    {
        $me = $this->user();
        $leaves = $this->user();
        $group = $this->group($me, 'Team', $leaves, $this->user());
        app(ChatService::class)->leave($group, $leaves);

        $this->assertTrue(Gate::forUser($leaves)->allows('view', $group));
        $this->assertFalse(Gate::forUser($leaves)->allows(ConversationPolicy::SEND, $group));

        $this->expectException(InvalidArgumentException::class);

        $this->send($group, $leaves, 'still here?');
    }

    public function test_only_the_creator_manages_the_group(): void
    {
        $me = $this->user();
        $colleague = $this->user();
        $group = $this->group($me, 'Team', $colleague);

        $this->assertTrue(Gate::forUser($me)->allows('update', $group));
        $this->assertFalse(Gate::forUser($colleague)->allows('update', $group));
        $this->assertTrue(Gate::forUser($colleague)->allows(ConversationPolicy::LEAVE, $group));
        $this->assertFalse(Gate::forUser($me)->allows('update', $this->direct($me, $colleague)));
    }

    public function test_reading_tells_the_members_only_when_the_pointer_moves(): void
    {
        $me = $this->user();
        $colleague = $this->user();
        $conversation = $this->direct($me, $colleague);
        $message = $this->send($conversation, $colleague, 'hi');

        Event::fake([ChatUpdated::class]);

        $this->assertTrue(app(ChatService::class)->markRead($conversation, $me, $message->id));
        $this->assertFalse(app(ChatService::class)->markRead($conversation, $me, $message->id));

        Event::assertDispatchedTimes(ChatUpdated::class, 1);
    }

    public function test_reaction_toggles_and_replaces(): void
    {
        $me = $this->user();
        $colleague = $this->user(['name' => 'Olga']);
        $conversation = $this->direct($me, $colleague);
        $message = $this->send($conversation, $me, 'hi');
        $service = app(ChatService::class);

        $this->assertSame(Reaction::Like, $service->react($conversation, $message, $colleague, Reaction::Like));
        $this->assertSame(Reaction::Heart, $service->react($conversation, $message, $colleague, Reaction::Heart));
        $this->assertSame([$message->id => ['heart' => [$colleague->id => 'Olga']]], app(ReactionRepository::class)->forMessages([$message->id]));
        $this->assertNull($service->react($conversation, $message, $colleague, Reaction::Heart));
        $this->assertSame([], app(ReactionRepository::class)->forMessages([$message->id]));
    }

    public function test_reaction_to_a_message_of_another_conversation_is_refused(): void
    {
        $me = $this->user();
        $colleague = $this->user();
        $mine = $this->direct($me, $colleague);
        $foreign = $this->send($this->direct($colleague, $this->user()), $colleague, 'x');

        $this->expectException(InvalidArgumentException::class);

        app(ChatService::class)->react($mine, $foreign, $me, Reaction::Like);
    }

    public function test_every_reaction_has_an_emoji_and_a_label(): void
    {
        foreach (Reaction::cases() as $reaction) {
            $this->assertNotSame('', $reaction->emoji());
            $this->assertStringNotContainsString('filament-chat::', $reaction->getLabel());
        }

        $this->assertSame(0, Message::query()->count());
    }
}
