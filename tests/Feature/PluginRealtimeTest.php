<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Tests\Feature;

use Asignua\FilamentChat\Events\ChatUpdated;
use Asignua\FilamentChat\FilamentChatPlugin;
use Asignua\FilamentChat\Support\ChatConfig;
use Asignua\FilamentChat\Tests\TestCase;
use Filament\Panel;
use Filament\PanelProvider;
use Illuminate\Support\Facades\Broadcast;
use Workbench\App\Providers\AdminPanelProvider;

/**
 * `.env` says "no realtime", the plugin says `->realtime()`: the plugin wins (README), and so
 * the channel must exist — panels are built after this package's provider has booted.
 */
class PluginRealtimeTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return array_map(
            fn (string $provider): string => $provider === AdminPanelProvider::class ? RealtimePanelProvider::class : $provider,
            parent::getPackageProviders($app),
        );
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('filament-chat.realtime.enabled', false);
    }

    public function test_the_plugin_overrides_env_and_the_channel_is_registered(): void
    {
        $this->assertTrue(ChatConfig::realtime());
        $this->assertNotNull(Broadcast::driver()->getChannels()->get(ChatUpdated::CHANNEL.'{key}'));
    }
}

class RealtimePanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->plugin(FilamentChatPlugin::make()->realtime());
    }
}
