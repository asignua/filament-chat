<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Tests\Feature;

use Asignua\FilamentChat\Repositories\MessageRepository;
use Asignua\FilamentChat\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;

/**
 * Messages read for one conversation carry it already: hooks rendered per message
 * (e.g. an attachment's annotate button) ask for it, and an app with
 * Model::preventLazyLoading() would otherwise die on the chat page.
 */
class MessageConversationRelationTest extends TestCase
{
    protected function tearDown(): void
    {
        Model::preventLazyLoading(false);

        parent::tearDown();
    }

    public function test_listed_and_found_messages_carry_their_conversation(): void
    {
        $me = $this->user();
        $conversation = $this->direct($me, $this->user());
        $first = $this->send($conversation, $me, 'One');
        $this->send($conversation, $me, 'Two');

        Model::preventLazyLoading();
        $repository = app(MessageRepository::class);

        foreach ($repository->latest($conversation->fresh(), 10) as $message) {
            $this->assertTrue($message->relationLoaded('conversation'));
            $this->assertSame($conversation->id, $message->conversation->id);
        }

        $found = $repository->findInConversation($conversation, $first->ulid);
        $this->assertNotNull($found);
        $this->assertTrue($found->relationLoaded('conversation'));
    }
}
