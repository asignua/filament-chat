<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | User model
    |--------------------------------------------------------------------------
    |
    | The model people chat as. Null falls back to the model of the default
    | auth provider (config('auth.providers.users.model')).
    |
    */

    'user_model' => null,

    /*
    |--------------------------------------------------------------------------
    | User attributes
    |--------------------------------------------------------------------------
    |
    | `search_columns` — columns of the users table the conversation search
    | looks in. `broadcast_key` — the attribute that names a user's private
    | channel (`filament-chat.user.{key}`); null uses the primary key. Point it
    | at a public identifier (a ulid / uuid column) if you prefer not to expose
    | sequential ids to the websocket server.
    |
    */

    'users' => [
        'search_columns' => ['name'],
        'broadcast_key' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Models and tables
    |--------------------------------------------------------------------------
    |
    | Swap a model for your own subclass (e.g. to add activity logging) or
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

    /*
    |--------------------------------------------------------------------------
    | Real-time delivery
    |--------------------------------------------------------------------------
    |
    | Null — automatic: on when the default broadcaster is a websocket one
    | (reverb, pusher, ably). The browser needs `window.Echo`; the simplest way
    | is Filament's own `filament.broadcasting.echo` config. Without real-time
    | the chat keeps working by polling (`polling` seconds for an open
    | conversation, `badge_polling` for the unread counter).
    |
    */

    'realtime' => null,

    'polling' => 15,

    'badge_polling' => 60,

    /*
    |--------------------------------------------------------------------------
    | Database notifications
    |--------------------------------------------------------------------------
    |
    | A bell notification on the first unread message of a conversation (the
    | rest only grow the counter). Requires `->databaseNotifications()` on the
    | panel and the Notifiable trait on the user model.
    |
    */

    'database_notifications' => true,

    /*
    |--------------------------------------------------------------------------
    | Messages
    |--------------------------------------------------------------------------
    */

    'page_size' => 30,

    /*
    | Authors may edit their messages: `window` — for how many minutes after
    | sending (null — any time). An edited message is marked as such.
    */

    'editing' => [
        'enabled' => true,
        'window' => null,
    ],

    'max_length' => 5000,

];
