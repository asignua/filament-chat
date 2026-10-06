<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Tests\Feature;

use Asignua\FilamentChat\Models\Message;
use Asignua\FilamentChat\Repositories\ConversationRepository;
use Asignua\FilamentChat\Repositories\MessageRepository;
use Asignua\FilamentChat\Support\ChatConfig;
use Asignua\FilamentChat\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Workbench\App\Livewire\ExtendedChatWindow;
use Workbench\App\Models\SoftMessage;

/**
 * An extension swaps the message model for a subclass with SoftDeletes (the free
 * package adds none itself): everything must keep working with it.
 */
class SoftDeletedMessagesTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('filament-chat.models.message', SoftMessage::class);
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        Schema::table('chat_messages', function (Blueprint $table): void {
            $table->softDeletes();
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        ExtendedChatWindow::resetState();
    }

    public function test_the_configured_model_is_used_everywhere(): void
    {
        $me = $this->user();
        $conversation = $this->direct($me, $this->user());
        $reply = $this->send($conversation, $me, 'Original');

        $message = $this->send($conversation, $me, 'Hello');

        $this->assertInstanceOf(SoftMessage::class, $message);
        $this->assertInstanceOf(SoftMessage::class, $conversation->messages()->first());
        $this->assertInstanceOf(SoftMessage::class, $conversation->latestMessage()->first());
        $this->assertInstanceOf(SoftMessage::class, $message->replyTo()->getRelated());
        $this->assertInstanceOf(SoftMessage::class, app(MessageRepository::class)->findInConversation($conversation, $reply->ulid));
        $this->assertNotNull(Gate::getPolicyFor(SoftMessage::class));
        $this->assertSame(SoftMessage::class, ChatConfig::messageModel());
    }

    public function test_deleted_messages_are_hidden_unless_the_extension_includes_them(): void
    {
        $me = $this->user();
        $colleague = $this->user();
        $conversation = $this->direct($me, $colleague);
        $this->send($conversation, $colleague, 'Alive');
        $gone = $this->send($conversation, $colleague, 'Deleted body');
        $gone->delete();
        $this->actingAs($me);

        Livewire::test(ExtendedChatWindow::class)
            ->call('open', $conversation->ulid)
            ->assertSee('Alive')
            ->assertDontSee('Deleted body')
            ->assertDontSee(__('filament-chat::chat.message_deleted'));

        ExtendedChatWindow::$withTrashed = true;
        ExtendedChatWindow::$gone = [$gone->ulid];

        Livewire::test(ExtendedChatWindow::class)
            ->call('open', $conversation->ulid)
            ->assertSee('Alive')
            ->assertSee(__('filament-chat::chat.message_deleted'))
            ->assertDontSee('Deleted body');
    }

    public function test_a_deleted_message_is_not_unread(): void
    {
        $me = $this->user();
        $colleague = $this->user();
        $conversation = $this->direct($me, $colleague);
        $this->send($conversation, $colleague, 'One');
        $this->send($conversation, $colleague, 'Two')->delete();

        $this->assertSame(1, app(ConversationRepository::class)->unreadTotalFor($me));
    }

    public function test_open_message_finds_a_deleted_message_only_with_the_extension_scope(): void
    {
        $me = $this->user();
        $conversation = $this->direct($me, $this->user());
        $gone = $this->send($conversation, $me, 'Soon gone');
        $gone->delete();
        $this->actingAs($me);

        Livewire::test(ExtendedChatWindow::class)->call('openMessage', $gone->ulid)->assertSet('conversation', null);

        ExtendedChatWindow::$withTrashed = true;
        Livewire::test(ExtendedChatWindow::class)->call('openMessage', $gone->ulid)->assertSet('conversation', $conversation->ulid);
        $this->assertInstanceOf(Message::class, $gone);
    }
}
