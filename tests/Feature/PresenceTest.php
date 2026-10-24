<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Tests\Feature;

use Asignua\FilamentChat\Livewire\ChatDock;
use Asignua\FilamentChat\Livewire\ChatWindow;
use Asignua\FilamentChat\Support\ChatManager;
use Asignua\FilamentChat\Support\Presence;
use Asignua\FilamentChat\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

/**
 * Who is online: a throttled heartbeat, "online within N seconds", the dot and its text.
 */
class PresenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Carbon::setTestNow('2026-10-25 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(ChatManager::class)->hidePresenceUsing = null;

        parent::tearDown();
    }

    public function test_the_heartbeat_is_throttled(): void
    {
        $user = $this->user();

        $this->assertTrue(Presence::touch($user));
        $this->assertFalse(Presence::touch($user), 'inside the window: no write');

        Carbon::setTestNow('2026-10-25 10:00:20');
        $this->assertFalse(Presence::touch($user));
        $this->assertSame('2026-10-25 10:00:00', $this->seenAt($user));

        Carbon::setTestNow('2026-10-25 10:00:31');
        $this->assertTrue(Presence::touch($user));
        $this->assertSame('2026-10-25 10:00:31', $this->seenAt($user));
        $this->assertSame(1, DB::table('chat_presences')->count(), 'one row per person');
    }

    public function test_online_means_seen_within_the_configured_seconds(): void
    {
        $viewer = $this->user();
        $user = $this->user();
        Presence::touch($user);

        $this->assertTrue(Presence::statuses([$user], $viewer)[$user->id]->online);

        Carbon::setTestNow('2026-10-25 10:01:29');
        $this->assertTrue(Presence::statuses([$user], $viewer)[$user->id]->online, '89 s');

        Carbon::setTestNow('2026-10-25 10:01:31');
        $status = Presence::statuses([$user], $viewer)[$user->id];
        $this->assertFalse($status->online, '91 s');
        $this->assertSame('2026-10-25 10:00:00', $status->lastSeen?->toDateTimeString());

        config(['filament-chat.presence.online_seconds' => 300]);
        $this->assertTrue(Presence::statuses([$user], $viewer)[$user->id]->online);
    }

    public function test_never_seen_people_have_no_status(): void
    {
        $this->assertSame([], Presence::statuses([$this->user()], $this->user()));
    }

    public function test_the_privacy_hook_hides_a_person(): void
    {
        $viewer = $this->user();
        $shy = $this->user();
        $open = $this->user();
        Presence::touch($shy);
        Presence::touch($open);
        app(ChatManager::class)->hidePresenceUsing = fn (Model $subject, ?Model $who): bool => $subject->is($shy);

        $this->assertSame([$open->id], array_keys(Presence::statuses([$shy, $open], $viewer)));
    }

    public function test_disabled_config_records_and_shows_nothing(): void
    {
        config(['filament-chat.presence.enabled' => false]);
        $me = $this->user();
        $colleague = $this->user();
        $this->direct($me, $colleague);

        $this->assertFalse(Presence::touch($colleague));
        $this->assertSame(0, DB::table('chat_presences')->count());

        $this->actingAs($me);
        Livewire::test(ChatWindow::class)
            ->assertDontSeeHtml('data-fchat-online')
            ->assertDontSeeHtml('data-fchat-presence')
            ->assertDontSeeHtml('wire:poll');
    }

    public function test_the_list_dot_and_the_header_text(): void
    {
        $me = $this->user(['name' => 'Anna']);
        $colleague = $this->user(['name' => 'Olga']);
        $conversation = $this->direct($me, $colleague);
        Presence::touch($colleague);
        $this->actingAs($me);

        Livewire::test(ChatWindow::class)
            ->assertSeeHtml('data-fchat-online')
            ->assertSeeHtml('aria-label="'.__('filament-chat::chat.online').'"')
            ->call('open', $conversation->ulid)
            ->assertSeeHtml('data-fchat-presence')
            ->assertSee(__('filament-chat::chat.online'));

        Carbon::setTestNow('2026-10-25 10:05:00');

        Livewire::test(ChatWindow::class)
            ->assertDontSeeHtml('data-fchat-online')
            ->call('open', $conversation->ulid)
            ->assertSee(__('filament-chat::chat.last_seen', ['time' => '5 minutes ago']));
    }

    public function test_the_group_header_counts_who_is_online(): void
    {
        $me = $this->user();
        $a = $this->user();
        $b = $this->user();
        $group = $this->group($me, 'Team', $a, $b);
        Presence::touch($a);
        $this->actingAs($me);

        Livewire::test(ChatWindow::class)
            ->call('open', $group->ulid)
            ->assertSeeHtml('data-fchat-online-count')
            ->assertSee(__('filament-chat::chat.online_count', ['count' => 1]));
    }

    public function test_the_window_and_the_dock_stamp_the_viewer(): void
    {
        $me = $this->user();
        $this->actingAs($me);

        Livewire::test(ChatDock::class);
        $this->assertSame('2026-10-25 10:00:00', $this->seenAt($me));

        Cache::flush();
        Carbon::setTestNow('2026-10-25 10:10:00');
        Livewire::test(ChatWindow::class)->call('heartbeat');
        $this->assertSame('2026-10-25 10:10:00', $this->seenAt($me));
    }

    public function test_the_window_polls_for_presence_even_in_real_time(): void
    {
        $this->actingAs($this->user());

        $this->assertTrue(config('filament-chat.realtime.enabled'));
        Livewire::test(ChatWindow::class)->assertSeeHtml('wire:poll.60s="heartbeat"');
    }

    public function test_without_the_migration_the_chat_works_as_before(): void
    {
        Schema::drop('chat_presences');
        app(ChatManager::class)->presenceTable = null;
        $me = $this->user();
        $this->direct($me, $this->user());

        $this->assertFalse(Presence::touch($me));

        $this->actingAs($me);
        Livewire::test(ChatWindow::class)->assertDontSeeHtml('data-fchat-online');
    }

    public function test_the_migration_keeps_indexes_short_and_the_table_name_configurable(): void
    {
        $migration = include __DIR__.'/../../database/migrations/create_filament_chat_presences_table.php.stub';
        Schema::drop('chat_presences');
        config(['filament-chat.tables.presences' => 'team_presences']);
        $migration->up();

        $this->assertTrue(Schema::hasTable('team_presences'));
        $this->assertSame(['user_id', 'last_seen_at'], Schema::getColumnListing('team_presences'));

        foreach (Schema::getIndexes('team_presences') as $index) {
            $this->assertLessThanOrEqual(64, strlen($index['name']), 'MySQL identifier limit');
            $this->assertSame([], array_filter($index['columns'], fn (string $column): bool => !in_array($column, ['user_id', 'last_seen_at'], true)));
        }

        $this->assertContains(['user_id'], array_column(array_filter(Schema::getIndexes('team_presences'), fn (array $index): bool => $index['primary']), 'columns'));

        $migration->down();
        $this->assertFalse(Schema::hasTable('team_presences'));
        Schema::create('chat_presences', function ($table): void {
            $table->unsignedBigInteger('user_id')->primary();
            $table->timestamp('last_seen_at')->nullable();
        });
    }

    private function seenAt(Model $user): ?string
    {
        $value = DB::table('chat_presences')->where('user_id', $user->getKey())->value('last_seen_at');

        return $value !== null ? Carbon::parse($value)->toDateTimeString() : null;
    }
}
