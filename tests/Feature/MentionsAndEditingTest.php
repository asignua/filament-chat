<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Tests\Feature;

use Asignua\FilamentChat\Events\ChatUpdated;
use Asignua\FilamentChat\Livewire\ChatWindow;
use Asignua\FilamentChat\Notifications\MentionNotification;
use Asignua\FilamentChat\Notifications\NewMessageNotification;
use Asignua\FilamentChat\Services\ChatService;
use Asignua\FilamentChat\Support\ChatText;
use Asignua\FilamentChat\Support\Mentions;
use Asignua\FilamentChat\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Livewire\Livewire;

class MentionsAndEditingTest extends TestCase
{
    public function test_mentions_are_found_by_name_longest_first_and_case_insensitive(): void
    {
        $people = [1 => 'Olga', 2 => 'Olga Green', 3 => 'Ivan'];

        $this->assertSame([2], Mentions::find('Hi @olga green!', $people));
        $this->assertSame([1, 3], Mentions::find('@Olga and @Ivan, look', $people));
        $this->assertSame([], Mentions::find('mail olga@ivan.com or @Ivanna', $people));
    }

    public function test_mentions_are_highlighted_and_text_stays_escaped(): void
    {
        $html = (string) ChatText::toHtml('<b>@Olga</b> and @Ivan', [1 => 'Olga', 3 => 'Ivan'], 3);

        $this->assertStringContainsString('&lt;b&gt;<span class="fchat-mention">@Olga</span>&lt;/b&gt;', $html);
        $this->assertStringContainsString('<span class="fchat-mention fchat-mention-me">@Ivan</span>', $html);
    }

    public function test_mentioned_members_are_stored_and_always_notified(): void
    {
        Notification::fake();
        $me = $this->user(['name' => 'Anna']);
        $olga = $this->user(['name' => 'Olga Green']);
        $ivan = $this->user(['name' => 'Ivan']);
        $stranger = $this->user(['name' => 'Petro']);
        $group = $this->group($me, 'Team', $olga, $ivan);

        $this->send($group, $me, 'first');
        $message = $this->send($group, $me, '@Olga Green please check, @Petro too, and @Anna');

        $this->assertSame([$olga->id], $message->mentionIds());
        Notification::assertSentToTimes($olga, MentionNotification::class, 1);
        Notification::assertNotSentTo($stranger, MentionNotification::class);
        Notification::assertNotSentTo($me, MentionNotification::class);
        // Ivan got the bell for the first unread message only.
        Notification::assertSentToTimes($ivan, NewMessageNotification::class, 1);
        Notification::assertNotSentTo($ivan, MentionNotification::class);
    }

    public function test_author_edits_the_text_and_new_mentions_are_notified(): void
    {
        Notification::fake();
        $me = $this->user();
        $olga = $this->user(['name' => 'Olga']);
        $group = $this->group($me, 'Team', $olga, $this->user());
        $message = $this->send($group, $me, 'Draft');

        Event::fake([ChatUpdated::class]);

        app(ChatService::class)->edit($group, $message, $me, 'Final, @Olga');

        $message->refresh();
        $this->assertSame('Final, @Olga', $message->body);
        $this->assertTrue($message->isEdited());
        $this->assertSame([$olga->id], $message->mentionIds());
        Event::assertDispatched(ChatUpdated::class, fn (ChatUpdated $event): bool => $event->message === null);
        Notification::assertSentToTimes($olga, MentionNotification::class, 1);

        // Editing again with the same mention does not ring twice.
        app(ChatService::class)->edit($group, $message, $me, 'Final! @Olga');
        Notification::assertSentToTimes($olga, MentionNotification::class, 1);
    }

    public function test_only_the_author_edits_while_in_the_conversation_and_within_the_window(): void
    {
        $me = $this->user();
        $colleague = $this->user();
        $group = $this->group($me, 'Team', $colleague, $this->user());
        $mine = $this->send($group, $me, 'mine');

        $this->assertTrue(Gate::forUser($me)->allows('update', $mine));
        $this->assertFalse(Gate::forUser($colleague)->allows('update', $mine));

        config(['filament-chat.features.editing.window' => 5]);
        $this->travel(6)->minutes();
        $this->assertFalse(Gate::forUser($me)->allows('update', $mine));

        config(['filament-chat.features.editing.window' => null]);
        $this->assertTrue(Gate::forUser($me)->allows('update', $mine));

        config(['filament-chat.features.editing.enabled' => false]);
        $this->assertFalse(Gate::forUser($me)->allows('update', $mine));
        config(['filament-chat.features.editing.enabled' => true]);

        app(ChatService::class)->leave($group, $me);
        $this->assertFalse(Gate::forUser($me)->allows('update', $mine));

        $this->expectException(InvalidArgumentException::class);
        app(ChatService::class)->edit($group, $mine, $me, 'changed');
    }

    public function test_editing_from_the_window(): void
    {
        $me = $this->user();
        $conversation = $this->direct($me, $this->user());
        $message = $this->send($conversation, $me, 'Typo hre');
        $this->actingAs($me);

        Livewire::test(ChatWindow::class)
            ->call('open', $conversation->ulid)
            ->assertViewHas('editable', [$message->id])
            ->call('editLast')
            ->assertSet('editing', $message->ulid)
            ->assertSet('body', 'Typo hre')
            ->assertDispatched(ChatWindow::EVENT_EDIT)
            ->assertSee(__('filament-chat::chat.editing'))
            ->set('body', 'Typo here')
            ->call('send')
            ->assertSet('editing', null)
            ->assertSet('body', '')
            ->assertSee('Typo here')
            ->assertSee(__('filament-chat::chat.edited'));

        $this->assertSame('Typo here', $message->refresh()->body);
        $this->assertSame(1, $conversation->messages()->count());
    }

    public function test_someone_elses_message_cannot_be_put_into_edit(): void
    {
        $me = $this->user();
        $colleague = $this->user();
        $conversation = $this->direct($me, $colleague);
        $foreign = $this->send($conversation, $colleague, 'not yours');
        $this->actingAs($me);

        Livewire::test(ChatWindow::class)
            ->call('open', $conversation->ulid)
            ->assertViewHas('editable', [])
            ->call('startEdit', $foreign->ulid)
            ->assertSet('editing', null)
            ->call('cancelEdit')
            ->assertSet('body', '');
    }

    public function test_composer_offers_the_active_members_for_mentions(): void
    {
        $me = $this->user();
        $olga = $this->user(['name' => 'Olga']);
        $leaves = $this->user(['name' => 'Leaver']);
        $group = $this->group($me, 'Team', $olga, $leaves);
        app(ChatService::class)->leave($group, $leaves);
        $this->send($group, $me, 'hey @Olga');
        $this->actingAs($me);

        Livewire::test(ChatWindow::class)
            ->call('open', $group->ulid)
            ->assertViewHas('mentionable', fn (array $people): bool => array_column($people, 'name') === ['Olga'])
            ->assertSeeHtml('<span class="fchat-mention">@Olga</span>');
    }
}
