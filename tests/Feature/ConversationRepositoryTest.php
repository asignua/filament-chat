<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Tests\Feature;

use Asignua\FilamentChat\Data\GroupData;
use Asignua\FilamentChat\Events\GroupMembersChanged;
use Asignua\FilamentChat\Models\Conversation;
use Asignua\FilamentChat\Repositories\ConversationRepository;
use Asignua\FilamentChat\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;

class ConversationRepositoryTest extends TestCase
{
    private function repository(): ConversationRepository
    {
        return app(ConversationRepository::class);
    }

    public function test_direct_conversation_is_one_per_pair_in_either_order(): void
    {
        $a = $this->user();
        $b = $this->user();

        $first = $this->repository()->findOrCreateDirect($a, $b);
        $second = $this->repository()->findOrCreateDirect($b, $a);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, Conversation::query()->count());
        $this->assertCount(2, $first->participants);
    }

    public function test_direct_conversation_with_oneself_is_refused(): void
    {
        $a = $this->user();

        $this->expectException(InvalidArgumentException::class);

        $this->repository()->findOrCreateDirect($a, $a);
    }

    public function test_unread_counts_only_foreign_messages_after_the_read_pointer(): void
    {
        $me = $this->user();
        $colleague = $this->user();
        $conversation = $this->direct($me, $colleague);

        $this->send($conversation, $me, 'mine');
        $first = $this->send($conversation, $colleague, 'one');
        $this->send($conversation, $colleague, 'two');

        $this->assertSame(2, $this->repository()->unreadTotalFor($me));
        $this->assertSame([$conversation->id => 2], $this->repository()->unreadByConversation($me));

        $this->repository()->markRead($conversation, $me, $first->id);

        $this->assertSame(1, $this->repository()->unreadIn($conversation, $me));
        $this->assertSame(0, $this->repository()->unreadTotalFor($colleague));
    }

    public function test_read_pointer_never_moves_back(): void
    {
        $me = $this->user();
        $colleague = $this->user();
        $conversation = $this->direct($me, $colleague);
        $first = $this->send($conversation, $colleague, 'one');
        $second = $this->send($conversation, $colleague, 'two');

        $this->assertTrue($this->repository()->markRead($conversation, $me, $second->id));
        $this->assertFalse($this->repository()->markRead($conversation, $me, $first->id));
        $this->assertFalse($this->repository()->markRead($conversation, $me, $second->id));
        $this->assertSame($second->id, $this->repository()->participant($conversation, $me)?->last_read_message_id);
    }

    public function test_group_needs_a_title_and_another_member(): void
    {
        $me = $this->user();

        try {
            $this->repository()->createGroup(new GroupData('', [$this->user()->id]), $me);
            $this->fail('A group without a title was created.');
        } catch (InvalidArgumentException) {
        }

        $this->expectException(InvalidArgumentException::class);

        $this->repository()->createGroup(new GroupData('Alone', [$me->id]), $me);
    }

    public function test_group_takes_only_chattable_users_and_always_the_creator(): void
    {
        $me = $this->user();
        $active = $this->user();
        $inactive = $this->user(['is_active' => false]);

        $group = $this->repository()->createGroup(new GroupData('Team', [$active->id, $inactive->id, 999]), $me);

        $this->assertEqualsCanonicalizing([$me->id, $active->id], $this->repository()->activeMemberIds($group));
    }

    public function test_history_before_joining_is_not_unread(): void
    {
        $me = $this->user();
        $colleague = $this->user();
        $newcomer = $this->user();
        $group = $this->group($me, 'Team', $colleague);
        $this->send($group, $colleague, 'before you came');

        $this->repository()->updateGroup($group, new GroupData('Team', [$colleague->id, $newcomer->id]));

        $this->assertSame(0, $this->repository()->unreadTotalFor($newcomer));

        $this->send($group, $colleague, 'welcome');

        $this->assertSame(1, $this->repository()->unreadTotalFor($newcomer));
    }

    public function test_removed_member_leaves_and_can_be_brought_back(): void
    {
        $me = $this->user();
        $colleague = $this->user();
        $other = $this->user();
        $group = $this->group($me, 'Team', $colleague, $other);

        $this->repository()->updateGroup($group, new GroupData('Team', [$other->id]));

        $this->assertFalse($this->repository()->participant($group, $colleague)?->isActive());

        $this->repository()->updateGroup($group, new GroupData('Team', [$other->id, $colleague->id]));

        $this->assertTrue($this->repository()->participant($group, $colleague)?->isActive());
    }

    public function test_messages_written_while_away_are_not_unread_after_coming_back(): void
    {
        $me = $this->user();
        $colleague = $this->user();
        $other = $this->user();
        $group = $this->group($me, 'Team', $colleague, $other);

        $this->repository()->updateGroup($group, new GroupData('Team', [$other->id]));
        $this->send($group, $other, 'while you were away');
        $this->send($group, $me, 'and this too');
        $this->repository()->updateGroup($group, new GroupData('Team', [$other->id, $colleague->id]));

        $this->assertSame(0, $this->repository()->unreadTotalFor($colleague));

        $this->send($group, $other, 'welcome back');

        $this->assertSame(1, $this->repository()->unreadTotalFor($colleague));
    }

    public function test_creator_stays_in_the_group_even_if_left_out_of_the_form(): void
    {
        $me = $this->user();
        $colleague = $this->user();
        $group = $this->group($me, 'Team', $colleague);

        $this->repository()->updateGroup($group, new GroupData('Renamed', [$colleague->id]));

        $this->assertContains($me->id, $this->repository()->activeMemberIds($group));
        $this->assertSame('Renamed', $group->refresh()->title);
    }

    public function test_leaving_stops_unread_counting(): void
    {
        $me = $this->user();
        $colleague = $this->user();
        $other = $this->user();
        $group = $this->group($me, 'Team', $colleague, $other);
        $this->send($group, $me, 'hello');

        $this->repository()->leave($group, $colleague);

        $this->assertSame(0, $this->repository()->unreadTotalFor($colleague));
    }

    public function test_membership_changes_fire_an_event(): void
    {
        Event::fake([GroupMembersChanged::class]);

        $me = $this->user(['name' => 'Anna']);
        $colleague = $this->user(['name' => 'Bohdan']);
        $group = $this->group($me, 'Team', $colleague);
        $this->repository()->leave($group, $colleague);

        Event::assertDispatched(GroupMembersChanged::class, fn (GroupMembersChanged $e): bool => $e->before === [] && $e->after === ['Anna', 'Bohdan']);
        Event::assertDispatched(GroupMembersChanged::class, fn (GroupMembersChanged $e): bool => $e->before === ['Anna', 'Bohdan'] && $e->after === ['Anna']);
    }

    public function test_list_is_newest_first_and_searchable_by_name_and_title(): void
    {
        $me = $this->user();
        $olga = $this->user(['name' => 'Olga Petrenko']);
        $ivan = $this->user(['name' => 'Ivan Shevchenko']);
        $withOlga = $this->direct($me, $olga);
        $withIvan = $this->direct($me, $ivan);
        $group = $this->group($me, 'Marketing', $olga, $ivan);

        $this->send($withOlga, $olga, 'first');
        $this->send($withIvan, $ivan, 'second');

        $list = $this->repository()->listFor($me);

        $this->assertSame([$withIvan->id, $withOlga->id, $group->id], $list->pluck('id')->all());
        $this->assertSame([$withOlga->id, $group->id], $this->repository()->listFor($me, 'petrenko')->pluck('id')->sort()->values()->all());
        $this->assertSame([$group->id], $this->repository()->listFor($me, 'market')->pluck('id')->all());
    }
}
