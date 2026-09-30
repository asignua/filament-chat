---
name: filament-chat
description: Use when installing, configuring or debugging asignua/filament-chat (team chat for Filament 5 panels) — registering the plugin, choosing who can chat, turning features on/off, making panel records attachable to messages (references, drag & drop, DiscussInChatAction), setting up real-time delivery with Reverb/Pusher or polling, or when the chat is unstyled/white on a dark panel, a dropped link "cannot be attached", a record shows "no access", "Laravel Echo cannot be found", messages arrive only after ~15 s, or /broadcasting/auth returns 403.
---

# Filament Chat (asignua/filament-chat)

Team chat inside a Filament 5 panel: direct messages, groups, @mentions, editing, reactions, read
receipts, a pinnable slide-over, unread badges, bell notifications, and messages that reference
panel records. Real-time over Laravel Echo (Reverb/Pusher/Ably) or polling.

Source of truth for options: `vendor/asignua/filament-chat/config/filament-chat.php` (every key is
commented) and `README.md` in the same package. Read them before guessing.

## Install — the whole sequence

```bash
composer require asignua/filament-chat
php artisan filament-chat:install --migrate      # config + migration (+ --skill links this skill)
php artisan make:notifications-table && php artisan migrate   # only if the app has no `notifications` table
```

```php
// the panel provider
->databaseNotifications()
->plugin(FilamentChatPlugin::make())
```

Verify: open `/{panel}/chat` — the Chat page renders and a chat button sits before the user menu.

If the project commits published assets (`public/css`, `public/js`), commit
`public/css/asignua/filament-chat/filament-chat.css` too and re-run `php artisan filament:assets`
after every package upgrade.

## Decide these three things with the user

1. **Who can chat** — default is *every row of the users table*. Almost always narrow it:
   `->users(fn (Builder $q) => $q->where('is_active', true))`, or by roles
   (`->whereHas('roles', fn ($r) => $r->whereIn('name', [...]))`). Customers of another panel must
   be excluded here.
2. **Which records can be attached** — none by default. Explicit list
   (`->references([ReferenceType::resource(OrderResource::class)])`) or all resources
   (`references.all_resources = true` + `references.except = [...]` for logs/statistics).
3. **Real-time or polling** — `FILAMENT_CHAT_REALTIME=true|false` (see below).

## Configuration map

Every config key has a plugin setter; the plugin wins. Closures exist only on the plugin.

| Need | Config key | Plugin |
|---|---|---|
| who can be written to | — | `->users(fn (Builder $q) => …)` |
| display name | `users.name_attribute` | `->userName(fn ($u) => …)` |
| conversation search columns | `users.search_columns` | — |
| private channel key (hide ids) | `users.broadcast_key` (e.g. `ulid`) | — |
| groups / reactions / mentions / ✓✓ | `features.groups` / `.reactions` / `.mentions` / `.read_receipts` | `->groups(false)` … `->readReceipts(false)` |
| editing, time window | `features.editing.enabled` / `.window` (minutes) | `->editing(true, 15)` |
| page size, max length | `messages.page_size` / `.max_length` | — |
| references on/off, visibility | `references.enabled` / `.authorize` | `->authorizeReferences('resource')` |
| all resources referenceable | `references.all_resources` / `.except` | `ReferenceType::allResources()->except([...])` |
| real-time | `realtime.enabled` / `.connection` / `.echo` / `.client.*` | `->realtime(true)` |
| polling seconds | `polling.conversation` / `.badge` | — |
| bell / toasts | `notifications.database` / `.toasts` | — |
| dock, pin, tab badge | `ui.dock` / `.pinnable` / `.tab_badge` | `->dock(false)` / `->pinnable(false)` / `->tabBadge(false)` |
| colour, slug, navigation | `ui.color` / `.slug` / `.navigation.*` | `->color()` / `->slug()` / `->navigationGroup()` / `->navigationSort()` / `->navigationIcon()` |
| own models / table names | `models.*` / `tables.*` | — (change tables BEFORE migrating) |

## Record references

- `ReferenceType::resource(XResource::class)` takes label, icon, title, URL (edit page if the user
  may edit, else view page) and picker search (globally searchable attributes) from the resource.
  Key = snake model basename (`order`) — it is stored in `messages.reference_type`; never change it.
- `ReferenceType::make('key', Model::class)->label()->icon()->color()->title(fn)->url(fn)->search(fn)
  ->searchColumns([...])->visibleUsing(fn)` for models without a resource or special rules.
- `all_resources`: key = resource slug. Renaming a slug orphans old references ("record deleted").
- Attaching: picker (🔗), drag & drop of any link to a record page, "Attach current" in the slide-over.
- `DiscussInChatAction::make()` — header or row action on record pages; visible only for
  referenceable records.

### The policy trap (most common support question)

`references.authorize` decides who sees a referenced record:

| value | rule | model WITHOUT a policy |
|---|---|---|
| `'policy'` (default) | `Gate::allows('view', $record)` | hidden — cannot be attached |
| `'resource'` | `Resource::canView($record)` (Filament's rule) | visible |
| `false` | none | visible |

Filament shows resources whose models have no policy, but Laravel's Gate denies undefined
abilities. Symptom: the record is in the panel, a drop says it cannot be attached / shows "no
access". Fix: add a policy (`view` → true for read-only resources) **or** set `'resource'`. Ask the
user which — a new policy changes the resource's own authorisation.

## Real-time

| `FILAMENT_CHAT_REALTIME` | behaviour |
|---|---|
| `false` | polling: open conversation 15 s, badge 60 s |
| `true` | instant delivery over Echo; toasts on any page |
| unset | on if the default broadcaster is reverb/pusher/ably |

Reverb, minimal:

```bash
php artisan install:broadcasting --reverb
# .env: BROADCAST_CONNECTION=reverb, FILAMENT_CHAT_REALTIME=true
php artisan reverb:start
```

- `realtime.echo = 'plugin'` (default): the plugin creates `window.Echo` from Filament's bundled
  `window.EchoFactory`, configured from the broadcasting connection. Nothing to build. An existing
  `window.Echo` is left alone. `'host'` = the app's own bundle provides Echo; `'filament'` =
  `filament.broadcasting.echo`.
- Browser address: `FILAMENT_CHAT_WS_HOST` / `_PORT` / `_SCHEME`; empty = the page's host/protocol,
  port 443/80. Docker: PHP publishes to `REVERB_HOST=reverb:8080`, the browser to the published
  port. Production: nginx `location ^~ /app/ { proxy_pass http://reverb:8080; … Upgrade … }` and
  leave the three empty.
- `/broadcasting/auth` is registered by the package when missing (`realtime.register_auth_route`).
- Events are `ShouldBroadcastNow` (no queue). Payload = ulids only; components re-read via policies.
- Channel: `private-filament-chat.user.{broadcast_key}`.

Verify in the browser console: `window.Echo.connector.pusher.connection.state === 'connected'`, then
send a message from another user (tinker: `app(ChatService::class)->send(...)`) — it must appear
without reload within a second.

## Hosts that are not a plain panel

- **A package owns the PanelProvider** (a CMS): no place to call `->plugin()`. `boot()` is too late
  (routes exist). Register while the panel registers:

  ```php
  // AppServiceProvider::register()
  $this->app->resolving(PanelRegistry::class, fn (PanelRegistry $r) =>
      $r->get('panel-id', isStrict: false)?->plugin(FilamentChatPlugin::make()));
  ```

  Check with `php artisan route:list --path=chat`.
- **Custom theme (Vite)**: nothing to add. The chat CSS is linked in `STYLES_AFTER`, after the theme.
  Do not add `@source` for the package.
- **Several panels**: register on one. Notification links point to it.

## Writing messages from code

Always through `Asignua\FilamentChat\Services\ChatService` (membership checks, mentions, broadcast,
notifications) — never `Message::create()`:

```php
$chat = app(ChatService::class);
$c = $chat->startDirect($from, $to);                 // or ->createGroup(new GroupData('Title', [$id, ...]), $creator)
$chat->send($c, $from, MessageData::fromArray(['body' => 'Hi @Olga Green', 'reference_type' => 'order', 'reference_id' => 42]));
$chat->edit($c, $message, $author, 'New text');
$chat->react($c, $message, $user, Reaction::Like);
```

Mentions are recognised server-side by member names (`@Olga Green`), longest name first; e-mail
addresses are not mentions.

## Troubleshooting

| Symptom | Check |
|---|---|
| chat unstyled / white on a dark panel | `php artisan filament:assets`; committed stale `public/css/asignua/…` |
| "X cannot be attached to messages" | resource not referenceable → register it or `all_resources` |
| record shows only its type / "no access" | the policy trap above |
| "Laravel Echo cannot be found" | realtime on, `realtime.echo` = host/filament without Echo on the page |
| messages after ~15 s | realtime off, or socket unreachable: browser network tab → ws URL; `FILAMENT_CHAT_WS_*` |
| 403 `/broadcasting/auth` | not logged in on `web`; custom guard → register `Broadcast::routes()` with that guard and set `register_auth_route` false |
| no bell | `->databaseNotifications()`, `notifications` table, `Notifiable` trait |
| Chat page 404 on a package-built panel | plugin added in `boot()` — move to `resolving(PanelRegistry)` in `register()` |
| toast/slide-over does not open in an automated browser | a hidden tab stalls `requestAnimationFrame` (Alpine transitions) — not a bug |

## Don't

- Don't edit `vendor/asignua/filament-chat`; override via config, plugin setters, model subclasses.
- Don't change a reference key or a resource slug used as a key without migrating
  `messages.reference_type`.
- Don't add audit logging to messages/reactions/participants — it exposes private conversations
  to whoever reads the audit log. Audit conversations via a model subclass +
  `GroupMembersChanged`.
- Don't run `migrate:fresh` in a host project to "reset" the chat; roll back the chat migration only.
