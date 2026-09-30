<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Tests\Feature;

use Asignua\FilamentChat\Repositories\ConversationRepository;
use Asignua\FilamentChat\Services\ChatService;
use Asignua\FilamentChat\Support\ReadStatus;
use Asignua\FilamentChat\Tests\TestCase;

class ReadStatusTest extends TestCase
{
    public function test_direct_is_read_once_the_other_side_reads(): void
    {
        $me = $this->user();
        $colleague = $this->user();
        $conversation = $this->direct($me, $colleague);
        $message = $this->send($conversation, $me, 'hi');

        $this->assertFalse(ReadStatus::for($conversation->participants()->with('user')->get(), $me->id)->isRead($message->id));

        app(ConversationRepository::class)->markRead($conversation, $colleague, $message->id);

        $this->assertTrue(ReadStatus::for($conversation->participants()->with('user')->get(), $me->id)->isRead($message->id));
    }

    public function test_group_needs_everyone_and_names_who_has_not_read(): void
    {
        $me = $this->user();
        $olga = $this->user(['name' => 'Olga']);
        $ivan = $this->user(['name' => 'Ivan']);
        $group = $this->group($me, 'Team', $olga, $ivan);
        $message = $this->send($group, $me, 'hi');
        app(ConversationRepository::class)->markRead($group, $olga, $message->id);

        $status = ReadStatus::for($group->participants()->with('user')->get(), $me->id);

        $this->assertFalse($status->isRead($message->id));
        $this->assertSame(['Ivan'], $status->unreadBy($message->id));
        $this->assertSame(__('filament-chat::chat.status_unread_by', ['names' => 'Ivan']), $status->hint($message->id, true));
    }

    public function test_who_left_the_group_does_not_hold_the_status_back(): void
    {
        $me = $this->user();
        $olga = $this->user();
        $ivan = $this->user();
        $group = $this->group($me, 'Team', $olga, $ivan);
        $message = $this->send($group, $me, 'hi');
        app(ConversationRepository::class)->markRead($group, $olga, $message->id);
        app(ChatService::class)->leave($group, $ivan);

        $this->assertTrue(ReadStatus::for($group->participants()->with('user')->get(), $me->id)->isRead($message->id));
    }

    public function test_nobody_else_active_means_not_read(): void
    {
        $this->assertFalse(ReadStatus::for([], 1)->isRead(10));
    }
}
