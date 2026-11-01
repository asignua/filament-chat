<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Tests\Feature;

use Asignua\FilamentChat\Events\ChatUpdated;
use Asignua\FilamentChat\Livewire\ChatWindow;
use Asignua\FilamentChat\Models\Conversation;
use Asignua\FilamentChat\Pages\Chat;
use Asignua\FilamentChat\Repositories\ConversationRepository;
use Asignua\FilamentChat\Support\ChatManager;
use Asignua\FilamentChat\Support\ChatUsers;
use Asignua\FilamentChat\Support\Presence;
use Asignua\FilamentChat\Support\Realtime;
use Asignua\FilamentChat\Support\Tenancy;
use Asignua\FilamentChat\Tests\TestCase;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/**
 * Multi-tenancy: a person in two tenants has two separate chats.
 */
class TenancyTest extends TestCase
{
    private Team $acme;

    private Team $globex;

    /** In both teams. */
    private User $shared;

    private User $acmeOnly;

    private User $globexOnly;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->acme = Team::create(['name' => 'Acme', 'slug' => 'acme']);
        $this->globex = Team::create(['name' => 'Globex', 'slug' => 'globex']);
        $this->shared = $this->user(['name' => 'Sam Shared']);
        $this->acmeOnly = $this->user(['name' => 'Alice Acme']);
        $this->globexOnly = $this->user(['name' => 'Gary Globex']);
        $this->acme->members()->attach([$this->shared->id, $this->acmeOnly->id]);
        $this->globex->members()->attach([$this->shared->id, $this->globexOnly->id]);

        // The panel gets tenancy at run time (the shared suite panel has none).
        Filament::getPanel('admin')->tenant(Team::class, slugAttribute: 'slug');
    }

    protected function tearDown(): void
    {
        Filament::setTenant(null, isQuiet: true);
        app(ChatManager::class)->resolveTenantUsing = null;
        app(ChatManager::class)->tenantUsersUsing = null;

        parent::tearDown();
    }

    private function in(Team $team): void
    {
        Filament::setTenant($team, isQuiet: true);
    }

    public function test_a_conversation_is_stamped_with_the_current_tenant(): void
    {
        $this->in($this->acme);
        $conversation = $this->direct($this->shared, $this->acmeOnly);

        $this->assertSame($this->acme->getMorphClass(), $conversation->tenant_type);
        $this->assertSame((string) $this->acme->id, $conversation->tenant_id);
        $this->assertTrue($conversation->tenant->is($this->acme));
    }

    public function test_a_person_in_two_tenants_has_two_separate_lists(): void
    {
        $this->in($this->acme);
        $inAcme = $this->group($this->shared, 'Acme staff', $this->acmeOnly);
        $this->in($this->globex);
        $inGlobex = $this->group($this->shared, 'Globex staff', $this->globexOnly);
        $repository = app(ConversationRepository::class);

        $this->in($this->acme);
        $this->assertSame([$inAcme->id], $repository->listFor($this->shared)->pluck('id')->all());
        $this->assertNull($repository->findByUlid($inGlobex->ulid), 'another tenant\'s conversation does not exist here');

        $this->in($this->globex);
        $this->assertSame([$inGlobex->id], $repository->listFor($this->shared)->pluck('id')->all());
        $this->assertNull($repository->findByUlid($inAcme->ulid));
    }

    public function test_the_same_pair_gets_a_direct_conversation_per_tenant(): void
    {
        $other = $this->user();
        $this->acme->members()->attach($other->id);
        $this->globex->members()->attach($other->id);

        $this->in($this->acme);
        $a = $this->direct($this->shared, $other);
        $this->assertTrue($a->is($this->direct($this->shared, $other)), 'inside one tenant still one per pair');

        $this->in($this->globex);
        $b = $this->direct($this->shared, $other);

        $this->assertFalse($a->is($b));
        $this->assertSame((string) $this->globex->id, $b->tenant_id);
    }

    public function test_unread_counters_are_per_tenant(): void
    {
        $other = $this->user();
        $this->acme->members()->attach($other->id);
        $this->globex->members()->attach($other->id);

        $this->in($this->acme);
        $this->send($this->direct($this->shared, $other), $other, 'In Acme');
        $this->send($this->direct($this->shared, $other), $other, 'In Acme again');
        $this->in($this->globex);
        $this->send($this->direct($this->shared, $other), $other, 'In Globex');
        $repository = app(ConversationRepository::class);

        $this->assertSame(1, $repository->unreadTotalFor($this->shared));
        $this->in($this->acme);
        $this->assertSame(2, $repository->unreadTotalFor($this->shared));
        $this->assertCount(1, $repository->unreadByConversation($this->shared));
    }

    public function test_the_people_to_write_to_are_the_tenants_members(): void
    {
        $this->in($this->acme);

        $names = array_values(ChatUsers::search('', $this->shared));
        $this->assertSame(['Alice Acme'], $names);
        $this->assertSame([], ChatUsers::search('Gary'), 'search never finds another tenant\'s people');
        $this->assertNull(ChatUsers::findChattable($this->globexOnly->id));
        $this->assertSame([], ChatUsers::chattableIds([$this->globexOnly->id]));

        $this->in($this->globex);
        $this->assertSame(['Gary Globex'], array_values(ChatUsers::preload($this->shared)));
    }

    public function test_the_window_lists_only_the_current_tenants_conversations(): void
    {
        $this->in($this->acme);
        $this->group($this->shared, 'Acme staff', $this->acmeOnly);
        $this->in($this->globex);
        $this->group($this->shared, 'Globex staff', $this->globexOnly);

        $this->actingAs($this->shared);

        $this->in($this->acme);
        Livewire::test(ChatWindow::class)->assertSee('Acme staff')->assertDontSee('Globex staff');

        $this->in($this->globex);
        Livewire::test(ChatWindow::class)->assertSee('Globex staff')->assertDontSee('Acme staff');
    }

    public function test_a_conversation_of_another_tenant_cannot_be_opened_by_its_ulid(): void
    {
        $this->in($this->acme);
        $secret = $this->group($this->shared, 'Acme secret', $this->acmeOnly);
        $this->send($secret, $this->acmeOnly, 'Acme only text');

        $this->in($this->globex);
        $this->actingAs($this->shared);

        $this->assertFalse($this->shared->can('view', $secret), 'policy: not visible from another tenant');
        Livewire::test(ChatWindow::class)
            ->call('open', $secret->ulid)
            ->assertSet('conversation', null)
            ->assertDontSee('Acme only text');
    }

    public function test_presence_shows_only_the_tenants_people(): void
    {
        $this->in($this->acme);
        Presence::touch($this->acmeOnly);
        Presence::touch($this->globexOnly);

        $statuses = Presence::statuses([$this->acmeOnly, $this->globexOnly], $this->shared);

        $this->assertSame([$this->acmeOnly->id], array_keys($statuses));
    }

    public function test_the_event_and_the_listener_use_the_tenants_channel(): void
    {
        $this->in($this->acme);
        $conversation = $this->direct($this->shared, $this->acmeOnly);
        Event::fake([ChatUpdated::class]);

        $this->send($conversation, $this->acmeOnly, 'Hi');

        $segment = Tenancy::channelKey(['Workbench\App\Models\Team', (string) $this->acme->id]);
        Event::assertDispatched(ChatUpdated::class, fn (ChatUpdated $event): bool => $event->tenant === $segment
            && str_starts_with($event->broadcastOn()[0]->name, 'private-filament-chat.tenant.'.$segment.'.user.'));

        // The listener of the person points at the tenant they are in now …
        $this->assertStringContainsString('filament-chat.tenant.'.$segment.'.user.', Realtime::listener($this->shared));

        // … and at the other tenant's channel in the other tenant.
        $this->in($this->globex);
        $this->assertStringNotContainsString($segment, Realtime::listener($this->shared));
    }

    public function test_the_tenant_channel_is_open_only_to_the_tenants_members(): void
    {
        $segment = Tenancy::channelKey(['Workbench\App\Models\Team', (string) $this->acme->id]);

        $this->assertTrue(Tenancy::mayListen($this->acmeOnly, $segment));
        $this->assertTrue(Tenancy::mayListen($this->shared, $segment));
        $this->assertFalse(Tenancy::mayListen($this->globexOnly, $segment));
    }

    public function test_a_notification_links_into_its_own_tenant(): void
    {
        $this->in($this->acme);
        $conversation = $this->direct($this->shared, $this->acmeOnly);

        $this->in($this->globex);

        // The panel got its tenancy at run time, so the route has no {tenant} segment: it goes in the query.
        $this->assertStringContainsString('tenant='.$this->acme->id, Chat::urlFor($conversation));
    }

    public function test_scoping_can_be_switched_off(): void
    {
        $this->in($this->acme);
        $mine = $this->direct($this->shared, $this->acmeOnly);

        config(['filament-chat.tenancy.enabled' => false]);
        $this->in($this->globex);

        $this->assertFalse(Tenancy::active());
        $this->assertNotNull(app(ConversationRepository::class)->findByUlid($mine->ulid));
    }

    public function test_a_custom_resolver_scopes_without_panel_tenancy(): void
    {
        // A panel without tenancy …
        $panel = Filament::getPanel('admin');
        $panel->tenant(null);
        $current = $this->acme;
        app(ChatManager::class)->resolveTenantUsing = function () use (&$current): Team {
            return $current;
        };
        app(ChatManager::class)->tenantUsersUsing = fn ($query, Team $tenant) => $query->whereIn('id', $tenant->members()->select('users.id'));

        $a = $this->direct($this->shared, $this->acmeOnly);
        $current = $this->globex;
        $b = $this->direct($this->shared, $this->globexOnly);
        $repository = app(ConversationRepository::class);

        $this->assertSame([$b->id], $repository->listFor($this->shared)->pluck('id')->all());
        $this->assertSame(['Gary Globex'], array_values(ChatUsers::search('', $this->shared)));
        $this->assertInstanceOf(Conversation::class, $a);
    }

    public function test_without_tenancy_nothing_changes(): void
    {
        Filament::getPanel('admin')->tenant(null);

        $conversation = $this->direct($this->shared, $this->acmeOnly);

        $this->assertNull($conversation->tenant_type);
        $this->assertFalse(Tenancy::active());
        $this->assertCount(2, ChatUsers::search('', $this->shared), 'everybody else, whatever the team');
    }

    public function test_a_host_that_has_not_run_the_tenant_migration_keeps_working(): void
    {
        app(ChatManager::class)->tenantColumns = false;
        $this->in($this->acme);

        $conversation = $this->direct($this->shared, $this->acmeOnly);

        $this->assertFalse(Tenancy::active());
        $this->assertNull($conversation->tenant_type);
        $this->assertNotNull(app(ConversationRepository::class)->findByUlid($conversation->ulid));
    }
}
