<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Tests\Feature;

use Asignua\FilamentChat\Actions\DiscussInChatAction;
use Asignua\FilamentChat\Livewire\ChatWindow;
use Asignua\FilamentChat\Models\Message;
use Asignua\FilamentChat\Support\ChatManager;
use Asignua\FilamentChat\Support\References\RecordUrlResolver;
use Asignua\FilamentChat\Support\References\ReferenceRegistry;
use Asignua\FilamentChat\Support\References\ReferenceType;
use Asignua\FilamentChat\Tests\TestCase;
use Livewire\Livewire;
use Workbench\App\Filament\Resources\Notes\NoteResource;
use Workbench\App\Filament\Resources\Notes\Pages\EditNote;
use Workbench\App\Filament\Resources\Users\UserResource;
use Workbench\App\Models\Note;
use Workbench\App\Models\User;

class ReferencesTest extends TestCase
{
    public function test_resource_reference_takes_label_title_and_url_from_the_resource(): void
    {
        $this->actingAs($this->user());
        $note = Note::query()->create(['title' => 'Release plan']);

        $presented = app(ChatManager::class)->references->present('note', $note->id);

        $this->assertSame('Note', $presented['type'] ?? null);
        $this->assertSame('Release plan', $presented['label'] ?? null);
        $this->assertSame(NoteResource::getUrl('edit', ['record' => $note]), $presented['url'] ?? null);
        $this->assertSame('warning', $presented['color'] ?? null);
    }

    public function test_hidden_and_deleted_records_keep_only_their_type(): void
    {
        $me = $this->user();
        $this->actingAs($me);
        $foreign = Note::query()->create(['title' => 'Secret', 'owner_id' => $this->user()->id]);
        $gone = Note::query()->create(['title' => 'Gone']);
        $goneId = $gone->id;
        $gone->delete();

        $hidden = app(ChatManager::class)->references->present('note', $foreign->id);
        $deleted = app(ChatManager::class)->references->present('note', $goneId);

        $this->assertSame(__('filament-chat::chat.reference_hidden'), $hidden['label'] ?? null);
        $this->assertNull($hidden['url'] ?? null);
        $this->assertSame(__('filament-chat::chat.reference_deleted'), $deleted['label'] ?? null);
    }

    public function test_record_page_urls_resolve_to_records(): void
    {
        $this->actingAs($this->user());
        $note = Note::query()->create(['title' => 'Plan']);

        $this->assertSame(['type' => 'note', 'id' => $note->id], RecordUrlResolver::resolve(NoteResource::getUrl('edit', ['record' => $note])));
        $this->assertSame(['type' => 'note', 'id' => $note->id], RecordUrlResolver::resolve('/admin/notes/'.$note->id.'/edit'));
    }

    public function test_foreign_or_non_record_urls_resolve_to_nothing(): void
    {
        $this->actingAs($this->user());
        $note = Note::query()->create(['title' => 'Plan']);
        $secret = Note::query()->create(['title' => 'Secret', 'owner_id' => $this->user()->id]);

        $this->assertNull(RecordUrlResolver::resolve('https://elsewhere.test/admin/notes/'.$note->id.'/edit'));
        $this->assertNull(RecordUrlResolver::resolve('/admin/notes'));
        $this->assertNull(RecordUrlResolver::resolve('/admin/notes/999/edit'));
        $this->assertNull(RecordUrlResolver::resolve('/admin/notes/'.$secret->id.'/edit'));
        $this->assertNull(RecordUrlResolver::resolve('not a url at all'));
    }

    public function test_dropped_link_is_attached_and_sent(): void
    {
        $me = $this->user();
        $conversation = $this->direct($me, $this->user());
        $note = Note::query()->create(['title' => 'Budget']);
        $this->actingAs($me);

        Livewire::test(ChatWindow::class)
            ->call('open', $conversation->ulid)
            ->call('attachUrl', NoteResource::getUrl('edit', ['record' => $note]))
            ->assertSet('referenceType', 'note')
            ->assertSet('referenceId', $note->id)
            ->set('body', 'See this')
            ->call('send')
            ->assertSet('referenceType', null)
            ->assertSee('Budget');

        $message = Message::query()->sole();
        $this->assertSame('note', $message->reference_type);
        $this->assertSame($note->id, $message->reference_id);
    }

    public function test_unsupported_drop_warns_and_attaches_nothing(): void
    {
        $me = $this->user();
        $conversation = $this->direct($me, $this->user());
        $this->actingAs($me);

        Livewire::test(ChatWindow::class)
            ->call('open', $conversation->ulid)
            ->call('attachUrl', 'https://example.com/whatever')
            ->assertSet('referenceType', null)
            ->assertNotified(__('filament-chat::chat.drop_unsupported'));
    }

    public function test_slide_over_on_a_record_page_offers_to_attach_it(): void
    {
        $me = $this->user();
        $conversation = $this->direct($me, $this->user());
        $note = Note::query()->create(['title' => 'Roadmap']);
        $this->actingAs($me);

        Livewire::test(ChatWindow::class, ['compact' => true, 'pageUrl' => NoteResource::getUrl('edit', ['record' => $note])])
            ->call('open', $conversation->ulid)
            ->assertSee(__('filament-chat::chat.add_current'))
            ->call('attachPage')
            ->assertSet('referenceId', $note->id);
    }

    public function test_attach_record_picker_searches_visible_records(): void
    {
        $me = $this->user();
        $conversation = $this->direct($me, $this->user());
        $note = Note::query()->create(['title' => 'Quarterly report']);
        $this->actingAs($me);

        Livewire::test(ChatWindow::class)
            ->call('open', $conversation->ulid)
            ->callAction('attachRecord', ['reference_type' => 'note', 'reference_id' => $note->id])
            ->assertSet('referenceId', $note->id);

        $this->assertSame([$note->id], app(ChatManager::class)->references->get('note')?->searchRecords('quarter')->modelKeys());
    }

    public function test_discuss_in_chat_from_a_record_page(): void
    {
        $me = $this->user();
        $colleague = $this->user();
        $note = Note::query()->create(['title' => 'Offer']);
        $this->actingAs($me);

        Livewire::test(EditNote::class, ['record' => $note->getRouteKey()])
            ->assertActionVisible(DiscussInChatAction::class)
            ->callAction(DiscussInChatAction::class, ['to' => 'user:'.$colleague->id, 'body' => 'Thoughts?'])
            ->assertHasNoActionErrors();

        $message = Message::query()->sole();
        $this->assertSame('Thoughts?', $message->body);
        $this->assertSame($note->id, $message->reference_id);
    }

    public function test_discuss_in_chat_refuses_a_foreign_group(): void
    {
        $me = $this->user();
        $foreign = $this->group($this->user(), 'Theirs', $this->user());
        $note = Note::query()->create(['title' => 'Offer']);
        $this->actingAs($me);

        Livewire::test(EditNote::class, ['record' => $note->getRouteKey()])
            ->callAction(DiscussInChatAction::class, ['to' => 'group:'.$foreign->ulid, 'body' => 'Hi'])
            ->assertHasActionErrors(['to']);

        $this->assertSame(0, Message::query()->count());
    }

    public function test_reference_authorization_modes(): void
    {
        $this->actingAs($this->user());
        $note = Note::query()->create(['title' => 'Plan']);
        $secret = Note::query()->create(['title' => 'Secret', 'owner_id' => 999]);
        $free = ReferenceType::make('person', User::class);
        $colleague = $this->user();
        $viaResource = app(ChatManager::class)->references->get('note');
        $this->assertNotNull($viaResource);

        // 'policy' (default): the policy decides, and a model without one is hidden.
        $this->assertTrue($viaResource->canView($note));
        $this->assertFalse($viaResource->canView($secret));
        $this->assertFalse($free->canView($colleague));

        // 'resource': Filament's rule — the resource asks the policy, a model without one is visible.
        config(['filament-chat.references.authorize' => 'resource']);
        $this->assertFalse($viaResource->canView($secret));
        $this->assertTrue($free->canView($colleague));

        // false: no check.
        config(['filament-chat.references.authorize' => false]);
        $this->assertTrue($viaResource->canView($secret));

        // A type's own rule wins over the config.
        $this->assertFalse(ReferenceType::make('none', Note::class)->visibleUsing(fn (): bool => false)->canView($note));
    }

    public function test_a_page_of_an_unregistered_resource_names_its_type(): void
    {
        $me = $this->user();
        $conversation = $this->direct($me, $this->user());
        $this->actingAs($me);

        $url = UserResource::getUrl('edit', ['record' => $me]);

        $this->assertNull(RecordUrlResolver::resolve($url));
        $this->assertSame('Users', RecordUrlResolver::unsupportedResourceLabel($url));
        $this->assertNull(RecordUrlResolver::unsupportedResourceLabel('/admin/notes/1/edit'));

        Livewire::test(ChatWindow::class)
            ->call('open', $conversation->ulid)
            ->call('attachUrl', $url)
            ->assertNotified(__('filament-chat::chat.drop_not_allowed', ['type' => 'Users']));
    }

    public function test_all_resources_mode_from_the_config(): void
    {
        // The workbench User has no policy: 'resource' (Filament's rule) lets it be seen.
        config(['filament-chat.references.all_resources' => true, 'filament-chat.references.authorize' => 'resource']);
        $me = $this->user(['name' => 'Olga']);
        $this->actingAs($me);
        $registry = new ReferenceRegistry;
        $registry->register(ReferenceType::resource(NoteResource::class)->color('warning'));

        // The explicit Note type stays as it is; users come from the panel, keyed by slug.
        $this->assertSame(['note', 'users'], array_keys($registry->all()));
        $this->assertSame('warning', $registry->get('note')?->getColor());
        $this->assertSame(['type' => 'users', 'id' => $me->id], $this->resolveWith($registry, UserResource::getUrl('edit', ['record' => $me])));

        config(['filament-chat.references.except' => [UserResource::class]]);
        $fresh = new ReferenceRegistry;
        $fresh->register(ReferenceType::allResources()->except([NoteResource::class]));

        $this->assertSame([], $fresh->all());
    }

    /**
     * @return array{type: string, id: int}|null
     */
    private function resolveWith(ReferenceRegistry $registry, string $url): ?array
    {
        $manager = app(ChatManager::class);
        $this->app->instance(ChatManager::class, new ChatManager($registry));
        app(ChatManager::class)->panelId = $manager->panelId;

        return RecordUrlResolver::resolve($url);
    }
}
