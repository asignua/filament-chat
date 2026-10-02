<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Tests\Feature;

use Asignua\FilamentChat\Data\MessageData;
use Asignua\FilamentChat\Services\ChatService;
use Asignua\FilamentChat\Tests\TestCase;

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
}
