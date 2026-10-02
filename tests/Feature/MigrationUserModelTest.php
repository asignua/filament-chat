<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Tests\Feature;

use Asignua\FilamentChat\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use ReflectionMethod;

class MigrationUserModelTest extends TestCase
{
    public function test_the_migration_points_foreign_keys_at_the_configured_user_model(): void
    {
        config(['filament-chat.users.model' => MigrationStaffMember::class]);

        $this->assertSame('staff_members', $this->usersTable());
    }

    public function test_without_a_configured_model_the_auth_model_is_used(): void
    {
        config(['filament-chat.users.model' => null]);

        $this->assertSame('users', $this->usersTable());
    }

    private function usersTable(): string
    {
        $migration = include __DIR__.'/../../database/migrations/create_filament_chat_tables.php.stub';

        return (string) (new ReflectionMethod($migration, 'usersTable'))->invoke($migration);
    }
}

class MigrationStaffMember extends Model
{
    protected $table = 'staff_members';
}
