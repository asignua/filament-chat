# Filament Chat

Team chat for [Filament](https://filamentphp.com) panels: direct messages and groups, reactions,
read receipts, a slide-over dock you can pin next to any page, and messages that point at your
panel's records — drag a record link into the chat and it becomes a card.

- Direct messages (one per pair) and groups: title, members, leave; the creator manages the group.
- **@mentions**: type `@` for the list of members; a mention is highlighted and always rings the bell.
- **Editing**: ✏️ on your message or ↑ in an empty composer; edited messages are marked.
- Reactions (six emoji, one per person) and read receipts: ✓ sent, ✓✓ read (a group — by everyone).
- A full **Chat** page and a **top-bar button** with a slide-over; on wide screens the slide-over can
  be **pinned** as a split screen that stays open across pages.
- Unread counters in the navigation, on the button, in the browser tab title and on the favicon.
- **Real-time** over Laravel Echo (Reverb, Pusher, Ably) — or polling when there is no socket.
- A database (bell) notification on the first unread message of a conversation.
- **Record references**: register your resources, then attach a record with a picker, by dropping a
  link to its page into the chat, or with "Attach current" from the slide-over. `DiscussInChatAction`
  starts a conversation from a record page.
- Privacy by design: a conversation is visible to its members only — admins included.
- English and Ukrainian translations.

## Requirements

- PHP 8.3+
- Laravel 12 or 13
- Filament 5

## Installation

```bash
composer require asignua/filament-chat
php artisan vendor:publish --tag=filament-chat-migrations
php artisan migrate
```

The bell notifications use Laravel's `notifications` table — create it if your app does not have
it yet (`php artisan make:notifications-table`).

Register the plugin in your panel:

```php
use Asignua\FilamentChat\FilamentChatPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        // ...
        ->databaseNotifications()
        ->plugin(FilamentChatPlugin::make());
}
```

That's it: a **Chat** page appears in the navigation and a chat button next to the user menu.
The stylesheet is registered as a Filament asset — no custom theme needed. If you deploy with
`php artisan filament:assets`, run it after installing or upgrading the package.

## Configuration

### Who can be written to

By default everybody in the users table. Narrow it down with a query:

```php
FilamentChatPlugin::make()
    ->users(fn (Builder $query) => $query->where('is_active', true)),
```

### Names

The chat calls people by Filament's `HasName::getFilamentName()`, then by the `name` attribute.
Override it:

```php
FilamentChatPlugin::make()
    ->userName(fn (User $user): string => "{$user->first_name} {$user->last_name}"),
```

…and tell the conversation search which columns to look in (`config/filament-chat.php`):

```php
'users' => [
    'search_columns' => ['first_name', 'last_name'],
],
```

### Record references

Register the resources whose records a message may point at:

```php
use Asignua\FilamentChat\Support\References\ReferenceType;

FilamentChatPlugin::make()
    ->references([
        ReferenceType::resource(OrderResource::class),
        ReferenceType::resource(CustomerResource::class)->color('warning'),
    ]),
```

From the resource the chat takes the label, icon, record title, the link (edit page for those who
may edit, view page for the rest) and the search for the picker (globally searchable attributes).
Everything can be overridden:

```php
ReferenceType::make('invoice', Invoice::class)
    ->label('Invoice')
    ->icon(Heroicon::OutlinedDocumentCurrencyDollar)
    ->color('success')
    ->title(fn (Invoice $invoice): string => $invoice->number)
    ->url(fn (Invoice $invoice): ?string => InvoiceResource::getUrl('view', ['record' => $invoice]))
    ->search(fn (string $search) => Invoice::where('number', 'like', "%{$search}%")->limit(20)->get()),
```

The key (`order`, `invoice`) is what is stored with the message — keep it stable. A reference
respects your policies: a viewer without `view` rights sees the type but neither the title nor the link.

Add **Discuss in chat** to a record page:

```php
use Asignua\FilamentChat\Actions\DiscussInChatAction;

protected function getHeaderActions(): array
{
    return [DiscussInChatAction::make()];
}
```

### The dock

```php
FilamentChatPlugin::make()
    ->dock(false)          // no top-bar button and slide-over — only the Chat page
    ->pinnable(false)      // no "pin" (split screen)
    ->tabBadge(false)      // no unread count in the tab title / favicon
    ->color('fuchsia')     // accent of group avatars and author names
    ->navigationGroup('Work')
    ->navigationSort(10)
    ->slug('messages'),
```

### Real-time delivery

Without a websocket the chat polls (every 15 s for an open conversation, 60 s for the counter).
For instant delivery:

1. Set up broadcasting — e.g. [Laravel Reverb](https://laravel.com/docs/reverb):
   `php artisan install:broadcasting --reverb`.
2. Give Filament an Echo configuration (`config/filament.php`, publish it with
   `php artisan vendor:publish --tag=filament-config`):

   ```php
   'broadcasting' => [
       'echo' => [
           'broadcaster' => 'reverb',
           'key' => env('VITE_REVERB_APP_KEY'),
           'wsHost' => env('VITE_REVERB_HOST'),
           'wsPort' => env('VITE_REVERB_PORT'),
           'wssPort' => env('VITE_REVERB_PORT'),
           'forceTLS' => env('VITE_REVERB_SCHEME', 'https') === 'https',
           'enabledTransports' => ['ws', 'wss'],
           'authEndpoint' => '/broadcasting/auth',
       ],
   ],
   ```

   Any other way of putting `window.Echo` on the page works too.

Real-time is on automatically when the default broadcaster is `reverb`, `pusher` or `ably`
(`'realtime' => true|false` in the config forces it). Events go to the private channel
`filament-chat.user.{key}` — the package authorises it. By default the key is the user's primary key;
set `users.broadcast_key` to a public column (a ULID or UUID) if you prefer not to expose ids to
the websocket server. The event is broadcast immediately (`ShouldBroadcastNow`), no queue worker needed.

### Editing

```php
// config/filament-chat.php
'editing' => [
    'enabled' => true,
    'window' => 15, // minutes after sending; null — any time
],
```

Only the author edits, and only while still in the conversation. People newly mentioned by an edit
are notified; the text change itself reaches the others silently (the message shows "edited").

### Your own models and tables

Every model can be swapped for a subclass (say, to add activity logging) and every table renamed in
`config/filament-chat.php` — publish it with `php artisan vendor:publish --tag=filament-chat-config`.
`Asignua\FilamentChat\Events\GroupMembersChanged` is fired whenever group membership changes, with
the member names before and after — handy for an audit trail.

## Notes

- Messages and conversations are never deleted.
- Mentions are recognised by the members' names (`@Olga Green`), so a mention typed by hand works too.
- Whoever leaves a group keeps the history up to that moment and can no longer write.
- Conversation and record search uses `LIKE`: case-insensitive on MySQL/MariaDB and PostgreSQL;
  on SQLite only for ASCII letters.

## Testing

```bash
composer test      # PHPUnit
composer analyse   # Larastan
composer format    # Pint
npm run build      # rebuild resources/dist/filament-chat.css after changing views
```

## License

MIT. See [LICENSE.md](LICENSE.md).
