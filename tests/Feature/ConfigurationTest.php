<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Tests\Feature;

use Asignua\FilamentChat\Data\GroupData;
use Asignua\FilamentChat\Enums\Reaction;
use Asignua\FilamentChat\Events\ChatUpdated;
use Asignua\FilamentChat\FilamentChatPlugin;
use Asignua\FilamentChat\Livewire\ChatWindow;
use Asignua\FilamentChat\Services\ChatService;
use Asignua\FilamentChat\Support\ChatConfig;
use Asignua\FilamentChat\Support\ChatUsers;
use Asignua\FilamentChat\Tests\TestCase;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Livewire\Livewire;

class ConfigurationTest extends TestCase
{
    public function test_groups_can_be_turned_off(): void
    {
        config(['filament-chat.features.groups' => false]);
        $me = $this->user();
        $this->actingAs($me);

        Livewire::test(ChatWindow::class)
            ->assertViewHas('groups', false)
            ->assertActionHidden('newGroup');

        $this->expectException(InvalidArgumentException::class);
        $this->group($me, 'Team', $this->user());
    }

    public function test_with_groups_off_an_existing_group_cannot_be_changed(): void
    {
        $me = $this->user();
        $colleague = $this->user();
        $group = $this->group($me, 'Team', $colleague);
        config(['filament-chat.features.groups' => false]);

        try {
            app(ChatService::class)->updateGroup($group, new GroupData('Renamed', [$colleague->id]));
            $this->fail('A group was changed while groups are off.');
        } catch (InvalidArgumentException) {
        }

        $this->assertSame('Team', $group->refresh()->title);
    }

    public function test_reactions_can_be_turned_off(): void
    {
        $me = $this->user();
        $conversation = $this->direct($me, $this->user());
        $message = $this->send($conversation, $me, 'hi');
        config(['filament-chat.features.reactions' => false]);
        $this->actingAs($me);

        Livewire::test(ChatWindow::class)
            ->call('open', $conversation->ulid)
            ->assertDontSeeHtml('title="'.__('filament-chat::chat.react').'"')
            ->call('react', $message->ulid, 'like');

        $this->assertSame(0, $message->reactions()->count());

        $this->expectException(InvalidArgumentException::class);
        app(ChatService::class)->react($conversation, $message, $me, Reaction::Like);
    }

    public function test_mentions_and_read_receipts_can_be_turned_off(): void
    {
        config(['filament-chat.features.mentions' => false, 'filament-chat.features.read_receipts' => false]);
        $me = $this->user();
        $olga = $this->user(['name' => 'Olga']);
        $conversation = $this->direct($me, $olga);
        $message = $this->send($conversation, $me, 'hey @Olga');
        $this->actingAs($me);

        $this->assertNull($message->mentions);

        Livewire::test(ChatWindow::class)
            ->call('open', $conversation->ulid)
            ->assertViewHas('mentionable', [])
            ->assertViewHas('readStatus', null)
            ->assertDontSeeHtml('title="'.__('filament-chat::chat.status_sent').'"');
    }

    public function test_name_attribute_from_the_config(): void
    {
        config(['filament-chat.users.name_attribute' => 'email']);

        $this->assertSame('olga@example.com', ChatUsers::name($this->user(['email' => 'olga@example.com'])));
    }

    public function test_plugin_setters_override_the_config(): void
    {
        $plugin = FilamentChatPlugin::make()->groups(false)->editing(true, 10)->realtime(false)->color('fuchsia')->slug('messages');

        $plugin->register(Filament::getPanel('admin'));

        $this->assertFalse(ChatConfig::groups());
        $this->assertSame(10, ChatConfig::editingWindow());
        $this->assertFalse(ChatConfig::realtime());
        $this->assertSame('fuchsia', ChatConfig::color());
        $this->assertSame('messages', ChatConfig::slug());
    }

    public function test_realtime_switch_accepts_env_strings_and_follows_the_broadcaster_when_unset(): void
    {
        config(['filament-chat.realtime.enabled' => 'false']);
        $this->assertFalse(ChatConfig::realtime());

        config(['filament-chat.realtime.enabled' => 'true']);
        $this->assertTrue(ChatConfig::realtime());

        config(['filament-chat.realtime.enabled' => null, 'broadcasting.default' => 'log']);
        $this->assertFalse(ChatConfig::realtime());

        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb' => ['driver' => 'reverb', 'key' => 'app-key']]);
        $this->assertTrue(ChatConfig::realtime());
    }

    public function test_events_go_through_the_configured_connection(): void
    {
        config(['filament-chat.realtime.connection' => 'second']);

        $this->assertSame(['second'], (new ChatUpdated(['1'], 'c'))->broadcastConnections());
    }

    public function test_the_plugin_brings_echo_only_when_asked(): void
    {
        config([
            'filament-chat.realtime.enabled' => true,
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb' => ['driver' => 'reverb', 'key' => 'app-key-123'],
            'filament-chat.realtime.client.port' => 8080,
        ]);
        $this->actingAs($this->user());

        $this->get('/admin/chat')
            ->assertOk()
            ->assertSee('window.EchoFactory', false)
            ->assertSee('app-key-123', false);

        config(['filament-chat.realtime.echo' => 'host']);

        $this->get('/admin/chat')->assertOk()->assertDontSee('app-key-123', false);

        config(['filament-chat.realtime.enabled' => false, 'filament-chat.realtime.echo' => 'plugin']);

        $this->get('/admin/chat')->assertOk()->assertDontSee('app-key-123', false);
    }

    public function test_the_dock_can_be_turned_off(): void
    {
        config(['filament-chat.ui.dock' => false]);
        $this->actingAs($this->user());

        $this->get('/admin/notes')
            ->assertOk()
            ->assertDontSee('filament-chat-toggle', false)
            ->assertDontSee('filament-chat-panel-window', false);
    }

    public function test_no_event_when_realtime_is_off(): void
    {
        config(['filament-chat.realtime.enabled' => false]);
        Event::fake([ChatUpdated::class]);
        $me = $this->user();

        $this->send($this->direct($me, $this->user()), $me, 'hi');

        Event::assertNotDispatched(ChatUpdated::class);
    }
}
