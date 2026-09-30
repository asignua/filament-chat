<?php

declare(strict_types=1);

namespace Asignua\FilamentChat;

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
use Livewire\Livewire;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class FilamentChatServiceProvider extends PackageServiceProvider
{
    public static string $name = 'filament-chat';

    public function configurePackage(Package $package): void
    {
        $package
            ->name(static::$name)
            ->hasConfigFile()
            ->hasViews()
            ->hasTranslations()
            ->hasMigration('create_filament_chat_tables');
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
        FilamentAsset::register([
            Css::make('filament-chat', __DIR__.'/../resources/dist/filament-chat.css'),
        ], 'asignua/filament-chat');

        Livewire::component('filament-chat.chat-window', ChatWindow::class);
        Livewire::component('filament-chat.chat-dock', ChatDock::class);

        Gate::policy(ChatConfig::conversationModel(), ConversationPolicy::class);
        Gate::policy(ChatConfig::messageModel(), MessagePolicy::class);

        if (ChatConfig::realtime()) {
            // A person listens only to their own channel.
            Broadcast::channel(
                ChatUpdated::CHANNEL.'{key}',
                fn (Model $user, string $key): bool => ChatUsers::broadcastKey($user) === $key,
            );
        }
    }
}
