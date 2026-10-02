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
            ->assertViewHas('mentionable', function (array $people): bool {
                return count($people) === 7
                    && !in_array('Anna', array_column($people, 'name'), true)
                    && array_column($people, 'name') === ['Iryna L', 'Iryna M', 'Iryna P', 'Maria', 'Marta', 'Natalia', 'Olena']
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
            ->assertDontSeeHtml('ui-avatars.com');
    }
}
