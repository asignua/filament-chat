<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Enums;

/**
 * Places inside the chat window an extension can add markup to
 * (FilamentChatPlugin::renderHook()). Every closure gets the ChatWindow and a
 * context array — `conversation` (the open one, or null) and, for the two
 * message hooks, `message` and `mine`.
 */
enum ChatHook: string
{
    /**
     * Top of the conversation list, above the search box (no open conversation needed).
     */
    case SIDEBAR_BEFORE = 'sidebar.before';

    /**
     * The open conversation's header, before the group buttons.
     */
    case HEADER_ACTIONS = 'header.actions';

    /**
     * Top of the feed, above "load older".
     */
    case FEED_BEFORE = 'feed.before';

    /**
     * Inside a message bubble, after the text and the record card, above the time. Context: `message`, `mine`.
     */
    case MESSAGE_BODY_AFTER = 'message.body.after';

    /**
     * The per-message buttons beside a bubble (reply, edit, react). Context: `message`, `mine`.
     */
    case MESSAGE_MENU = 'message.menu';

    /**
     * First thing in the composer form, above the reply / edit / reference strips.
     */
    case COMPOSER_BEFORE = 'composer.before';

    /**
     * In the composer row, right before the send button.
     */
    case COMPOSER_TOOLS = 'composer.tools';

    /**
     * Last thing in the composer form, below the input row.
     */
    case COMPOSER_AFTER = 'composer.after';
}
