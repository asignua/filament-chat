<?php

declare(strict_types=1);

namespace Asignua\FilamentChat;

use Asignua\FilamentChat\Commands\InstallCommand;
use Asignua\FilamentChat\Events\ChatUpdated;
use Asignua\FilamentChat\Livewire\ChatDock;
use Asignua\FilamentChat\Livewire\ChatWindow;
use Asignua\FilamentChat\Policies\ConversationPolicy;
use Asignua\FilamentChat\Policies\MessagePolicy;
use Asignua\FilamentChat\Repositories\ConversationRepository;
use Asignua\FilamentChat\Repositories\MessageRepository;
use Asignua\FilamentChat\Repositories\ReactionRepository;
use Asignua\FilamentChat\Services\ChatService;
use Asignua\FilamentChat\Support\ChatConfig;
use Asignua\FilamentChat\Support\ChatManager;
use Asignua\FilamentChat\Support\ChatUsers;
use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class FilamentChatServiceProvider extends PackageServiceProvider
{
    public static string $name = 'filament-chat';

    public const string PACKAGE = 'asignua/filament-chat';

    public const string STYLESHEET = 'filament-chat';

    public function configurePackage(Package $package): void
    {
        $package
            ->name(static::$name)
            ->hasConfigFile()
            ->hasViews()
            ->hasTranslations()
            ->hasMigrations(['create_filament_chat_tables', 'add_reply_to_filament_chat_messages'])
            ->hasCommand(InstallCommand::class);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(ChatManager::class);
        $this->app->singleton(ConversationRepository::class);
        $this->app->singleton(MessageRepository::class);
        $this->app->singleton(ReactionRepository::class);
        $this->app->singleton(ChatService::class);
    }

    public function packageBooted(): void
    {
        // Published by `filament:assets`, but linked by the plugin itself after the panel's
        // theme (FilamentChatPlugin::register) — see the note there.
        FilamentAsset::register([
            Css::make(self::STYLESHEET, __DIR__.'/../resources/dist/filament-chat.css')->loadedOnRequest(),
        ], self::PACKAGE);

        Livewire::component('filament-chat.chat-window', ChatWindow::class);
        Livewire::component('filament-chat.chat-dock', ChatDock::class);

        Gate::policy(ChatConfig::conversationModel(), ConversationPolicy::class);
        Gate::policy(ChatConfig::messageModel(), MessagePolicy::class);

        if (ChatConfig::realtime()) {
            // A person listens only to their own channel, and only while they may chat at all
            // (an archived or deactivated account loses it with the next auth request).
            Broadcast::channel(
                ChatUpdated::CHANNEL.'{key}',
                fn (Model $user, string $key): bool => ChatUsers::broadcastKey($user) === $key
                    && ChatUsers::query()->whereKey($user->getKey())->exists(),
            );

            // Private channels need /broadcasting/auth; many panels never set broadcasting up.
            $this->app->booted(function (): void {
                if (ChatConfig::registerAuthRoute() && !Route::has('broadcasting.auth')) {
                    Broadcast::routes(['middleware' => ['web', 'auth']]);
                }
            });
        }
    }
}
