<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Tests\Feature;

use Asignua\FilamentChat\Livewire\ChatWindow;
use Asignua\FilamentChat\Support\ChatManager;
use Asignua\FilamentChat\Support\ChatUsers;
use Asignua\FilamentChat\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Livewire\Livewire;

class AvatarsTest extends TestCase
{
    protected function tearDown(): void
    {
        app(ChatManager::class)->avatarUsing = null;

        parent::tearDown();
    }

    public function test_the_plugin_closure_wins(): void
    {
        $user = $this->user(['name' => 'Olga']);
        app(ChatManager::class)->avatarUsing = fn (Model $user): string => 'https://cdn.test/'.$user->getKey().'.png';

        $this->assertSame('https://cdn.test/'.$user->id.'.png', ChatUsers::avatar($user));
    }

    public function test_without_a_closure_filaments_avatar_provider_is_used(): void
    {
        $user = $this->user(['name' => 'Olga Green']);

        $this->assertStringContainsString('ui-avatars.com', (string) ChatUsers::avatar($user));
    }

    public function test_mention_list_has_every_active_member_but_me_with_avatars(): void
    {
        $me = $this->user(['name' => 'Anna']);
        $others = [];

        foreach (['Marta', 'Olena', 'Iryna L', 'Iryna M', 'Maria', 'Iryna P', 'Natalia'] as $name) {
            $others[] = $this->user(['name' => $name]);
        }
        $group = $this->group($me, 'Team', ...$others);
        $this->actingAs($me);

        Livewire::test(ChatWindow::class)
            ->call('open', $group->ulid)
            ->assertViewHas('mentionable', function (array $people) use ($others): bool {
                return count($people) === 7
                    && !in_array('Anna', array_column($people, 'name'), true)
                    && array_column($people, 'name') === ['Iryna L', 'Iryna M', 'Iryna P', 'Maria', 'Marta', 'Natalia', 'Olena']
                    && array_keys($people[0]) === ['key', 'name', 'avatar']
                    && $people[0]['key'] === $others[2]->id
                    && str_contains((string) $people[0]['avatar'], 'ui-avatars.com');
            });
    }

    public function test_avatars_can_be_turned_off(): void
    {
        config(['filament-chat.features.avatars' => false]);
        $me = $this->user();
        $olga = $this->user(['name' => 'Olga']);
        $ivan = $this->user(['name' => 'Ivan']);
        $group = $this->group($me, 'Team', $olga, $ivan);
        $this->send($group, $olga, 'Hi');
        $this->actingAs($me);

        Livewire::test(ChatWindow::class)
            ->call('open', $group->ulid)
            ->assertViewHas('avatarsOn', false)
            ->assertViewHas('avatars', [])
            ->assertViewHas('mentionable', fn (array $people): bool => $people[0]['avatar'] === null)
            ->assertDontSeeHtml('ui-avatars.com')
            ->assertSee('Hi')
            ->assertDontSeeHtml('data-fchat-avatar');
    }

    public function test_group_feed_shows_the_avatar_once_per_series(): void
    {
        app(ChatManager::class)->avatarUsing = fn (Model $user): string => 'https://cdn.test/u'.$user->getKey().'.png';
        $me = $this->user();
        $olga = $this->user(['name' => 'Olga']);
        $ivan = $this->user(['name' => 'Ivan']);
        $group = $this->group($me, 'Team', $olga, $ivan);
        $this->send($group, $olga, 'one');
        $this->send($group, $olga, 'two');
        $this->send($group, $ivan, 'three');
        $this->send($group, $me, 'mine');
        $this->actingAs($me);

        $html = Livewire::test(ChatWindow::class)->call('open', $group->ulid)->html();

        // Olga's series of two → one avatar, Ivan → one, my own message → none.
        $this->assertSame(1, substr_count($html, 'data-fchat-avatar src="https://cdn.test/u'.$olga->id.'.png"'));
        $this->assertSame(1, substr_count($html, 'data-fchat-avatar src="https://cdn.test/u'.$ivan->id.'.png"'));
        $this->assertStringNotContainsString('data-fchat-avatar src="https://cdn.test/u'.$me->id.'.png"', $html);
    }

    public function test_conversation_list_uses_the_plugin_closure(): void
    {
        app(ChatManager::class)->avatarUsing = fn (Model $user): string => 'https://cdn.test/u'.$user->getKey().'.png';
        $me = $this->user();
        $olga = $this->user(['name' => 'Olga']);
        $this->send($this->direct($me, $olga), $olga, 'hello');
        $this->actingAs($me);

        Livewire::test(ChatWindow::class)
            ->assertSeeHtml('src="https://cdn.test/u'.$olga->id.'.png"')
            ->assertDontSeeHtml('ui-avatars.com');
    }

    public function test_conversation_list_falls_back_to_initials_when_the_closure_returns_null(): void
    {
        app(ChatManager::class)->avatarUsing = fn (Model $user): ?string => null;
        $me = $this->user();
        $olga = $this->user(['name' => 'Olga']);
        $this->send($this->direct($me, $olga), $olga, 'hello');
        $this->actingAs($me);

        $html = Livewire::test(ChatWindow::class)->html();

        $this->assertStringNotContainsString('ui-avatars.com', $html);
        $this->assertMatchesRegularExpression('/<span data-fchat-list-avatar[^>]*>\s*O\s*<\/span>/', $html);
    }

    public function test_conversation_list_without_a_closure_uses_filaments_provider(): void
    {
        $me = $this->user();
        $olga = $this->user(['name' => 'Olga Green']);
        $this->send($this->direct($me, $olga), $olga, 'hello');
        $this->actingAs($me);

        Livewire::test(ChatWindow::class)->assertSeeHtml('ui-avatars.com');
    }

    public function test_direct_feed_has_no_avatars_next_to_messages(): void
    {
        app(ChatManager::class)->avatarUsing = fn (Model $user): string => 'https://cdn.test/u'.$user->getKey().'.png';
        $me = $this->user();
        $olga = $this->user(['name' => 'Olga']);
        $direct = $this->direct($me, $olga);
        $this->send($direct, $olga, 'hello');
        $this->actingAs($me);

        Livewire::test(ChatWindow::class)->call('open', $direct->ulid)->assertDontSeeHtml('data-fchat-avatar');
    }
}
