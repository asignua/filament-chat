<?php

declare(strict_types=1);

/*
 * Filament Chat. Everything here can also be set per panel on the plugin
 * (FilamentChatPlugin::make()->…); the plugin wins. Closures (who can be
 * written to, how people are called, per-type reference rules) live on the
 * plugin only — config must stay cacheable.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Users
    |--------------------------------------------------------------------------
    |
    | model          — who chats; null = config('auth.providers.users.model').
    | name_attribute — the attribute shown as a person's name; null = Filament's
    |                  HasName::getFilamentName(), then `name`. For anything
    |                  computed use ->userName(fn ($user) => …) on the plugin.
    | search_columns — columns the conversation search looks in.
    | broadcast_key  — the attribute that names a person's private channel
    |                  (`filament-chat.user.{key}`); null = the primary key. Point
    |                  it at a public column (ulid / uuid) if you prefer not to
    |                  expose sequential ids to the websocket server.
    |
    | Who can be written to (e.g. only active staff) is a query — set it with
    | ->users(fn (Builder $query) => …) on the plugin. Default: everybody.
    |
    */

    'users' => [
        'model' => null,
        'name_attribute' => null,
        'search_columns' => ['name'],
        'broadcast_key' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Features
    |--------------------------------------------------------------------------
    |
    | Turn off whatever your team does not need. Switching a feature off hides
    | it everywhere and the server refuses it too; stored data is kept.
    |
    */

    'features' => [
        // Group conversations (title, members, leave). Off — direct messages only.
        'groups' => true,

        // Six emoji reactions, one per person per message.
        'reactions' => true,

        // "@Name" with autocomplete; a mention always rings the bell.
        'mentions' => true,

        // ✓ sent / ✓✓ read on one's own messages.
        'read_receipts' => true,

        // Authors edit their messages; `window` — minutes after sending (null = any time).
        'editing' => [
            'enabled' => true,
            'window' => null,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Messages
    |--------------------------------------------------------------------------
    */

    'messages' => [
        // How many messages load at once (and per "show earlier").
        'page_size' => 30,

        'max_length' => 5000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Record references
    |--------------------------------------------------------------------------
    |
    | Messages may point at records of your panel: pick one, drop a link to its
    | page into the chat, or "Attach current" in the slide-over.
    |
    | Which records: types registered on the plugin
    | (->references([ReferenceType::resource(OrderResource::class)])) and — with
    | `all_resources` — every resource of the panel except `except`. With
    | all_resources the key stored with a message is the resource slug; renaming
    | a slug loses the link of old messages. Explicit types always win.
    |
    | Who sees a referenced record (title and link; otherwise only its type,
    | and it cannot be attached) — `authorize`:
    |   'policy'   — the model's `view` policy. A model WITHOUT a policy is
    |                hidden: Laravel denies an ability nobody defined. Strictest.
    |   'resource' — the resource's canView(), Filament's own rule: a model
    |                without a policy is visible to everyone in the panel.
    |   false      — no check.
    | One type can have its own rule: ReferenceType::…->visibleUsing(fn ($record) => …).
    |
    */

    'references' => [
        'enabled' => true,
        'authorize' => 'policy',
        'all_resources' => false,
        'except' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Real-time delivery
    |--------------------------------------------------------------------------
    |
    | enabled — true: new messages, reads and reactions arrive instantly over a
    |           websocket (Laravel Reverb or any Pusher-compatible server).
    |           false: the chat polls (see `polling`). Both work out of the box.
    |           null: on when the default broadcaster is reverb / pusher / ably.
    |
    | connection — the broadcasting connection to publish on; null = default.
    |
    | echo — who puts Laravel Echo into the browser:
    |   'plugin'   — the plugin does (Filament's bundled Echo), configured from
    |                the connection + `client` below. Nothing else to set up.
    |   'filament' — Filament's own `filament.broadcasting.echo` config.
    |   'host'     — your app already loads window.Echo (your Vite bundle).
    |
    | client — where the BROWSER connects, when it differs from where PHP
    |          publishes (e.g. PHP → reverb:8080 inside Docker, the browser →
    |          your public host behind nginx). Empty = the page's own host,
    |          protocol and default port.
    |
    | register_auth_route — register /broadcasting/auth (web + auth middleware)
    |          if your app has not (bootstrap/app.php ->withBroadcasting()).
    |
    */

    'realtime' => [
        'enabled' => env('FILAMENT_CHAT_REALTIME'),
        'connection' => env('FILAMENT_CHAT_BROADCAST_CONNECTION'),
        'echo' => env('FILAMENT_CHAT_ECHO', 'plugin'),
        'client' => [
            'host' => env('FILAMENT_CHAT_WS_HOST'),
            'port' => env('FILAMENT_CHAT_WS_PORT'),
            'scheme' => env('FILAMENT_CHAT_WS_SCHEME'),
        ],
        'register_auth_route' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Polling
    |--------------------------------------------------------------------------
    |
    | Seconds. `conversation` — an open conversation without real-time;
    | `badge` — the unread counter (always, as a safety net).
    |
    */

    'polling' => [
        'conversation' => 15,
        'badge' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | database — the panel bell: the first unread message of a conversation and
    |            every mention. Needs ->databaseNotifications() on the panel,
    |            the `notifications` table and Notifiable on the user model.
    | toasts   — a "Reply" toast when a message arrives in another conversation
    |            (real-time only).
    |
    */

    'notifications' => [
        'database' => true,
        'toasts' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Interface
    |--------------------------------------------------------------------------
    |
    | dock       — the top-bar button with a slide-over; off — the Chat page only.
    | pinnable   — the slide-over can be pinned as a split screen (from lg).
    | tab_badge  — unread count in the browser tab title and on the favicon.
    | color      — accent of group avatars and author names (a Filament colour).
    | navigation — the Chat page in the menu.
    |
    */

    'ui' => [
        'dock' => true,
        'pinnable' => true,
        'tab_badge' => true,
        'color' => 'primary',
        'slug' => 'chat',
        'navigation' => [
            'group' => null,
            'sort' => 90,
            'icon' => null,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Models and tables
    |--------------------------------------------------------------------------
    |
    | Swap a model for your own subclass (activity logging, extra relations) or
    | rename the tables before running the published migration.
    |
    */

    'models' => [
        'conversation' => Asignua\FilamentChat\Models\Conversation::class,
        'participant' => Asignua\FilamentChat\Models\Participant::class,
        'message' => Asignua\FilamentChat\Models\Message::class,
        'reaction' => Asignua\FilamentChat\Models\MessageReaction::class,
    ],

    'tables' => [
        'conversations' => 'chat_conversations',
        'participants' => 'chat_participants',
        'messages' => 'chat_messages',
        'reactions' => 'chat_message_reactions',
    ],

];
