<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Tests;

use Asignua\FilamentChat\Data\GroupData;
use Asignua\FilamentChat\Data\MessageData;
use Asignua\FilamentChat\FilamentChatServiceProvider;
use Asignua\FilamentChat\Models\Conversation;
use Asignua\FilamentChat\Models\Message;
use Asignua\FilamentChat\Services\ChatService;
use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\Facades\Filament;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider;
use Workbench\App\Models\Note;
use Workbench\App\Models\User;
use Workbench\App\Policies\NotePolicy;
use Workbench\App\Providers\AdminPanelProvider;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::policy(Note::class, NotePolicy::class);
        Filament::setCurrentPanel('admin');
    }

    protected function getPackageProviders($app): array
    {
        return [
            ActionsServiceProvider::class,
            BladeCaptureDirectiveServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            BladeIconsServiceProvider::class,
            FilamentServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            LivewireServiceProvider::class,
            NotificationsServiceProvider::class,
            SchemasServiceProvider::class,
            SupportServiceProvider::class,
            TablesServiceProvider::class,
            WidgetsServiceProvider::class,
            FilamentChatServiceProvider::class,
            AdminPanelProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('filament-chat.realtime.enabled', true);
        $app['config']->set('broadcasting.default', 'null');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../workbench/database/migrations');

        $migration = include __DIR__.'/../database/migrations/create_filament_chat_tables.php.stub';
        $migration->up();

        $reply = include __DIR__.'/../database/migrations/add_reply_to_filament_chat_messages.php.stub';
        $reply->up();
    }

    /**
     * @param array<string, mixed> $attributes
     */
    protected function user(array $attributes = []): User
    {
        return User::factory()->create($attributes);
    }

    protected function direct(User $a, User $b): Conversation
    {
        return app(ChatService::class)->startDirect($a, $b);
    }

    protected function group(User $creator, string $title, User ...$members): Conversation
    {
        return app(ChatService::class)->createGroup(
            new GroupData($title, array_map(fn (User $user): int => $user->id, $members)),
            $creator,
        );
    }

    protected function send(Conversation $conversation, User $author, string $body, ?Note $note = null): Message
    {
        return app(ChatService::class)->send($conversation, $author, new MessageData(
            $body,
            $note !== null ? 'note' : null,
            $note?->id,
        ));
    }
}
