<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Tests\Feature;

use Asignua\FilamentChat\Tests\TestCase;
use Illuminate\Support\Facades\File;

class InstallCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        File::delete(config_path('filament-chat.php'));
        File::delete(File::glob(database_path('migrations/*_create_filament_chat_tables.php')));

        parent::tearDown();
    }

    public function test_it_publishes_the_config_and_the_migration_once(): void
    {
        $this->artisan('filament-chat:install')->assertSuccessful();

        $this->assertFileExists(config_path('filament-chat.php'));
        $this->assertCount(1, File::glob(database_path('migrations/*_create_filament_chat_tables.php')));

        $this->artisan('filament-chat:install')
            ->expectsOutputToContain('already exists')
            ->assertSuccessful();

        $this->assertCount(1, File::glob(database_path('migrations/*_create_filament_chat_tables.php')));
    }
}
