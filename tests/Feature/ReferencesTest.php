<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Tests\Feature;

use Asignua\FilamentChat\Actions\DiscussInChatAction;
use Asignua\FilamentChat\Livewire\ChatWindow;
use Asignua\FilamentChat\Models\Message;
use Asignua\FilamentChat\Support\ChatManager;
use Asignua\FilamentChat\Support\References\RecordUrlResolver;
use Asignua\FilamentChat\Tests\TestCase;
use Livewire\Livewire;
use Workbench\App\Filament\Resources\Notes\NoteResource;
use Workbench\App\Filament\Resources\Notes\Pages\EditNote;
use Workbench\App\Models\Note;

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
}
