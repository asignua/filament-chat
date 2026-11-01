<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Tests\Feature;

use Asignua\FilamentChat\Events\ChatUpdated;
use Asignua\FilamentChat\Pages\Chat;
use Asignua\FilamentChat\Support\Tenancy;
use Asignua\FilamentChat\Tests\TestCase;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Workbench\App\Models\Team;

/**
 * Tenancy on a panel, but the 1.8 migration has not been run yet, with Eloquent in strict mode: the chat keeps working
 * (a plain read of the missing `tenant_type` would throw MissingAttributeException and silently drop every event).
 */
class WithoutTenantMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Model::shouldBeStrict();
        Filament::getPanel('admin')->tenant(Team::class, slugAttribute: 'slug');
        Filament::setTenant(Team::create(['name' => 'Acme', 'slug' => 'acme']), isQuiet: true);
    }

    protected function tearDown(): void
    {
        Model::shouldBeStrict(false);
        Filament::setTenant(null, isQuiet: true);

        parent::tearDown();
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../workbench/database/migrations');

        foreach (['create_filament_chat_tables', 'add_reply_to_filament_chat_messages', 'add_last_read_at_to_filament_chat_participants'] as $stub) {
            (include __DIR__.'/../../database/migrations/'.$stub.'.php.stub')->up();
        }
    }

    public function test_events_links_and_sending_work_without_the_tenant_columns(): void
    {
        $a = $this->user();
        $b = $this->user();
        Event::fake([ChatUpdated::class]);

        $conversation = $this->direct($a, $b);
        $this->send($conversation, $a, 'Hello');

        $fresh = app(\Asignua\FilamentChat\Repositories\ConversationRepository::class)->findByUlid($conversation->ulid);
        $this->assertFalse(Tenancy::columnsAvailable());
        $this->assertFalse(Tenancy::closed());
        $this->assertNull(Tenancy::channelKeyOf($fresh), 'no read of a missing attribute');
        Event::assertDispatched(ChatUpdated::class);
        $this->assertStringContainsString('chat', Chat::urlFor($fresh));
    }
}
