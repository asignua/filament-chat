<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

/**
 * One-time setup: config, migration, (optionally) the agent skill and a list
 * of what is left to do by hand.
 */
class InstallCommand extends Command
{
    protected $signature = 'filament-chat:install
        {--migrate : Run the migrations right away}
        {--skill : Link the agent skill into .claude/skills}';

    protected $description = 'Publish the Filament Chat config and migration, link the agent skill';

    public function handle(Filesystem $files): int
    {
        if (!$files->exists(config_path('filament-chat.php'))) {
            $this->call('vendor:publish', ['--tag' => 'filament-chat-config']);
        } else {
            $this->components->info('config/filament-chat.php already exists — kept.');
        }

        // laravel-package-tools maps an already published migration onto its existing file
        // and vendor:publish without --force never overwrites, so only the missing ones are added.
        $missing = array_filter(
            ['create_filament_chat_tables', 'add_reply_to_filament_chat_messages'],
            fn (string $name): bool => $files->glob(database_path("migrations/*_{$name}.php")) === [],
        );

        if ($missing !== []) {
            $this->call('vendor:publish', ['--tag' => 'filament-chat-migrations']);
        } else {
            $this->components->info('The chat migrations are already published — kept.');
        }

        if ($this->option('skill')) {
            $this->linkSkill($files);
        }

        if ($this->option('migrate')) {
            $this->call('migrate');
        }

        $this->newLine();
        $this->components->bulletList(array_filter([
            $this->hasNotificationsTable($files) ? null : 'Bell notifications need Laravel\'s table: php artisan make:notifications-table',
            $this->option('migrate') ? null : 'Run the migrations: php artisan migrate',
            'Register the plugin: ->plugin(FilamentChatPlugin::make()) (and ->databaseNotifications()) in your panel provider',
            'Real-time (optional): FILAMENT_CHAT_REALTIME=true + a Reverb/Pusher connection — see the README',
        ]));

        return self::SUCCESS;
    }

    /**
     * A relative symlink, so it resolves on the host and inside a container
     * that mounts the project elsewhere.
     */
    private function linkSkill(Filesystem $files): void
    {
        $target = base_path('.claude/skills/filament-chat');
        $source = 'vendor/asignua/filament-chat/resources/boost/skills/filament-chat';

        if ($files->exists($target) || is_link($target)) {
            $this->components->info('.claude/skills/filament-chat already exists — kept.');

            return;
        }

        $files->ensureDirectoryExists(dirname($target));
        symlink('../../'.$source, $target);

        $this->components->info('Linked the agent skill: .claude/skills/filament-chat');
    }

    private function hasNotificationsTable(Filesystem $files): bool
    {
        return $files->glob(database_path('migrations/*_create_notifications_table.php')) !== [];
    }
}
