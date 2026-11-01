<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Tests\Feature;

use Asignua\FilamentChat\Data\GroupData;
use Asignua\FilamentChat\Data\MessageData;
use Asignua\FilamentChat\Events\ChatUpdated;
use Asignua\FilamentChat\Livewire\ChatWindow;
use Asignua\FilamentChat\Models\Conversation;
use Asignua\FilamentChat\Pages\Chat;
use Asignua\FilamentChat\Repositories\ConversationRepository;
use Asignua\FilamentChat\Services\ChatService;
use Asignua\FilamentChat\Support\ChatManager;
use Asignua\FilamentChat\Support\ChatUsers;
use Asignua\FilamentChat\Support\Presence;
use Asignua\FilamentChat\Support\Realtime;
use Asignua\FilamentChat\Support\Tenancy;
use Asignua\FilamentChat\Tests\TestCase;
use Filament\Facades\Filament;
use Filament\View\PanelsRenderHook;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
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
        app(ChatManager::class)->authorizeTenantChannelUsing = null;

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

    // --- Fail closed: a tenancy panel page without a {tenant} (profile, registration, MFA…) -------------------------

    public function test_without_a_tenant_in_an_interactive_request_nothing_of_any_tenant_is_visible(): void
    {
        $this->in($this->acme);
        $conversation = $this->group($this->shared, 'Acme staff', $this->acmeOnly);
        $this->send($conversation, $this->acmeOnly, 'Acme text');
        $dm = $this->direct($this->shared, $this->acmeOnly);

        // The profile page: signed in, no tenant.
        Filament::setTenant(null, isQuiet: true);
        $this->actingAs($this->shared);
        $repository = app(ConversationRepository::class);

        $this->assertTrue(Tenancy::closed());
        $this->assertSame([], ChatUsers::search('', $this->shared), 'people search lists nobody');
        $this->assertSame([], ChatUsers::search('Gary'));
        $this->assertSame([], ChatUsers::chattableIds([$this->globexOnly->id, $this->acmeOnly->id]));
        $this->assertCount(0, $repository->listFor($this->shared));
        $this->assertSame(0, $repository->unreadTotalFor($this->shared));
        $this->assertNull($repository->findByUlid($conversation->ulid));
        $this->assertFalse($this->shared->can('view', $conversation));
        $this->assertFalse(Tenancy::owns($conversation));

        // Direct service / repository calls (a forged Livewire call) are refused.
        $service = app(ChatService::class);
        $this->assertThrowsInvalid(fn () => $service->startDirect($this->shared, $this->globexOnly));
        $this->assertThrowsInvalid(fn () => $service->createGroup(new GroupData('X', [$this->globexOnly->id]), $this->shared));
        $this->assertThrowsInvalid(fn () => $service->updateGroup($conversation, new GroupData('Acme staff', [$this->globexOnly->id])));
        $this->assertThrowsInvalid(fn () => $service->send($dm, $this->shared, new MessageData('from the profile page')));
        $this->assertFalse($service->markRead($conversation, $this->shared, 1));

        // The UI is not rendered.
        $html = $this->panelHooks();
        $this->assertStringNotContainsString('fchat-scope', $html);
        $this->assertStringNotContainsString('fchat-dock-button', $html);
        $this->assertSame(0, DB::table('chat_messages')->where('body', 'from the profile page')->count());
    }

    public function test_the_ui_renders_inside_a_tenant(): void
    {
        $this->in($this->acme);
        $this->actingAs($this->shared);

        $this->assertFalse(Tenancy::closed());
        $html = $this->panelHooks();
        $this->assertStringContainsString('fchat-scope', $html);
        $this->assertStringContainsString('fchat-dock-button', $html);
    }

    public function test_console_without_a_user_is_not_closed(): void
    {
        $this->in($this->acme);
        $conversation = $this->direct($this->shared, $this->acmeOnly);
        Filament::setTenant(null, isQuiet: true);

        $this->assertFalse(Tenancy::closed());
        $this->assertNotNull(app(ConversationRepository::class)->findByUlid($conversation->ulid));
    }

    public function test_someone_detached_from_the_tenant_gets_no_notification_text(): void
    {
        $this->in($this->acme);
        $conversation = $this->group($this->shared, 'Acme staff', $this->acmeOnly);
        $this->acme->members()->detach($this->acmeOnly->id);

        $this->send($conversation, $this->shared, 'Secret text');

        $this->assertSame(0, $this->acmeOnly->notifications()->count(), 'a detached member is still in the conversation but is told nothing');
        $this->acme->members()->attach($this->acmeOnly->id);
        $this->send($conversation, $this->shared, 'Hi @Alice Acme');
        $this->assertSame(1, $this->acmeOnly->notifications()->count(), 'a member of the tenant is notified');
    }

    public function test_a_plain_key_tenant_without_closures_fails_closed(): void
    {
        $panel = Filament::getPanel('admin');
        $panel->tenant(null);
        app(ChatManager::class)->resolveTenantUsing = fn (): string => 'acme';

        $this->assertSame([], ChatUsers::chattableIds([$this->acmeOnly->id]), 'people are not narrowed by guessing: nobody');
        $this->assertFalse(Tenancy::mayListen($this->acmeOnly, Tenancy::channelKey(['tenant', 'acme']) ?? ''));

        app(ChatManager::class)->authorizeTenantChannelUsing = fn ($user, string $segment): bool => true;
        $this->assertTrue(Tenancy::mayListen($this->acmeOnly, 'x-1'));
        app(ChatManager::class)->authorizeTenantChannelUsing = null;
    }

    public function test_a_members_relation_of_another_model_is_not_taken_for_the_users(): void
    {
        $tenant = TeamWithForeignMembers::query()->findOrFail($this->acme->id);
        $query = ChatUsers::query();

        Tenancy::limitUsersTo($query, $tenant);

        // `members()` lists Teams here: the plugin falls back to canAccessTenant() instead of mixing team ids with user ids.
        $this->assertEqualsCanonicalizing([$this->shared->id, $this->acmeOnly->id], $query->pluck('id')->all());
    }

    public function test_host_code_outside_a_tenant_route_chooses_the_tenant_explicitly(): void
    {
        $this->in($this->acme);
        $conversation = $this->direct($this->shared, $this->acmeOnly);

        // A webhook / controller on the same guard: signed in, no tenant → closed.
        Filament::setTenant(null, isQuiet: true);
        $this->actingAs($this->shared);
        $service = app(ChatService::class);
        $this->assertThrowsInvalid(fn () => $service->send($conversation, $this->shared, new MessageData('no tenant')));

        // The documented pattern: pick the tenant for the call …
        $sent = Tenancy::forTenant($this->acme, fn () => $service->send($conversation, $this->shared, new MessageData('as Acme')));
        $this->assertSame('as Acme', $sent->body);
        $made = Tenancy::forTenant($this->globex, fn () => $service->startDirect($this->shared, $this->globexOnly));
        $this->assertSame((string) $this->globex->id, $made->tenant_id, 'stamped with the chosen tenant');

        // … or look at everything without one.
        $this->assertNotNull(Tenancy::unscoped(fn () => app(ConversationRepository::class)->findByUlid($conversation->ulid)));
        $this->assertTrue(Tenancy::closed(), 'and back to closed afterwards');
    }

    public function test_belonging_reads_only_the_recipients_even_without_a_members_relation(): void
    {
        foreach (range(1, 40) as $i) {
            $this->user();
        }

        $tenant = TeamWithoutMembers::query()->findOrFail($this->acme->id);
        $conversation = $this->direct($this->shared, $this->acmeOnly);
        $conversation->tenant_type = $tenant->getMorphClass();
        $conversation->tenant_id = (string) $tenant->id;
        Relation::morphMap([$tenant->getMorphClass() => TeamWithoutMembers::class], merge: true);

        $loaded = 0;
        User::retrieved(function () use (&$loaded): void {
            $loaded++;
        });

        $ids = Tenancy::belonging($conversation, [$this->shared->id, $this->globexOnly->id]);

        $this->assertSame([$this->shared->id], $ids);
        $this->assertLessThanOrEqual(4, $loaded, 'the recipients are read (and asked), not all 43 users');
    }

    /**
     * What the panel's BODY_END and USER_MENU_BEFORE hooks print on a page.
     */
    private function panelHooks(): string
    {
        $panel = Filament::getPanel('admin');
        $hooks = (new \ReflectionProperty($panel, 'renderHooks'))->getValue($panel);
        $html = '';

        foreach ([PanelsRenderHook::BODY_END, PanelsRenderHook::USER_MENU_BEFORE] as $name) {
            foreach ($hooks[$name][''] ?? [] as $hook) {
                $html .= $hook();
            }
        }

        return $html;
    }

    private function assertThrowsInvalid(\Closure $call): void
    {
        try {
            $call();
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('InvalidArgumentException expected');
    }
}

class TeamWithoutMembers extends Model
{
    protected $table = 'teams';

    public function getMorphClass(): string
    {
        return 'team-without-members';
    }
}

class TeamWithForeignMembers extends Team
{
    protected $table = 'teams';

    /**
     * @return BelongsToMany<Team, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'team_user', 'team_id', 'user_id');
    }
}
