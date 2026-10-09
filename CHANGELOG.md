# Changelog

All notable changes to `asignua/filament-chat` are documented here.

## v1.4.1 - unreleased

- Fixed: the composer lost Enter-to-send (including Ctrl/Cmd+Enter) and auto-grow in v1.4.0. A double quote inside a JS comment of its inline Alpine object ended the `x-data` attribute, so Alpine never built the component. A test now parses every inline Alpine object of the window as the browser does. A **published** `livewire/chat-window` view copied from v1.4.0 has the same bug: remove the double quotes from the comment in the composer `x-data`.

## v1.4.0 - 2026-10-08

- Fixed (CSS): the stylesheet emitted bare utilities (`.hidden`, `.flex`, `.bg-white` …) after the panel's theme and overrode the host's `hidden lg:block`, `dark:bg-gray-900` and similar on its own pages. Utilities are now scoped under `.fchat-scope`; the window root, the slide-over wrapper and the Chat page carry it, and the window root and the dock button use plain `.fchat` / `.fchat-dock-button` rules. A **published** `pages/chat` or `hooks/dock-panel` view needs the `fchat-scope` wrapper.
- Fixed: an unsent draft followed you into the next conversation and could be sent to the wrong person; it is cleared on a conversation change and on "back".
- Fixed: a browser tab in the background no longer marks messages as read (`visibilitychange` → `ChatWindow::setDocumentHidden()`); a tab opened in the background (middle-click on a link, a pinned dock) is reported hidden by the window's `x-init`. Until a report arrives the tab counts as visible, so a **published** `livewire/chat-window` view without the hook keeps the old behaviour (marks read) instead of never marking anything. To get the new behaviour, a published view's root needs: the `fchat-scope` class (next to `fchat`), `x-data`, `x-init="if (document.hidden) $wire.setDocumentHidden(true)"` and `x-on:visibilitychange.document="$wire.setDocumentHidden(document.hidden)"`.
- Performance: a read pointer move is broadcast only to the authors of the messages it passed (and the reader's own tabs), not to every member; with read receipts off only to the reader. The dock button skips the re-render for somebody else's read (`ChatUpdated::$reader`, set only on read events).
- Fixed: in SPA mode (`->spa()`) the pinned slide-over kept covering the page after the first navigation — the `fchat-pinned` class is re-applied.
- Fixed: `->realtime()` on the plugin now decides whether the private channel and `/broadcasting/auth` are registered (they were decided before the panel was built). The route and the channel use the panel's auth guard; Echo takes the URL of an existing `broadcasting.auth` route.
- Hardening: `attach()` takes only a record the person may view; `send()` re-checks it; `referenceType` / `referenceId` are `#[Locked]`.
- Fixed: Enter that confirms an IME candidate no longer sends the message; text can be dragged into the composer again (only links are taken as record references); Escape that closes a modal or dropdown no longer closes the slide-over.
- Fixed: links no longer swallow trailing punctuation, escaped quotes or angle brackets.
- Fixed: with `groups(false)` the "Manage group" button is hidden; with `mentions(false)` mentions in old messages are not highlighted. README states that existing groups keep working.
- Fixed: `ChatUpdated` waits for the commit of a caller's outer transaction (`ShouldDispatchAfterCommit`).
- Performance: the "New direct" and group member selects search on the server (50 results) instead of loading the whole users table (`ChatUsers::search()`, `ChatUsers::labels()`). The conversation list itself is still unbounded.

- Fixed: the conversation list ignored `->avatarUsing()` for direct conversations — it drew the person through `<x-filament-panels::avatar.user>`, i.e. the panel's avatar provider. It now uses the same URL as the feed, the @ list and the member line, and shows initials when the closure returns null.

## v1.3.0 - 2026-10-06

Extension seams for add-on packages; the chat itself does not change (README, Extending).

- `FilamentChatPlugin::windowComponent()` / `ui.window_component`: mount a subclass of `ChatWindow` on the Chat page and in the slide-over.
- Render hooks: the `ChatHook` enum (`SIDEBAR_BEFORE`, `HEADER_ACTIONS`, `FEED_BEFORE`, `MESSAGE_BODY_AFTER`, `MESSAGE_MENU`, `COMPOSER_BEFORE`, `COMPOSER_TOOLS`, `COMPOSER_AFTER`) and `FilamentChatPlugin::renderHook()`; markup renders inside the Livewire component.
- Protected methods on `ChatWindow`: `canSendWithoutBody()`, `beforeMessageCommit()`, `afterMessageSent()`, `modifyMessagesQuery()`, `isMessageTombstone()` (new string `message_deleted` in all ten languages). `MessageData::$allowEmpty` / `allow_empty` and `ChatService::send(…, ?Closure $inTransaction)` back them.
- `ChatWindow::openMessage()` opens the message's conversation first, so a search result can lead to another conversation.
- New event `Events\MessageSent` (not broadcast), dispatched after the commit.
- `MessageRepository` reads take an optional trailing `?Closure $scope`; new `findByUlid()`. If your app rebinds the repository and overrides these methods, add the parameter.
- A message model with `SoftDeletes` (an add-on's subclass of `models.message`) is supported: deleted messages are not unread.
- The composer dispatches browser events `filament-chat-typing` and `filament-chat-paste`.
- `ChatWindow::conversationChanged(?string $from, ?string $to)` seam; `openMessage()` no longer scrolls to the unread line when it opens another conversation (the jump to the message wins); `saveEdit()` refuses a message that became a tombstone; the list preview of a group you left no longer applies `modifyMessagesQuery()` (a soft-deleted last message drops out, like for everyone else).
- **Compatibility:** `ChatService::send()` has a new optional fourth parameter `?Closure $inTransaction` (an override must accept it); `MessageRepository` reads have a new trailing `?Closure $scope`. A **published** `pages/chat.blade.php` still mounts the stock window (it ignores `ui.window_component`), and a **published** `chat-window` view has none of the hook places — re-publish or merge them to use add-ons.
- The slide-over mounts the window through `@livewire(ChatConfig::windowComponent(), …)` instead of `<livewire:filament-chat.chat-window>` — a published copy of `hooks/dock-panel` keeps working with the stock window; update it to pick up `window_component`.

## v1.2.2 - 2026-10-05

- Fix: references to models with a UUID/ULID string key were stored under a wrong integer id or silently dropped. "All resources" now skips such models, and registering one explicitly throws an `InvalidArgumentException` naming it (README, Record references).
- `ChatService::updateGroup()` refuses to work with groups turned off, like `createGroup()`. README: group management in `ChatService` does not authorize the caller — check `ConversationPolicy` (`update`, `leave`) first.
- Hardening: `ChatWindow::$limit` is `#[Locked]` — the browser could set any page size and make every render and poll load the whole conversation. Marking as read now fetches only the newest message id (`MessageRepository::latestId()`), not the loaded page.
- Performance: the feed loads the records its messages reference in one query per type (it used to run a query and a view check per message on every render and poll). New `ReferenceRegistry::presentMany()` and `ReferenceType::findMany()`; the `chat-window` view now gets `$messageReferences` keyed by message id — a published copy of the view keeps working, but update it to use that variable to get the speed-up.
- Fix: a member added back to a group got every message written while they were away as unread. Coming back now works like joining: the read pointer moves to the latest message. The history itself stays visible, as for any new member (README, Conversations).
- Security: the bell and the "Reply" toast showed the message text, the sender name and the group title as HTML (Filament's sanitizer keeps links, images and inline styles), so a message could place an invisible full-screen link or a tracking image in the recipient's panel. They are now escaped and shown as plain text, as in the feed. `NewMessageNotification::title()` returns an escaped string; the new `NewMessageNotification::body()` gives the escaped preview.

## v1.2.1

- Fix: the install migration read `filament-chat.user_model`, a key the config never defined, so a custom `users.model` was ignored and the chat tables' user foreign keys pointed at `auth.providers.users.model`'s table. It now reads `filament-chat.users.model`. Apps that already ran the migration are unaffected unless their chat users live in a different table than the auth model — then check the foreign keys.

## v1.2.0

- Replies: quote a message, jump to the original (loads earlier messages if needed). New migration `add_reply_to_filament_chat_messages` — see "Upgrading from 1.1". Switch: `features.replies` / `->replies()`.
- Without the new migration 1.2 keeps working; replies stay off until you run it (the package checks for the `reply_to_id` column).
- If your app rebinds `MessageRepository` and overrides `create()`, add the new trailing `?int $replyToId = null` parameter.
- Avatars in group feeds (last message of a series), the @ list and the member line; `->avatarUsing()`; switch `features.avatars` / `->avatars()`.
- «New messages» line where the unread part starts; the feed opens there.
- Fix: the @ list showed only the first six members.
- Fix: the conversation list previewed messages written after a member left a group; now only up to leaving.
- Translations for the new strings in all ten languages.

## v1.1.1

- Author email updated in `composer.json`.

## v1.1.0

- Translations: German, Spanish, French, Italian, Dutch, Polish, Brazilian Portuguese and Turkish
  (in addition to English and Ukrainian); a test keeps every language in step with the English keys.
- Repository: GitHub Actions pinned to commit SHAs, Dependabot (with a 7-day cooldown), `SECURITY.md`.
- README: Plumb score badge.

## v1.0.1

- Author name corrected: Mykhailo Hladchenko.

## v1.0.0

- First stable release, published on Packagist and in the Filament plugin catalog.
- README: screenshots, badges.
- Repository files (`art/`, tests, workbench, tooling) are no longer shipped with `composer require`.

## v0.1.1

- A reference type registered without a resource (`ReferenceType::make()`) resolves dropped links
  of every resource of its model.
- The private channel is authorised only for people one can write to (`->users()`): an archived
  or deactivated account stops receiving events.

## v0.1.0

- Direct messages and groups (title, members, leave; the creator manages).
- @mentions with autocomplete; a mention always notifies.
- Editing own messages (configurable window), "edited" mark.
- Reactions (six emoji, one per person), read receipts ✓ / ✓✓.
- Chat page, top-bar button with a slide-over that can be pinned as a split screen.
- Unread counters: navigation badge, top-bar badge, browser tab title and favicon.
- Real-time delivery over Laravel Echo (Reverb / Pusher), polling fallback.
- Database notification on the first unread message of a conversation.
- Record references: register resources, attach by picker, drag-and-drop of a record link
  or "Attach current"; `DiscussInChatAction` for record pages.
- `references.authorize` (`policy` / `resource` / `false`) and `ReferenceType::visibleUsing()`.
- One config for everything (`users`, `features`, `messages`, `references`, `realtime`, `polling`,
  `notifications`, `ui`, `models`, `tables`); every key has a plugin setter.
- Feature switches: groups, reactions, mentions, read receipts, editing.
- Real-time switch `FILAMENT_CHAT_REALTIME`; the plugin creates Echo itself from the broadcasting
  connection (`realtime.echo = plugin|filament|host`), browser address `FILAMENT_CHAT_WS_*`,
  `/broadcasting/auth` registered when missing, a separate broadcasting connection.
- `references.all_resources` / `except` and `ReferenceType::allResources()`; a dropped link of a
  resource that is not referenceable names it.
- `filament-chat:install` (config, migration, `--migrate`, `--skill`); an agent skill and Laravel
  Boost guidelines.
- The stylesheet is linked after the panel theme.
- English and Ukrainian translations.
