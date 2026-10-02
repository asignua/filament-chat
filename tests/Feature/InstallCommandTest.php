<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Tests\Feature;

use Asignua\FilamentChat\FilamentChatServiceProvider;
use Asignua\FilamentChat\Tests\TestCase;
use Illuminate\Support\Facades\File;

class InstallCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        File::delete(config_path('filament-chat.php'));
        File::delete(File::glob(database_path('migrations/*_create_filament_chat_tables.php')));
        File::delete(File::glob(database_path('migrations/*_add_reply_to_filament_chat_messages.php')));

        parent::tearDown();
    }

    public function test_it_publishes_the_config_and_the_migration_once(): void
    {
        $this->artisan('filament-chat:install')->assertSuccessful();

        $this->assertFileExists(config_path('filament-chat.php'));
        $this->assertCount(1, File::glob(database_path('migrations/*_create_filament_chat_tables.php')));
        $this->assertCount(1, File::glob(database_path('migrations/*_add_reply_to_filament_chat_messages.php')));

        $this->artisan('filament-chat:install')
            ->expectsOutputToContain('already exists')
            ->assertSuccessful();

        $this->assertCount(1, File::glob(database_path('migrations/*_create_filament_chat_tables.php')));
    }

    public function test_a_host_from_1_1_gets_only_the_new_migration(): void
    {
        File::put(database_path('migrations/2026_01_01_000000_create_filament_chat_tables.php'), '<?php // published by 1.1');
        $this->rebootMigrationPublishing();

        $this->artisan('filament-chat:install')->assertSuccessful();

        $this->assertCount(1, File::glob(database_path('migrations/*_create_filament_chat_tables.php')));
        $this->assertSame('<?php // published by 1.1', File::get(database_path('migrations/2026_01_01_000000_create_filament_chat_tables.php')));
        $this->assertCount(1, File::glob(database_path('migrations/*_add_reply_to_filament_chat_messages.php')));

        $this->artisan('filament-chat:install')->assertSuccessful();
        $this->assertCount(1, File::glob(database_path('migrations/*_add_reply_to_filament_chat_messages.php')));
    }

    /**
     * The provider lists the host's migrations once, at boot — in a real run the
     * 1.1 file already exists then; here the test app booted before it was written.
     */
    private function rebootMigrationPublishing(): void
    {
        $provider = $this->app->getProvider(FilamentChatServiceProvider::class);

        (fn () => $this->bootPackageMigrations())->call($provider);
    }
}
