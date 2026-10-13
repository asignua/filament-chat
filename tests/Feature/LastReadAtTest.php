<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Tests\Feature;

use Asignua\FilamentChat\Models\Participant;
use Asignua\FilamentChat\Repositories\ConversationRepository;
use Asignua\FilamentChat\Support\ChatConfig;
use Asignua\FilamentChat\Tests\TestCase;
use Illuminate\Support\Carbon;

/**
 * When a person last read a conversation — next to the read pointer, moved with it.
 */
class LastReadAtTest extends TestCase
{
    public function test_reading_stamps_the_time_and_only_a_moving_pointer_does(): void
    {
        $me = $this->user();
        $colleague = $this->user();
        $conversation = $this->direct($me, $colleague);
        $first = $this->send($conversation, $colleague, 'One');
        $repository = app(ConversationRepository::class);
        $this->assertTrue(ChatConfig::readTimeAvailable());

        Carbon::setTestNow('2026-10-13 10:00:00');
        $this->assertTrue($repository->markRead($conversation, $me, $first->id));
        $this->assertSame('2026-10-13 10:00:00', $this->participant($conversation->id, $me->id)->last_read_at?->toDateTimeString());

        Carbon::setTestNow('2026-10-13 11:00:00');
        $this->assertFalse($repository->markRead($conversation, $me, $first->id));
        $this->assertSame('2026-10-13 10:00:00', $this->participant($conversation->id, $me->id)->last_read_at?->toDateTimeString(), 'the pointer did not move');

        $second = $this->send($conversation, $colleague, 'Two');
        $this->assertTrue($repository->markRead($conversation, $me, $second->id));
        $this->assertSame('2026-10-13 11:00:00', $this->participant($conversation->id, $me->id)->last_read_at?->toDateTimeString());

        Carbon::setTestNow();
    }

    private function participant(int $conversationId, int $userId): Participant
    {
        return Participant::query()->where('conversation_id', $conversationId)->where('user_id', $userId)->firstOrFail();
    }
}
