<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Tests\Feature;

use Asignua\FilamentChat\Events\ChatUpdated;
use Asignua\FilamentChat\Livewire\ChatWindow;
use Asignua\FilamentChat\Models\Conversation;
use Asignua\FilamentChat\Models\Message;
use Asignua\FilamentChat\Repositories\ConversationRepository;
use Asignua\FilamentChat\Services\ChatService;
use Asignua\FilamentChat\Support\ChatText;
use Asignua\FilamentChat\Support\ChatUsers;
use Asignua\FilamentChat\Tests\TestCase;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Workbench\App\Models\Note;

class ReviewFixesTest extends TestCase
{
    public function test_the_stylesheet_has_no_bare_utilities(): void
    {
        $css = (string) file_get_contents(__DIR__.'/../../resources/dist/filament-chat.css');

        // A bare `.hidden` / `.bg-white` after the host's theme would beat its `lg:block` / `dark:` variants.
        $this->assertSame(0, preg_match('~[{};,]\.(hidden|flex|block|bg-white|text-gray-500|border-gray-200|p-3|w-full)[{,:]~', $css));
        $this->assertStringContainsString('.fchat-scope .hidden{', $css);
        $this->assertStringContainsString('.fchat-scope .bg-white{', $css);
    }

    public function test_the_window_and_the_slide_over_carry_the_style_scope(): void
    {
        $me = $this->user();
        $this->actingAs($me);

        Livewire::test(ChatWindow::class)->assertSeeHtml('class="fchat fchat-scope');

        $this->get('/admin/notes')->assertSee('class="fchat-scope"', false);
        $this->get('/admin/chat')->assertSee('<div class="fchat-scope">', false);
    }

    public function test_an_unsent_draft_does_not_follow_to_another_conversation(): void
    {
        $me = $this->user();
        $alice = $this->direct($me, $this->user());
        $bob = $this->direct($me, $this->user());
        $this->actingAs($me);

        Livewire::test(ChatWindow::class)
            ->call('open', $alice->ulid)
            ->set('body', 'private reply to Alice')
            ->call('open', $bob->ulid)
            ->assertSet('body', '')
            ->assertDispatched(ChatWindow::EVENT_EDIT, body: '')
            ->set('body', 'draft')
            ->call('back')
            ->assertSet('body', '');
    }

    public function test_a_background_tab_marks_nothing_as_read(): void
    {
        $me = $this->user();
        $colleague = $this->user();
        $conversation = $this->direct($me, $colleague);
        $this->actingAs($me);

        $window = Livewire::test(ChatWindow::class)
            ->call('open', $conversation->ulid)
            ->call('setDocumentHidden', true);

        $message = $this->send($conversation, $colleague, 'are you there?');
        $window->call('onChatUpdated', ['conversation' => $conversation->ulid, 'message' => $message->ulid]);

        $this->assertSame(1, app(ConversationRepository::class)->unreadTotalFor($me));

        $window->call('setDocumentHidden', false);

        $this->assertSame(0, app(ConversationRepository::class)->unreadTotalFor($me));
    }

    public function test_without_any_visibility_report_messages_are_still_marked_read(): void
    {
        // A published chat-window view without the x-init hook never calls setDocumentHidden().
        $me = $this->user();
        $colleague = $this->user();
        $conversation = $this->direct($me, $colleague);
        $this->actingAs($me);

        $window = Livewire::test(ChatWindow::class)->assertSet('documentHidden', null)->call('open', $conversation->ulid);

        $message = $this->send($conversation, $colleague, 'hello');
        $window->call('onChatUpdated', ['conversation' => $conversation->ulid, 'message' => $message->ulid]);

        $this->assertSame(0, app(ConversationRepository::class)->unreadTotalFor($me));
    }

    public function test_the_shipped_window_view_carries_the_visibility_hooks(): void
    {
        $view = file_get_contents(__DIR__.'/../../resources/views/livewire/chat-window.blade.php');

        $this->assertStringContainsString('fchat-scope', $view);
        $this->assertStringContainsString('setDocumentHidden(true)', $view);
        $this->assertStringContainsString('x-on:visibilitychange.document', $view);
    }

    public function test_a_read_reaches_the_authors_it_concerns_not_the_whole_group(): void
    {
        $author = $this->user();
        $reader = $this->user();
        $bystander = $this->user();
        $group = $this->group($author, 'Team', $reader, $bystander);
        $message = $this->send($group, $author, 'hi');

        Event::fake([ChatUpdated::class]);

        $this->assertTrue(app(ChatService::class)->markRead($group, $reader, $message->id));

        Event::assertDispatched(ChatUpdated::class, function (ChatUpdated $event) use ($author, $reader, $bystander): bool {
            $recipients = $event->recipients;
            sort($recipients);

            return $recipients === [(string) $author->id, (string) $reader->id]
                && !in_array((string) $bystander->id, $event->recipients, true)
                && $event->reader === (string) $reader->id;
        });
    }

    public function test_with_read_receipts_off_a_read_stays_with_the_reader(): void
    {
        $author = $this->user();
        $reader = $this->user();
        $conversation = $this->direct($author, $reader);
        $message = $this->send($conversation, $author, 'hi');
        config(['filament-chat.features.read_receipts' => false]);

        Event::fake([ChatUpdated::class]);

        app(ChatService::class)->markRead($conversation, $reader, $message->id);

        Event::assertDispatched(ChatUpdated::class, fn (ChatUpdated $event): bool => $event->recipients === [(string) $reader->id]);
    }

    public function test_the_event_waits_for_an_outer_transaction(): void
    {
        $this->assertInstanceOf(ShouldDispatchAfterCommit::class, new ChatUpdated(['1'], 'conv'));
    }

    public function test_a_foreign_record_cannot_be_attached(): void
    {
        $me = $this->user();
        $conversation = $this->direct($me, $this->user());
        $secret = Note::query()->create(['title' => 'Secret', 'owner_id' => $this->user()->id]);
        $open = Note::query()->create(['title' => 'Open']);
        $this->actingAs($me);

        Livewire::test(ChatWindow::class)
            ->call('open', $conversation->ulid)
            ->call('attach', 'note', $secret->id)
            ->assertSet('referenceType', null)
            ->call('attach', 'note', 999999)
            ->assertSet('referenceType', null)
            ->call('attach', 'note', $open->id)
            ->assertSet('referenceId', $open->id);
    }

    public function test_the_attached_record_cannot_be_swapped_from_the_browser(): void
    {
        $me = $this->user();
        $conversation = $this->direct($me, $this->user());
        $secret = Note::query()->create(['title' => 'Secret', 'owner_id' => $this->user()->id]);
        $this->actingAs($me);

        $this->expectException(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);

        Livewire::test(ChatWindow::class)
            ->call('open', $conversation->ulid)
            ->set('referenceId', $secret->id);
    }

    public function test_a_record_closed_after_it_was_attached_is_not_sent(): void
    {
        $me = $this->user();
        $conversation = $this->direct($me, $this->user());
        $note = Note::query()->create(['title' => 'Plan']);
        $this->actingAs($me);

        $window = Livewire::test(ChatWindow::class)
            ->call('open', $conversation->ulid)
            ->call('attach', 'note', $note->id);

        $note->delete();

        $window->set('body', 'look')->call('send')
            ->assertSet('referenceType', null)
            ->assertNotified(__('filament-chat::chat.reference_hidden'));

        $this->assertSame(0, Message::query()->count());
    }

    public function test_the_composer_ignores_enter_during_ime_composition_and_text_drops(): void
    {
        $me = $this->user();
        $conversation = $this->direct($me, $this->user());
        $this->actingAs($me);

        Livewire::test(ChatWindow::class)
            ->call('open', $conversation->ulid)
            ->assertSeeHtml('event.isComposing || event.keyCode === 229')
            ->assertSeeHtml("includes('text/uri-list')");
    }

    public function test_the_slide_over_leaves_escape_to_modals_and_reapplies_the_pinned_class(): void
    {
        $this->actingAs($this->user());

        $this->get('/admin/notes')
            ->assertSee('escapeTaken', false)
            ->assertSee("document.documentElement.classList.toggle('fchat-pinned', this.pinned)", false)
            ->assertSee('livewire:navigated', false);
    }

    public function test_links_stop_before_punctuation_and_escaped_quotes(): void
    {
        $link = fn (string $url): string => '<a href="'.$url.'" target="_blank" rel="noopener noreferrer" class="underline break-all">'.$url.'</a>';

        $this->assertSame('(see '.$link('https://x.com/a').').', (string) ChatText::toHtml('(see https://x.com/a).'));
        $this->assertSame('&quot;'.$link('https://x.com').'&quot;', (string) ChatText::toHtml('"https://x.com"'));
        $this->assertSame('&lt;'.$link('https://x.com').'&gt;', (string) ChatText::toHtml('<https://x.com>'));
        $this->assertSame('Go '.$link('https://x.com/a_(b)').', now!', (string) ChatText::toHtml('Go https://x.com/a_(b), now!'));
        $this->assertSame($link('https://x.com/?a=1&amp;b=2'), (string) ChatText::toHtml('https://x.com/?a=1&b=2'));
    }

    public function test_with_groups_off_the_manage_button_is_gone(): void
    {
        $me = $this->user();
        $group = $this->group($me, 'Team', $this->user());
        config(['filament-chat.features.groups' => false]);
        $this->actingAs($me);

        Livewire::test(ChatWindow::class)
            ->call('open', $group->ulid)
            ->assertViewHas('canManage', false);
    }

    public function test_with_mentions_off_old_mentions_are_not_highlighted(): void
    {
        $me = $this->user();
        $olga = $this->user(['name' => 'Olga']);
        $group = $this->group($me, 'Team', $olga);
        $this->send($group, $me, 'Hello @Olga');
        $this->actingAs($me);

        Livewire::test(ChatWindow::class)->call('open', $group->ulid)->assertSeeHtml('fchat-mention');

        config(['filament-chat.features.mentions' => false]);

        Livewire::test(ChatWindow::class)->call('open', $group->ulid)->assertDontSeeHtml('fchat-mention');
    }

    public function test_user_selects_search_with_a_limit_instead_of_loading_everyone(): void
    {
        $me = $this->user();
        $olga = $this->user(['name' => 'Olga Petrenko']);
        $this->user(['name' => 'Ivan Bondar']);
        $this->actingAs($me);

        $this->assertSame([$olga->id => 'Olga Petrenko'], ChatUsers::search('petre', $me));
        $this->assertSame([$olga->id => 'Olga Petrenko'], ChatUsers::labels([$olga->id]));
        $this->assertCount(1, ChatUsers::search('', $me, 1));

        Livewire::test(ChatWindow::class)
            ->callAction('newDirect', ['user_id' => $olga->id]);

        $this->assertSame(1, Conversation::query()->count());
    }
}
