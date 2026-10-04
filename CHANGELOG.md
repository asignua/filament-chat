# Changelog

All notable changes to `asignua/filament-chat` are documented here.

## Unreleased

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
