# Changelog

All notable changes to `asignua/filament-chat` are documented here.

## Unreleased

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
- English and Ukrainian translations.
