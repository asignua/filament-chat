# Changelog

All notable changes to `asignua/filament-chat` are documented here.

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
