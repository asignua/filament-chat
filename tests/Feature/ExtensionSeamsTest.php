<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Tests\Feature;

use Asignua\FilamentChat\Data\MessageData;
use Asignua\FilamentChat\Enums\ChatHook;
use Asignua\FilamentChat\Enums\Reaction;
use Asignua\FilamentChat\Events\MessageSent;
use Asignua\FilamentChat\FilamentChatPlugin;
use Asignua\FilamentChat\Livewire\ChatWindow;
use Asignua\FilamentChat\Models\Message;
use Asignua\FilamentChat\Repositories\MessageRepository;
use Asignua\FilamentChat\Services\ChatService;
use Asignua\FilamentChat\Support\ChatConfig;
use Asignua\FilamentChat\Support\ChatManager;
use Asignua\FilamentChat\Tests\TestCase;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\HtmlString;
use InvalidArgumentException;
use Livewire\Livewire;
use stdClass;
use Workbench\App\Livewire\ExtendedChatWindow;

class ExtensionSeamsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        ExtendedChatWindow::resetState();
    }

    // --- 1. The swappable window ---------------------------------------------------

    public function test_the_stock_window_is_the_default(): void
    {
        $this->assertSame(ChatWindow::class, ChatConfig::windowComponent());
    }

    public function test_the_window_class_comes_from_config_or_the_plugin_setter(): void
    {
        FilamentChatPlugin::make()->windowComponent(ExtendedChatWindow::class);
        $this->assertSame(ChatWindow::class, ChatConfig::windowComponent(), 'the setter only lands in config when the panel registers');

        config(['filament-chat.ui.window_component' => ExtendedChatWindow::class]);
        $this->assertSame(ExtendedChatWindow::class, ChatConfig::windowComponent());
    }

    public function test_a_class_that_is_not_a_window_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        /** @phpstan-ignore argument.type */
        FilamentChatPlugin::make()->windowComponent(stdClass::class);
    }

    public function test_a_bad_config_value_is_refused_when_read(): void
    {
        config(['filament-chat.ui.window_component' => stdClass::class]);

        $this->expectException(InvalidArgumentException::class);
        ChatConfig::windowComponent();
    }

    public function test_the_page_and_the_slide_over_mount_the_configured_window(): void
    {
        config(['filament-chat.ui.window_component' => ExtendedChatWindow::class]);
        $this->actingAs($this->user());

        $this->get('/admin/chat')->assertOk()->assertSeeLivewire(ExtendedChatWindow::class);
        $this->get('/admin/notes')->assertOk()->assertSeeLivewire(ExtendedChatWindow::class);
    }

    // --- 2. Render hooks ---------------------------------------------------------------

    public function test_every_place_renders_what_was_registered(): void
    {
        $me = $this->user();
        $colleague = $this->user();
        $conversation = $this->direct($me, $colleague);
        $message = $this->send($conversation, $colleague, 'Hello');
        $this->actingAs($me);

        $plugin = FilamentChatPlugin::get();

        foreach (ChatHook::cases() as $hook) {
            $plugin->renderHook($hook, fn (ChatWindow $window, array $context): string => '<i data-hook="'.$hook->value.'" data-conversation="'.($context['conversation']->ulid ?? '').'" data-message="'.(isset($context['message']) ? $context['message']->ulid : '').'"></i>');
        }

        $html = Livewire::test(ChatWindow::class)->call('open', $conversation->ulid)->html();

        foreach (ChatHook::cases() as $hook) {
            $this->assertStringContainsString('data-hook="'.$hook->value.'"', $html, $hook->value);
        }

        $this->assertStringContainsString('data-hook="message.menu" data-conversation="'.$conversation->ulid.'" data-message="'.$message->ulid.'"', $html);
        $this->assertStringContainsString('data-hook="message.body.after" data-conversation="'.$conversation->ulid.'" data-message="'.$message->ulid.'"', $html);
        $this->assertStringContainsString('data-hook="sidebar.before" data-conversation=""', Livewire::test(ChatWindow::class)->html(), 'the sidebar hook needs no open conversation');
    }

    public function test_a_hook_can_use_the_component_and_return_html_view_or_nothing(): void
    {
        $me = $this->user();
        $conversation = $this->direct($me, $this->user());
        $this->actingAs($me);
        $plugin = FilamentChatPlugin::get();

        $plugin->renderHook(ChatHook::COMPOSER_TOOLS, fn (ChatWindow $window): HtmlString => new HtmlString('<b data-first wire:click="cancelReply">'.$window::class.'</b>'));
        $plugin->renderHook(ChatHook::COMPOSER_TOOLS, fn (): ?string => null);
        $plugin->renderHook(ChatHook::COMPOSER_TOOLS, fn (): Renderable => new class implements Renderable
        {
            public function render(): string
            {
                return '<u data-renderable></u>';
            }
        });
        $plugin->renderHook(ChatHook::COMPOSER_TOOLS, fn (): string => '<b data-second></b>');

        $html = Livewire::test(ExtendedChatWindow::class)->call('open', $conversation->ulid)->html();

        $this->assertStringContainsString('data-first wire:click="cancelReply">'.ExtendedChatWindow::class, $html);
        $this->assertStringContainsString('data-renderable', $html);
        $this->assertLessThan(strpos($html, 'data-second'), strpos($html, 'data-first'), 'in registration order');
    }

    public function test_hooks_print_nothing_when_nothing_is_registered(): void
    {
        $hooks = app(ChatManager::class)->hooks;

        $this->assertFalse($hooks->has(ChatHook::HEADER_ACTIONS));
        $this->assertSame('', $hooks->render(ChatHook::HEADER_ACTIONS, new ChatWindow)->toHtml());
    }

    public function test_the_stock_window_has_no_hook_wrappers_without_hooks(): void
    {
        $me = $this->user();
        $conversation = $this->direct($me, $this->user());
        $this->actingAs($me);

        $html = Livewire::test(ChatWindow::class)->call('open', $conversation->ulid)->html();

        $this->assertStringNotContainsString('fchat-hook-', $html);
    }

    public function test_the_composer_dispatches_typing_and_paste_events(): void
    {
        $me = $this->user();
        $conversation = $this->direct($me, $this->user());
        $this->actingAs($me);

        $html = Livewire::test(ChatWindow::class)->call('open', $conversation->ulid)->html();

        $this->assertStringContainsString(ChatWindow::EVENT_TYPING, $html);
        $this->assertStringContainsString(ChatWindow::EVENT_PASTE, $html);
    }

    // --- 3. Protected extension methods --------------------------------------------------

    public function test_an_empty_message_is_refused_unless_the_window_allows_it(): void
    {
        $me = $this->user();
        $conversation = $this->direct($me, $this->user());
        $this->actingAs($me);

        Livewire::test(ExtendedChatWindow::class)
            ->call('open', $conversation->ulid)
            ->set('body', '')
            ->call('send')
            ->assertNotified(__('filament-chat::chat.error_empty'));

        $this->assertSame(0, Message::query()->count());

        ExtendedChatWindow::$withoutBody = true;

        Livewire::test(ExtendedChatWindow::class)
            ->call('open', $conversation->ulid)
            ->set('body', '   ')
            ->call('send')
            ->assertNotNotified(__('filament-chat::chat.error_empty'));

        $this->assertSame('', Message::query()->sole()->body);
    }

    public function test_the_stock_window_never_allows_an_empty_message(): void
    {
        $me = $this->user();
        $conversation = $this->direct($me, $this->user());
        $this->actingAs($me);
        ExtendedChatWindow::$withoutBody = true;

        Livewire::test(ChatWindow::class)->call('open', $conversation->ulid)->call('send')->assertNotified(__('filament-chat::chat.error_empty'));
        $this->assertSame(0, Message::query()->count());
    }

    public function test_the_service_takes_an_empty_body_only_when_told_to(): void
    {
        $me = $this->user();
        $conversation = $this->direct($me, $this->user());

        $this->assertSame('', app(ChatService::class)->send($conversation, $me, new MessageData('', allowEmpty: true))->body);
        $this->assertTrue(MessageData::fromArray(['allow_empty' => true])->allowEmpty);
        $this->assertFalse(MessageData::fromArray(['allow_empty' => 'yes'])->allowEmpty, 'only a real true');

        $this->expectException(InvalidArgumentException::class);
        app(ChatService::class)->send($conversation, $me, new MessageData(''));
    }

    public function test_the_extension_is_told_inside_the_transaction_and_after_the_send(): void
    {
        $me = $this->user();
        $conversation = $this->direct($me, $this->user());
        $this->actingAs($me);

        Livewire::test(ExtendedChatWindow::class)->call('open', $conversation->ulid)->set('body', 'Hi')->call('send');

        $ulid = Message::query()->sole()->ulid;
        $calls = array_values(array_filter(ExtendedChatWindow::$log, fn (string $line): bool => $line !== 'query'));
        $this->assertSame(['before:'.$ulid, 'after:'.$ulid], $calls);
    }

    public function test_a_failure_in_the_transaction_rolls_the_message_back(): void
    {
        $me = $this->user();
        $conversation = $this->direct($me, $this->user());
        $this->actingAs($me);
        ExtendedChatWindow::$failInTransaction = true;
        Event::fake([MessageSent::class]);

        Livewire::test(ExtendedChatWindow::class)
            ->call('open', $conversation->ulid)
            ->set('body', 'Hi')
            ->call('send')
            ->assertNotified('Extension refused')
            ->assertSet('body', 'Hi');

        $this->assertSame(0, Message::query()->count());
        $this->assertNull($conversation->refresh()->last_message_at);
        $this->assertNotContains(true, array_map(fn (string $line): bool => str_starts_with($line, 'after:'), ExtendedChatWindow::$log));
        Event::assertNotDispatched(MessageSent::class);
    }

    public function test_the_query_hook_shapes_every_feed_query(): void
    {
        $me = $this->user();
        $conversation = $this->direct($me, $this->user());
        $this->send($conversation, $me, 'One');
        $this->actingAs($me);

        Livewire::test(ExtendedChatWindow::class)->call('open', $conversation->ulid);

        $this->assertGreaterThanOrEqual(4, count(array_keys(ExtendedChatWindow::$log, 'query', true)), 'open + render: page, older, unread, read pointer');
    }

    public function test_the_repository_applies_a_scope_to_every_read(): void
    {
        $me = $this->user();
        $conversation = $this->direct($me, $this->user());
        $first = $this->send($conversation, $me, 'Keep');
        $second = $this->send($conversation, $me, 'Hide');
        $repository = app(MessageRepository::class);
        $scope = fn ($query) => $query->where('body', 'Keep');

        $this->assertSame([$first->id], $repository->latest($conversation, 10, null, $scope)->pluck('id')->all());
        $this->assertSame($first->id, $repository->latestId($conversation, null, $scope));
        $this->assertNull($repository->findInConversation($conversation, $second->ulid, $scope));
        $this->assertSame(0, $repository->countFrom($conversation, $second->id, null, $scope));
        $this->assertFalse($repository->hasOlderThan($conversation, $first->id, $scope));
        $this->assertNull($repository->findByUlid($second->ulid, $scope));
        $this->assertSame($second->id, $repository->latestId($conversation));
    }

    public function test_a_tombstone_hides_text_record_reactions_and_actions(): void
    {
        $me = $this->user();
        $colleague = $this->user();
        $conversation = $this->direct($me, $colleague);
        $secret = $this->send($conversation, $colleague, 'Secret words');
        $answer = app(ChatService::class)->send($conversation, $me, new MessageData('Quoting', replyTo: $secret->ulid));
        $this->actingAs($me);
        ExtendedChatWindow::$gone = [$secret->ulid];

        $window = Livewire::test(ExtendedChatWindow::class)->call('open', $conversation->ulid);

        $window->assertSee(__('filament-chat::chat.message_deleted'))
            ->assertDontSee('Secret words')
            ->assertSeeHtml('data-fchat-deleted')
            ->assertDontSeeHtml("startReply('{$secret->ulid}')")
            ->assertSeeHtml("startReply('{$answer->ulid}')");

        // The conversation list does not leak it either: the deleted message is the latest one's quote only.
        $this->assertStringNotContainsString('Secret words', $window->html());

        $window->call('react', $secret->ulid, Reaction::Heart->value)->call('startReply', $secret->ulid)->call('startEdit', $secret->ulid);
        $this->assertSame(0, $secret->reactions()->count());
        $window->assertSet('replyingTo', null)->assertSet('editing', null);
    }

    public function test_a_tombstone_as_the_latest_message_is_not_previewed_in_the_list(): void
    {
        $me = $this->user();
        $colleague = $this->user();
        $conversation = $this->direct($me, $colleague);
        $last = $this->send($conversation, $colleague, 'Private last words');
        $this->actingAs($me);
        ExtendedChatWindow::$gone = [$last->ulid];

        Livewire::test(ExtendedChatWindow::class)
            ->assertSee(__('filament-chat::chat.message_deleted'))
            ->assertDontSee('Private last words');
    }

    // --- 4. The event ------------------------------------------------------------------------

    public function test_sending_dispatches_message_sent_with_the_message(): void
    {
        Event::fake([MessageSent::class]);
        $me = $this->user();
        $conversation = $this->direct($me, $this->user());

        $message = $this->send($conversation, $me, 'Hello');

        Event::assertDispatched(MessageSent::class, fn (MessageSent $event): bool => $event->message->is($message));
    }

    public function test_message_sent_is_not_a_broadcast_event(): void
    {
        $this->assertNotInstanceOf(\Illuminate\Contracts\Broadcasting\ShouldBroadcast::class, new MessageSent(new Message));
    }

    public function test_message_sent_waits_for_the_hosts_transaction(): void
    {
        $seen = null;
        Event::listen(MessageSent::class, function (MessageSent $event) use (&$seen): void {
            $seen = Message::query()->whereKey($event->message->id)->exists();
        });
        $me = $this->user();
        $conversation = $this->direct($me, $this->user());

        $this->send($conversation, $me, 'Committed');

        $this->assertTrue($seen);
    }

    // --- 6. Jumping to a message of another conversation ------------------------------------

    public function test_open_message_opens_its_conversation_and_loads_up_to_it(): void
    {
        $me = $this->user();
        $colleague = $this->user();
        $first = $this->direct($me, $colleague);
        $second = $this->direct($me, $this->user());
        $old = $this->send($first, $colleague, 'Old one');

        for ($i = 0; $i < 3; $i++) {
            $this->send($first, $colleague, 'Newer '.$i);
        }

        $this->actingAs($me);
        config(['filament-chat.page_size' => 2, 'filament-chat.messages.page_size' => 2]);
        $pageSize = ChatConfig::pageSize();

        $window = Livewire::test(ChatWindow::class)->call('open', $second->ulid);
        $window->call('openMessage', $old->ulid)
            ->assertSet('conversation', $first->ulid)
            ->assertDispatched(ChatWindow::EVENT_HIGHLIGHT, message: $old->ulid)
            ->assertSee('Old one');

        $this->assertGreaterThanOrEqual(4, $window->get('limit'));
        $this->assertGreaterThanOrEqual(1, $pageSize);
    }

    public function test_open_message_works_inside_the_open_conversation_too(): void
    {
        $me = $this->user();
        $conversation = $this->direct($me, $this->user());
        $message = $this->send($conversation, $me, 'Here');
        $this->actingAs($me);

        Livewire::test(ChatWindow::class)
            ->call('open', $conversation->ulid)
            ->call('openMessage', $message->ulid)
            ->assertSet('conversation', $conversation->ulid)
            ->assertDispatched(ChatWindow::EVENT_HIGHLIGHT, message: $message->ulid);
    }

    public function test_open_message_refuses_a_message_of_a_foreign_conversation(): void
    {
        $foreign = $this->direct($this->user(), $this->user());
        $secret = $this->send($foreign, $foreign->participants()->first()->user, 'Not yours');
        $me = $this->user();
        $this->actingAs($me);

        Livewire::test(ChatWindow::class)
            ->call('openMessage', $secret->ulid)
            ->assertSet('conversation', null)
            ->assertNotDispatched(ChatWindow::EVENT_HIGHLIGHT)
            ->call('openMessage', 'no-such-ulid')
            ->assertSet('conversation', null);
    }

    public function test_open_message_does_not_reveal_what_a_leaver_may_not_see(): void
    {
        $me = $this->user();
        $other = $this->user();
        $group = $this->group($me, 'Team', $other);
        $this->actingAs($other);
        app(ChatService::class)->leave($group, $other);
        $this->travel(5)->seconds();
        $later = $this->send($group, $me, 'After you left');
        $this->actingAs($other);

        Livewire::test(ChatWindow::class)
            ->call('openMessage', $later->ulid)
            ->assertNotDispatched(ChatWindow::EVENT_HIGHLIGHT);
    }

    // --- 3. Conversation changes, tombstoned edits, the jump scroll ---------------------------

    public function test_conversation_changed_fires_on_open_switch_back_and_a_refused_open(): void
    {
        $me = $this->user();
        $a = $this->direct($me, $this->user());
        $b = $this->direct($me, $this->user());
        $this->actingAs($me);

        Livewire::test(ExtendedChatWindow::class)
            ->call('open', $a->ulid)
            ->call('open', $a->ulid)
            ->call('open', $b->ulid)
            ->call('back')
            ->call('open', 'no-such-conversation');

        $this->assertSame(['->'.$a->ulid, $a->ulid.'>'.$b->ulid, $b->ulid.'>-'], ExtendedChatWindow::$changes);
    }

    public function test_a_search_jump_into_another_conversation_does_not_scroll_to_the_unread_line(): void
    {
        $me = $this->user();
        $other = $this->user();
        $first = $this->direct($me, $other);
        $target = $this->send($first, $other, 'Needle');
        $this->send($first, $other, 'Later unread');
        $second = $this->direct($me, $this->user());
        $this->actingAs($me);

        Livewire::test(ExtendedChatWindow::class)
            ->call('open', $second->ulid)
            ->call('openMessage', $target->ulid)
            ->assertSet('conversation', $first->ulid)
            ->assertNotDispatched(ChatWindow::EVENT_SCROLL)
            ->assertDispatched(ChatWindow::EVENT_HIGHLIGHT, message: $target->ulid);
    }

    public function test_a_message_that_became_a_tombstone_cannot_be_saved_from_the_editor(): void
    {
        $me = $this->user();
        $conversation = $this->direct($me, $this->user());
        $message = $this->send($conversation, $me, 'Original');
        $this->actingAs($me);

        $window = Livewire::test(ExtendedChatWindow::class)
            ->call('open', $conversation->ulid)
            ->call('startEdit', $message->ulid)
            ->set('body', 'Changed');

        ExtendedChatWindow::$gone = [$message->ulid];
        $window->call('send')->assertSet('editing', null);

        $this->assertSame('Original', $message->fresh()?->body);
    }
}
