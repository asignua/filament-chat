<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Tests\Feature;

use Asignua\FilamentChat\Data\MessageData;
use Asignua\FilamentChat\Livewire\ChatWindow;
use Asignua\FilamentChat\Services\ChatService;
use Asignua\FilamentChat\Support\ChatConfig;
use Asignua\FilamentChat\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Livewire\Livewire;

/**
 * A 1.1 host that updated the package but has not run the reply migration yet:
 * sending and the feed keep working, replies stay off.
 */
class WithoutReplyMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Model::shouldBeStrict();
    }

    protected function tearDown(): void
    {
        Model::shouldBeStrict(false);

        parent::tearDown();
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../workbench/database/migrations');

        $migration = include __DIR__.'/../../database/migrations/create_filament_chat_tables.php.stub';
        $migration->up();
    }

    public function test_replies_are_unavailable_without_the_column(): void
    {
        $this->assertFalse(ChatConfig::repliesAvailable());
        $this->assertFalse(ChatConfig::replies());
    }

    public function test_a_plain_message_is_sent(): void
    {
        $me = $this->user();
        $olga = $this->user();
        $direct = $this->direct($me, $olga);

        $message = app(ChatService::class)->send($direct, $me, MessageData::fromArray(['body' => 'Hello']));

        $this->assertSame('Hello', $message->fresh()?->body);
    }

    public function test_reply_to_is_ignored_and_the_message_still_goes_out(): void
    {
        $me = $this->user();
        $olga = $this->user();
        $direct = $this->direct($me, $olga);
        $original = $this->send($direct, $olga, 'Question');

        $reply = app(ChatService::class)->send($direct, $me, MessageData::fromArray(['body' => 'Answer', 'reply_to' => $original->ulid]));

        $this->assertSame('Answer', $reply->fresh()?->body);
        $this->actingAs($me);

        Livewire::test(ChatWindow::class)
            ->call('open', $direct->ulid)
            ->assertSee('Answer')
            ->assertDontSeeHtml('data-fchat-quote');
    }

    public function test_the_feed_renders_without_the_reply_button(): void
    {
        $me = $this->user();
        $olga = $this->user(['name' => 'Olga']);
        $direct = $this->direct($me, $olga);
        $original = $this->send($direct, $olga, 'Can you check the order?');
        $this->actingAs($me);

        Livewire::test(ChatWindow::class)
            ->call('open', $direct->ulid)
            ->assertSee('Can you check the order?')
            ->assertDontSeeHtml('startReply(')
            ->call('startReply', $original->ulid)
            ->assertSet('replyingTo', null);
    }
}
