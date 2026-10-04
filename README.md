# Filament Chat

[![Stand With Ukraine](https://raw.githubusercontent.com/vshymanskyy/StandWithUkraine/main/badges/StandWithUkraine.svg)](https://stand-with-ukraine.pp.ua)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/asignua/filament-chat.svg?style=flat-square)](https://packagist.org/packages/asignua/filament-chat)
[![Tests](https://img.shields.io/github/actions/workflow/status/asignua/filament-chat/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/asignua/filament-chat/actions/workflows/tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/asignua/filament-chat.svg?style=flat-square)](https://packagist.org/packages/asignua/filament-chat)
[![License](https://img.shields.io/packagist/l/asignua/filament-chat.svg?style=flat-square)](https://github.com/asignua/filament-chat/blob/main/LICENSE.md)
[![Plumb score](https://plumbphp.dev/badges/asignua/filament-chat/composite.svg)](https://plumbphp.dev/asignua/filament-chat)

<img class="filament-hidden" src="https://raw.githubusercontent.com/asignua/filament-chat/v1.2.0/art/cover.jpg" alt="Filament Chat">

Team chat for [Filament](https://filamentphp.com) panels. Direct messages and groups, @mentions,
editing, reactions, read receipts, a slide-over you can pin next to any page — and messages that
point at your panel's records: drag a link to a record into the chat and it becomes a card.

Works with or without a websocket server: turn real-time on with one environment variable
(Laravel Reverb, Pusher, Ably), or let the chat poll.

- [Screenshots](#screenshots)
- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration) — users, features, messages, interface, models & tables
- [Record references](#record-references) — which records, who sees them, drag & drop, "Discuss in chat"
- [Real-time delivery](#real-time-delivery) — Reverb, Docker, nginx, Pusher, polling
- [Notifications](#notifications)
- [Translations](#translations) — your language, your own wording
- [Upgrading from 1.1](#upgrading-from-11)
- [Customising](#customising) — own models, audit trail, events
- [Integration notes](#integration-notes) — custom themes, panels built by a package, several panels
- [Troubleshooting](#troubleshooting)
- [AI agents](#ai-agents)
- [Testing](#testing)

## Screenshots

The Chat page — conversations, reactions, read receipts and record cards:

![The Chat page](https://raw.githubusercontent.com/asignua/filament-chat/v1.2.0/art/chat-page.jpg)

The slide-over pinned next to a record page, with "Attach current":

![The slide-over next to a record](https://raw.githubusercontent.com/asignua/filament-chat/v1.2.0/art/slide-over.jpg)

@mentions with autocomplete:

![Mentions](https://raw.githubusercontent.com/asignua/filament-chat/v1.2.0/art/mention.jpg)

Dark mode:

![Dark mode](https://raw.githubusercontent.com/asignua/filament-chat/v1.2.0/art/chat-page-dark.jpg)

## Features

| | |
|---|---|
| **Conversations** | Direct messages (one per pair) and groups: title, members, leave. The creator manages a group; whoever leaves keeps the history up to that moment. A member added to a group — or added back after leaving — sees its whole history, including what was written while they were away; none of it counts as unread. |
| **@mentions** | Type `@` for the list of members. A mention is highlighted (your own name stands out) and always rings the bell. |
| **Editing** | ✏️ on your message or ↑ in an empty composer; edited messages are marked. Optional time window. |
| **Reactions** | Six emoji, one per person per message. |
| **Read receipts** | ✓ sent, ✓✓ read — in a group, read by every active member (the hint lists who has not). |
| **Replies** | ↩ on a message quotes it above your answer; a click on the quote jumps to the original, loading earlier messages if needed. |
| **Avatars** | Next to the last message of a series in groups, in the @ list and the member line. From Filament's avatar provider or `->avatarUsing()`; initials when there is no picture. |
| **New messages** | Opening a conversation scrolls to a line where the unread part starts (up to five pages back, otherwise it opens at the bottom); sending your own message clears the line. |
| **Record references** | Attach a record with a picker, by dropping a link to its page, or "Attach current" in the slide-over. |
| **Where** | A full **Chat** page and a **top-bar button** with a slide-over; on wide screens the slide-over can be **pinned** as a split screen that stays open across pages. |
| **Unread** | Counters in the navigation, on the button, in the browser tab title and on the favicon; a bell notification on the first unread message of a conversation and on every mention; a "Reply" toast. |
| **Privacy** | A conversation is visible to its members only — admins included. Messages are never deleted. |
| **Languages** | English, Ukrainian, German, Spanish, French, Italian, Dutch, Polish, Brazilian Portuguese, Turkish; [add yours](#translations). |

Every feature can be switched off.

## Requirements

- PHP 8.3+
- Laravel 12 or 13
- Filament 5

## Installation

```bash
composer require asignua/filament-chat
php artisan filament-chat:install --migrate
```

`filament-chat:install` publishes `config/filament-chat.php` and the migration (4 tables), runs the
migrations with `--migrate`, links the [agent skill](#ai-agents) with `--skill` and tells you what
is left. Bell notifications use Laravel's `notifications` table — `php artisan make:notifications-table`
if your app does not have it yet.

Register the plugin in your panel provider:

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

That's it: a **Chat** page in the navigation and a chat button next to the user menu. The chat
polls for news until you turn [real-time](#real-time-delivery) on.

The stylesheet ships compiled and is linked **after** your panel's theme — no custom theme and no
`@source` lines are needed. It is published by `php artisan filament:assets`; if you commit
published assets, re-run it after upgrading the package.

## Configuration

Every setting lives in `config/filament-chat.php` (commented — read it once) and can also be set on
the plugin, which wins. Closures — who can be written to, how people are called, per-type rules for
references — exist on the plugin only, so the config stays cacheable.

### Users

```php
FilamentChatPlugin::make()
    // Who can be written to and added to groups. Default: everybody in the users table.
    ->users(fn (Builder $query) => $query->where('is_active', true)->whereHas('roles'))
    // How a person is called. Default: users.name_attribute, then Filament's HasName, then `name`.
    ->userName(fn (User $user): string => "{$user->first_name} {$user->last_name}")
    // A person's avatar URL; null — initials. Default: Filament's avatar provider
    // (HasAvatar::getFilamentAvatarUrl(), the `avatar_url` attribute, then the panel's default provider).
    ->avatarUsing(fn (User $user): ?string => $user->profile_photo_url),
```

```php
// config/filament-chat.php
'users' => [
    'model' => null,                    // null = auth.providers.users.model
    'name_attribute' => null,           // e.g. 'full_name' — an attribute or accessor
    'search_columns' => ['name'],       // e.g. ['first_name', 'last_name'] — the conversation search
    'broadcast_key' => null,            // e.g. 'ulid' — names the private channel (default: the primary key)
],
```

### Features

| Key | Plugin | Default | Off means |
|---|---|---|---|
| `features.groups` | `->groups(false)` | `true` | direct messages only; "New group" disappears, the server refuses groups |
| `features.reactions` | `->reactions(false)` | `true` | no emoji picker or chips; the server refuses reactions |
| `features.mentions` | `->mentions(false)` | `true` | no `@` autocomplete, nothing highlighted or notified |
| `features.read_receipts` | `->readReceipts(false)` | `true` | no ✓ / ✓✓ |
| `features.avatars` | `->avatars(false)` | `true` | no pictures or initials next to messages, in the @ list or the member line |
| `features.replies` | `->replies(false)` | `true` | no ↩ button and no new quotes; quotes already stored stay visible |
| `features.editing.enabled` / `.window` | `->editing(true, 15)` | `true`, `null` | no editing; `window` — minutes after sending, `null` — any time |

Switching a feature off never deletes data.

### Messages

```php
'messages' => [
    'page_size' => 30,      // loaded at once and per "Show earlier messages"
    'max_length' => 5000,
],
```

### Interface

| Key | Plugin | Default |
|---|---|---|
| `ui.dock` | `->dock(false)` | `true` — the top-bar button and slide-over; off: the Chat page only |
| `ui.pinnable` | `->pinnable(false)` | `true` — "pin" the slide-over as a split screen (from `lg`) |
| `ui.tab_badge` | `->tabBadge(false)` | `true` — unread count in the tab title and on the favicon |
| `ui.color` | `->color('fuchsia')` | `primary` — group avatars and author names |
| `ui.slug` | `->slug('messages')` | `chat` |
| `ui.navigation.group` / `.sort` / `.icon` | `->navigationGroup()` / `->navigationSort()` / `->navigationIcon()` | `null` / `90` / chat bubbles |

`->navigationGroup()` and `->navigationIcon()` also accept enums and closures (translated group names).

## Record references

A message may point at a record of your panel — an order, a customer, a page. It shows as a card
with the record's icon, type and title, linking to its page.

### Which records

Register resources on the plugin:

```php
use Asignua\FilamentChat\Support\References\ReferenceType;

FilamentChatPlugin::make()
    ->references([
        ReferenceType::resource(OrderResource::class),
        ReferenceType::resource(CustomerResource::class)->color('warning'),
    ]),
```

From the resource the chat takes the label, icon, record title, the link (the edit page for those who
may edit, the view page for the rest) and the search of the picker (the globally searchable
attributes). Everything can be overridden:

```php
ReferenceType::make('invoice', Invoice::class)
    ->label('Invoice')
    ->icon(Heroicon::OutlinedDocumentCurrencyDollar)
    ->color('success')
    ->title(fn (Invoice $invoice): string => $invoice->number)
    ->url(fn (Invoice $invoice): ?string => InvoiceResource::getUrl('view', ['record' => $invoice]))
    ->search(fn (string $search) => Invoice::where('number', 'like', "%{$search}%")->limit(20)->get())
    ->searchColumns(['number', 'customer_name'])   // instead of ->search()
    ->visibleUsing(fn (Invoice $invoice): bool => auth()->user()->can('view', $invoice)),
```

The key (`invoice`; `order` for `ReferenceType::resource(OrderResource::class)`) is stored with the
message — keep it stable.

**Or every resource at once** — no list to maintain:

```php
// config/filament-chat.php
'references' => [
    'all_resources' => true,
    'except' => [ActivityLogResource::class, VisitorResource::class],
],

// or on the plugin
->references([
    ReferenceType::allResources()->except([ActivityLogResource::class]),
    ReferenceType::resource(OrderResource::class)->color('warning'), // explicit types win
]),
```

With "all resources" the key is the resource **slug** (`orders`, `activity-log/activity-logs`):
renaming a slug loses the link of old messages (they show "record deleted"). Register a type
explicitly where that matters.

`'references' => ['enabled' => false]` turns references off completely.

### Who sees a referenced record

A viewer who may not see the record gets its type only — no title, no link — and cannot attach it.
"May see" is `references.authorize` (or `->authorizeReferences()` on the plugin):

| Value | Rule | A model **without a policy** |
|---|---|---|
| `'policy'` (default) | the model's `view` policy | **hidden** |
| `'resource'` | the resource's `canView()` — Filament's own rule | visible to everyone in the panel |
| `false` | no check | visible |

> **Watch out.** Filament shows a resource whose model has no policy, but Laravel's `Gate` denies an
> ability nobody defined. With the default `'policy'`, records of such a resource are visible in
> the panel yet can never be attached — dropping a link says so. Either add a policy (`view` →
> `true` for a read-only resource) or switch to `'resource'`. A type's `->visibleUsing()` beats both.

### Attaching

- **Picker** — the 🔗 button next to the composer: type, then search.
- **Drag & drop** — drop any link to a record page into an open conversation: a table row, a link
  in a column, a global search result, the address bar. Links of resources that are not referenceable
  say so ("Orders cannot be attached to messages").
- **Attach current** — the slide-over opened on a record page offers that record in one click.

### Discuss in chat

A header action for record pages — to whom (a person or a group I am in) and the text; the record
is attached:

```php
use Asignua\FilamentChat\Actions\DiscussInChatAction;

protected function getHeaderActions(): array
{
    return [DiscussInChatAction::make()];
}
```

It shows only for records of a referenceable type; it works as a table row action too.

## Real-time delivery

The chat works both ways — pick per environment:

| `FILAMENT_CHAT_REALTIME` | Behaviour |
|---|---|
| `false` | polling: an open conversation every 15 s, the unread counter every 60 s (`polling.*`) |
| `true` | new messages, reads, reactions and group changes arrive instantly; toasts appear on any page |
| unset (`null`) | on when the default broadcaster is `reverb`, `pusher` or `ably` |

Events carry identifiers only; the components re-read the data, so policies — not the socket
payload — decide what anyone sees. Each person listens on a private channel
`filament-chat.user.{key}`, authorised by the package. Events are broadcast immediately
(`ShouldBroadcastNow`) — no queue worker needed; if the socket is down the message is still saved
and the counters catch up by polling.

### Laravel Reverb

```bash
php artisan install:broadcasting --reverb
```

```dotenv
BROADCAST_CONNECTION=reverb
FILAMENT_CHAT_REALTIME=true
```

```bash
php artisan reverb:start
```

That is all: by default (`realtime.echo = 'plugin'`) the plugin brings Laravel Echo itself — it
reuses the Echo that Filament already ships — configured from the broadcasting connection. It also
registers `/broadcasting/auth` if your app has not (`realtime.register_auth_route`).

**Where the browser connects.** PHP publishes to `REVERB_HOST:REVERB_PORT`; the browser connects to
the page's own host, protocol and default port unless told otherwise:

```dotenv
FILAMENT_CHAT_WS_HOST=ws.example.com   # empty = the page's host
FILAMENT_CHAT_WS_PORT=8080             # empty = 443 on https, 80 on http
FILAMENT_CHAT_WS_SCHEME=https          # empty = the page's protocol
```

- **Local, no Docker:** `reverb:start` on 8080 → `FILAMENT_CHAT_WS_PORT=8080`.
- **Docker / Sail:** PHP publishes to the service (`REVERB_HOST=reverb`, `REVERB_PORT=8080`), the
  browser to the published port (`FILAMENT_CHAT_WS_PORT=8080` or whatever you map).
- **Production behind nginx:** proxy the websocket on the same host and leave the three variables
  empty:

  ```nginx
  location ^~ /app/ {
      proxy_pass http://reverb:8080;
      proxy_http_version 1.1;
      proxy_set_header Upgrade $http_upgrade;
      proxy_set_header Connection "upgrade";
      proxy_set_header Host $host;
      proxy_read_timeout 60s;
  }
  ```

### Pusher / Ably / another connection

Any Pusher-compatible connection works the same way (`BROADCAST_CONNECTION=pusher`). Pusher Channels
(cloud) is addressed by its cluster from `broadcasting.connections.pusher.options.cluster`.
To publish chat events on a connection other than the app's default:
`FILAMENT_CHAT_BROADCAST_CONNECTION=reverb`.

### Your own Echo

If your app already loads `window.Echo` (a Vite bundle), or you configured Filament's
`filament.broadcasting.echo`, set `FILAMENT_CHAT_ECHO=host` or `=filament` — the plugin then only
subscribes. An existing `window.Echo` is never replaced.

## Notifications

| Key | Default | |
|---|---|---|
| `notifications.database` | `true` | the bell: the first unread message of a conversation (the rest only grow the counter) and every mention. Needs `->databaseNotifications()`, the `notifications` table and `Notifiable` on the user model. |
| `notifications.toasts` | `true` | a "Reply" toast for a message in another conversation (real-time only) |

## Translations

The chat follows your app's locale and ships with English, Ukrainian, German, Spanish, French,
Italian, Dutch, Polish, Brazilian Portuguese and Turkish. Every string lives in one file per language,
e.g. [`resources/lang/en/chat.php`](https://github.com/asignua/filament-chat/blob/main/resources/lang/en/chat.php);
dates use the same locale (month names come from Carbon).

**Another language** — create `lang/vendor/filament-chat/{locale}/chat.php` in your app with the same
keys (copy the English file and translate it):

```php
// lang/vendor/filament-chat/cs/chat.php
return [
    'chat' => 'Chat',
    'new_group' => 'Nová skupina',
    // ...
];
```

**Change a few phrases** — the same file with only the keys you want to change; the rest still comes
from the package:

```php
// lang/vendor/filament-chat/en/chat.php
return [
    'chat' => 'Messages',
];
```

**Copy all package translations** to edit them:

```bash
php artisan vendor:publish --tag=filament-chat-translations
```

Translated the chat into your language? A pull request with `resources/lang/{locale}/chat.php` is
very welcome.

## Upgrading from 1.1

1.2 adds one column (`reply_to_id`, replies) in a second migration, `add_reply_to_filament_chat_messages`.
Publish and run it:

```bash
php artisan vendor:publish --tag=filament-chat-migrations   # the 1.0/1.1 migration you already have is kept
php artisan migrate
```

(`php artisan filament-chat:install --migrate` does the same: it publishes whatever is missing.)

Without the new migration 1.2 keeps working, but replies stay off until you run it: the package checks
for the `reply_to_id` column.

The published migration reads the table name from `tables.messages`, so a renamed table is covered.
Only if your messages table was **not** created by the package's published migration (your app made it
some other way), add the column yourself, replacing `chat_messages` with your table name:

```php
Schema::table('chat_messages', function (Blueprint $table): void {
    $table->foreignId('reply_to_id')->nullable()->constrained('chat_messages')->nullOnDelete();
});
```

Avatars and the "New messages" line need no migration. Don't want avatars or replies? Switch them off
(`features.avatars`, `features.replies`) — stored quotes stay visible either way.

## Customising

### Your own models and tables

Swap any model for a subclass and rename the tables **before** running the migration:

```php
'models' => [
    'conversation' => App\Models\ChatConversation::class, // extends Asignua\FilamentChat\Models\Conversation
    // participant, message, reaction
],
'tables' => [
    'conversations' => 'chat_conversations',
    // participants, messages, reactions
],
```

### An audit trail

Messages, reactions and read pointers are deliberately not audited — an audit feed would let admins
read other people's conversations. For conversations use a subclass with your logging trait, and
listen to `Asignua\FilamentChat\Events\GroupMembersChanged` (`$conversation`, `$before`, `$after` —
member names) for membership, which lives in a pivot the model diff cannot see.

### Events

| Event | When |
|---|---|
| `Asignua\FilamentChat\Events\ChatUpdated` | anything changed in a conversation — broadcast to members |
| `Asignua\FilamentChat\Events\GroupMembersChanged` | a group was created, its members changed, someone left |

### Writing from your code

```php
use Asignua\FilamentChat\Data\MessageData;
use Asignua\FilamentChat\Services\ChatService;

$chat = app(ChatService::class);
$conversation = $chat->startDirect($me, $colleague);
$chat->send($conversation, $me, MessageData::fromArray([
    'body' => 'Please check @Olga Green',
    'reference_type' => 'order',
    'reference_id' => $order->id,
]));

// A reply: the ulid of a message in the same conversation (a foreign one is dropped).
$chat->send($conversation, $colleague, MessageData::fromArray([
    'body' => 'Done',
    'reply_to' => $message->ulid,
]));
```

Always go through `ChatService` — it checks membership, stores mentions, broadcasts and notifies.

## Integration notes

- **Custom themes.** The stylesheet is linked after the panel's theme on purpose: a theme compiles the
  same Tailwind utilities (`.bg-white`) and, loaded later, would beat the chat's `dark:` variants.
- **A panel built by a package** (a CMS that owns its `PanelProvider`): add the plugin when the panel
  registers — `boot()` is too late, the panel's routes already exist:

  ```php
  // AppServiceProvider::register()
  $this->app->resolving(PanelRegistry::class, function (PanelRegistry $registry): void {
      $registry->get('cms', isStrict: false)?->plugin(FilamentChatPlugin::make());
  });
  ```

- **Several panels.** Register the plugin on one panel. Links in notifications lead to that panel.
- **Users of another panel** (customers in a client cabinet): narrow `->users()` to the people of
  the chat's panel.
- **Search** uses `LIKE`: case-insensitive on MySQL/MariaDB and PostgreSQL; on SQLite for ASCII only.

## Troubleshooting

| Symptom | Cause / fix |
|---|---|
| "… cannot be attached to messages" on a drop | the resource is not referenceable — register it or use `all_resources` |
| "This link does not lead to a record…" | not a record page of this panel (a list, another host) |
| A record shows its type but "no access" | `references.authorize = 'policy'` and the model has no policy — see [Who sees](#who-sees-a-referenced-record) |
| The slide-over is white on a dark panel | stale published assets — `php artisan filament:assets` |
| "Laravel Echo cannot be found" in the console | real-time is on but no Echo: `realtime.echo` is `host`/`filament` without one, or `window.EchoFactory` is missing (Filament's core scripts) |
| Messages arrive only after ~15 s | real-time is off (`FILAMENT_CHAT_REALTIME`), or the socket is not reachable from the browser — check `FILAMENT_CHAT_WS_*` and the browser's network tab |
| 403 on `/broadcasting/auth` | the user is not logged in on the `web` guard, or `broadcast_key` differs between server and channel |
| No bell notifications | `->databaseNotifications()` on the panel, the `notifications` table, `Notifiable` on the user |

## AI agents

The package ships a skill for coding agents (Claude Code and others reading `.claude/skills`) —
installation, every option, record references, real-time setups and the traps above:

```bash
php artisan filament-chat:install --skill   # links .claude/skills/filament-chat
```

It lives in `resources/boost/skills/filament-chat/SKILL.md`, where Laravel Boost looks for package skills.

## Testing

```bash
composer test      # PHPUnit (Orchestra Testbench)
composer analyse   # Larastan
composer format    # Pint
npm run build      # rebuild resources/dist/filament-chat.css after changing views
```

## License

MIT. See [LICENSE.md](https://github.com/asignua/filament-chat/blob/main/LICENSE.md).
