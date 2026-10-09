{{--
    The chat window (Asignua\FilamentChat\Livewire\ChatWindow). The page has two
    columns (conversations | feed), the slide-over ($compact) one: the list OR
    the conversation.

    Scrolling down is the browser event filament-chat-scroll (open, sent, new
    incoming). Enter sends, Shift+Enter makes a new line. After sending the
    composer takes focus back; the Send button does not steal it
    (mousedown.prevent), and the blocks above the composer carry wire:key —
    otherwise the morph re-created the textarea when an attachment vanished.

    Reactions: 😊 next to a bubble opens six emoji (downwards for the first
    message, so the feed does not clip it); the chips below toggle one's own;
    whoever left a group sees them but cannot react.

    ✓ / ✓✓ — on one's own messages only (ReadStatus): a group needs all active members.

    wire:key sits on the aside and section of the root, the direct children of
    the section and the form blocks: browser extensions (Grammarly, password
    managers) inject nodes, and without keys the morph shuffles the form —
    the textarea loses focus.

    Without a socket the open conversation is polled.

    Extension points: {{ $hook(ChatHook::X, [...context]) }} prints what extensions registered
    with FilamentChatPlugin::renderHook(); the block-level ones sit in a keyed wrapper
    (only when they print something) for the same morph reason as above. $tombstones
    ([id => true]) are messages shown as «Message deleted» (ChatWindow::isMessageTombstone()).
--}}
@use('Asignua\FilamentChat\Enums\Reaction')
@use('Asignua\FilamentChat\Enums\ChatHook')
@use('Asignua\FilamentChat\Livewire\ChatWindow')
@use('Asignua\FilamentChat\Support\ChatText')
@use('Asignua\FilamentChat\Support\ChatTime')
@use('Asignua\FilamentChat\Support\ChatUsers')
<div
    @class([
        'fchat fchat-scope',
        'fchat-framed' => !$compact,
    ])
    @if (!$realtime && $current) wire:poll.{{ $polling }}s="poll" @endif
    {{-- A tab in the background marks nothing as read (the slide-over's own open/closed state is a separate flag). --}}
    x-data
    x-init="if (document.hidden) $wire.setDocumentHidden(true)"
    x-on:visibilitychange.document="$wire.setDocumentHidden(document.hidden)"
>
    {{-- Conversation list --}}
    <aside
        wire:key="fchat-list"
        @class([
            'flex min-w-0 flex-col border-gray-200 dark:border-white/10',
            'w-full' => $compact,
            'hidden' => $compact && $current,
            'w-full border-e md:w-80 md:shrink-0' => !$compact,
            'max-md:hidden' => !$compact && $current,
        ])
    >
        @if (($sidebarBefore = $hook(ChatHook::SIDEBAR_BEFORE))->isNotEmpty())
            <div wire:key="fchat-hook-sidebar-before">{{ $sidebarBefore }}</div>
        @endif
        <div class="space-y-2 border-b border-gray-200 p-3 dark:border-white/10">
            <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass">
                <x-filament::input
                    type="search"
                    wire:model.live.debounce.400ms="search"
                    :placeholder="__('filament-chat::chat.search')"
                />
            </x-filament::input.wrapper>
            <div class="flex flex-wrap gap-2">
                {{ $this->newDirectAction }}
                @if ($groups)
                    {{ $this->newGroupAction }}
                @endif
            </div>
        </div>

        <ul class="flex-1 divide-y divide-gray-100 overflow-y-auto dark:divide-white/5">
            @forelse ($conversations as $item)
                @php
                    $count = $unread[$item->id] ?? 0;
                    $counterpart = $item->counterpartFor($me);
                    $last = array_key_exists($item->id, $previews) ? $previews[$item->id] : $item->latestMessage;
                    $lastGone = $last !== null && isset($tombstones[$last->id]);
                @endphp
                <li wire:key="conv-{{ $item->ulid }}">
                    <button
                        type="button"
                        wire:click="open('{{ $item->ulid }}')"
                        @class([
                            'flex w-full items-center gap-3 px-3 py-2.5 text-start transition hover:bg-gray-50 dark:hover:bg-white/5',
                            'bg-primary-50 dark:bg-primary-500/10' => $current?->is($item),
                        ])
                    >
                        @if ($counterpart)
                            {{-- Through ChatUsers::avatar(), not <x-filament-panels::avatar.user>: that one asks the panel's provider and skips ->avatarUsing(). --}}
                            @php
                                $counterpartAvatar = ChatUsers::avatar($counterpart);
                            @endphp
                            @if ($counterpartAvatar)
                                <x-filament::avatar :src="$counterpartAvatar" :alt="ChatUsers::name($counterpart)" size="md" class="fi-user-avatar shrink-0" data-fchat-list-avatar />
                            @else
                                <span data-fchat-list-avatar class="fchat-group-avatar fi-color-{{ $color }} flex size-8 shrink-0 items-center justify-center rounded-full text-xs font-semibold">
                                    {{ mb_strtoupper(mb_substr(ChatUsers::name($counterpart), 0, 1)) }}
                                </span>
                            @endif
                        @else
                            <span class="fchat-group-avatar fi-color-{{ $color }} flex size-8 shrink-0 items-center justify-center rounded-full">
                                <x-filament::icon icon="heroicon-m-user-group" class="size-4" />
                            </span>
                        @endif
                        <span class="min-w-0 flex-1">
                            <span class="flex items-baseline justify-between gap-2">
                                <span @class(['truncate text-sm', 'font-semibold' => $count > 0, 'font-medium' => $count === 0])>
                                    {{ $item->titleFor($me) }}
                                </span>
                                @if ($last)
                                    <span class="shrink-0 text-xs text-gray-400">
                                        {{ ChatTime::short($last->created_at) }}
                                    </span>
                                @endif
                            </span>
                            <span class="flex items-center justify-between gap-2">
                                <span class="truncate text-xs text-gray-500 dark:text-gray-400">
                                    @if ($last)
                                        @if ($item->isGroup() && $last->author)
                                            {{ $last->author->is($me) ? __('filament-chat::chat.you') : ChatUsers::name($last->author) }}:
                                        @endif
                                        {{ $lastGone ? __('filament-chat::chat.message_deleted') : $last->preview(80) }}
                                    @else
                                        {{ __('filament-chat::chat.no_messages') }}
                                    @endif
                                </span>
                                @if ($count > 0)
                                    <x-filament::badge color="danger" size="sm">{{ $count }}</x-filament::badge>
                                @endif
                            </span>
                        </span>
                    </button>
                </li>
            @empty
                <li class="p-6 text-center text-sm text-gray-500 dark:text-gray-400">
                    {{ $search === '' ? __('filament-chat::chat.empty') : __('filament-chat::chat.nothing_found') }}
                </li>
            @endforelse
        </ul>
    </aside>

    {{-- The open conversation --}}
    {{--
        Drag and drop: any link to a record page (a table row, a link in a column, a global
        search result, the address bar) carries its URL — the server resolves the record
        (RecordUrlResolver) and attaches it to the message. A counter, because dragenter/leave
        fire on every child element too.
    --}}
    <section
        wire:key="fchat-conversation"
        @class([
            'relative flex min-w-0 flex-1 flex-col',
            'hidden' => $compact && !$current,
            'max-md:hidden' => !$compact && !$current,
        ])
        {{-- Drag & drop only when there is something to attach: otherwise every drop would just say "no". --}}
        @if ($canSend && $canAttach)
            x-data="{ depth: 0 }"
            x-on:dragenter.prevent="depth++"
            x-on:dragleave="depth = Math.max(0, depth - 1)"
            x-on:dragover.prevent="$event.dataTransfer.dropEffect = 'link'"
            x-on:drop="
                depth = 0
                if ($event.dataTransfer.types.includes('text/uri-list') || /^https?:\/\/\S+$/i.test($event.dataTransfer.getData('text/plain').trim())) {
                    $event.preventDefault()
                    $wire.attachUrl($event.dataTransfer.getData('text/uri-list') || $event.dataTransfer.getData('text/plain').trim())
                }
            "
        @endif
    >
        @if ($canSend && $canAttach)
            <div
                wire:key="fchat-drop-overlay"
                x-show="depth > 0"
                x-cloak
                class="pointer-events-none absolute inset-2 z-10 flex items-center justify-center rounded-xl border-2 border-dashed border-primary-500 bg-primary-50/80 text-sm font-medium text-primary-700 dark:bg-primary-500/10 dark:text-primary-300"
            >
                {{ __('filament-chat::chat.drop_hint') }}
            </div>
        @endif
        @if ($current)
            <header wire:key="fchat-header" class="flex items-center gap-3 border-b border-gray-200 px-3 py-2 dark:border-white/10">
                <x-filament::icon-button
                    icon="heroicon-m-arrow-left"
                    color="gray"
                    wire:click="back"
                    :label="__('filament-chat::chat.back')"
                    @class(['md:hidden' => !$compact])
                />
                <div class="min-w-0 flex-1">
                    <div class="truncate font-semibold">{{ $current->titleFor($me) }}</div>
                    @if ($current->isGroup())
                        <div class="flex items-center gap-1.5">
                            @if ($avatarsOn)
                                <span class="flex shrink-0 -space-x-1.5">
                                    @foreach ($members->take(5) as $member)
                                        @if ($avatars[$member->getKey()] ?? null)
                                            <img src="{{ $avatars[$member->getKey()] }}" alt="" class="size-4 rounded-full object-cover ring-2 ring-white dark:ring-gray-900" />
                                        @endif
                                    @endforeach
                                </span>
                            @endif
                            <div class="truncate text-xs text-gray-500 dark:text-gray-400">
                                {{ $members->map(fn ($u) => ChatUsers::name($u))->join(', ') }}
                            </div>
                        </div>
                    @endif
                </div>
                {{ $hook(ChatHook::HEADER_ACTIONS) }}
                @if ($canManage)
                    {{ $this->manageGroupAction }}
                @endif
                @if ($canLeave)
                    {{ $this->leaveGroupAction }}
                @endif
            </header>

            <div
                wire:key="fchat-feed"
                class="relative flex-1 space-y-1 overflow-y-auto px-3 py-3"
                x-data
                x-init="$nextTick(() => {
                    const line = $el.querySelector('#fchat-unread-line')
                    $el.scrollTop = line ? line.offsetTop - 8 : $el.scrollHeight
                })"
                x-on:{{ ChatWindow::EVENT_SCROLL }}.window="$nextTick(() => {
                    const line = $event.detail?.unread ? $el.querySelector('#fchat-unread-line') : null
                    $el.scrollTop = line ? line.offsetTop - 8 : $el.scrollHeight
                })"
                x-on:{{ ChatWindow::EVENT_HIGHLIGHT }}.window="$nextTick(() => {
                    const target = document.getElementById('fchat-msg-' + $event.detail.message)
                    if (! target) return
                    target.scrollIntoView({ block: 'center', behavior: 'smooth' })
                    target.classList.add('fchat-flash')
                    setTimeout(() => target.classList.remove('fchat-flash'), 1500)
                })"
            >
                @if (($feedBefore = $hook(ChatHook::FEED_BEFORE))->isNotEmpty())
                    <div wire:key="fchat-hook-feed-before">{{ $feedBefore }}</div>
                @endif
                @if ($hasOlder)
                    <div class="pb-2 text-center">
                        <x-filament::link tag="button" wire:click="loadOlder" size="sm">
                            {{ __('filament-chat::chat.load_older') }}
                        </x-filament::link>
                    </div>
                @endif

                @php $day = null; $prevAuthor = null; @endphp
                @forelse ($messages as $message)
                    @php
                        $messageDay = ChatTime::date($message->created_at);
                        $mine = $me !== null && $message->user_id === $me->getKey();
                        $showAuthor = !$mine && $current->isGroup() && ($prevAuthor !== $message->user_id || $day !== $messageDay);
                        $next = $messages[$loop->index + 1] ?? null;
                        $lastInSeries = $next === null || $next->user_id !== $message->user_id || ChatTime::date($next->created_at) !== $messageDay;
                        $withAvatar = $avatarsOn && $current->isGroup() && !$mine;
                        $gone = isset($tombstones[$message->id]);
                        $reference = $gone ? null : ($messageReferences[$message->id] ?? null);
                    @endphp
                    @if ($day !== $messageDay)
                        <div class="py-2 text-center text-xs text-gray-400" wire:key="day-{{ $messageDay }}">{{ $messageDay }}</div>
                        @php $day = $messageDay; @endphp
                    @endif
                    @if ($unreadMarker === $message->id)
                        <div wire:key="fchat-unread-line" id="fchat-unread-line" data-fchat-unread class="flex items-center gap-2 py-2 text-xs font-medium text-primary-600 dark:text-primary-400">
                            <span class="h-px flex-1 bg-primary-500/40"></span>
                            {{ __('filament-chat::chat.new_messages') }}
                            <span class="h-px flex-1 bg-primary-500/40"></span>
                        </div>
                    @endif
                    @php $messageReactions = $gone ? [] : ($reactions[$message->id] ?? []); @endphp
                    <div
                        wire:key="msg-{{ $message->ulid }}"
                        id="fchat-msg-{{ $message->ulid }}"
                        x-data="{ picker: false }"
                        {{-- relative: the reaction picker is anchored to the row on the bubble's side, so it never leaves the window. --}}
                        @class(['group/msg relative flex items-center gap-1', 'flex-row-reverse' => $mine])
                    >
                        {{-- min-w-0 + wrap-anywhere: a long word or URL wraps instead of widening the bubble. --}}
                        <div @class(['flex min-w-0 max-w-[85%] flex-col', 'items-end' => $mine, 'items-start' => !$mine])>
                            {{-- The avatar sits beside the bubble, not the reactions: it lines up with the bubble's bottom. --}}
                            @if ($withAvatar)
                                <div class="flex max-w-full items-end gap-1">
                                    <div class="size-7 shrink-0 self-end">
                                        @if ($lastInSeries)
                                            @if ($avatars[$message->user_id] ?? null)
                                                <img data-fchat-avatar src="{{ $avatars[$message->user_id] }}" alt="" class="size-7 rounded-full object-cover" loading="lazy" />
                                            @else
                                                {{-- ->avatarUsing() returned null, or the author's account is gone: initials, no network request. --}}
                                                <span class="fchat-group-avatar fi-color-{{ $color }} flex size-7 items-center justify-center rounded-full text-[0.65rem] font-semibold">
                                                    {{ $message->author ? mb_strtoupper(mb_substr(ChatUsers::name($message->author), 0, 1)) : '?' }}
                                                </span>
                                            @endif
                                        @endif
                                    </div>
                            @endif
                            <div
                                @class([
                                    'max-w-full rounded-2xl px-3 py-2 text-sm',
                                    'min-w-0' => $withAvatar,
                                    'bg-primary-600 text-white' => $mine,
                                    'bg-gray-100 text-gray-900 dark:bg-white/10 dark:text-gray-100' => !$mine,
                                ])
                            >
                                @if ($showAuthor)
                                    <div class="fchat-author fi-color-{{ $color }} mb-0.5 text-xs font-semibold">
                                        {{ $message->author ? ChatUsers::name($message->author) : __('filament-chat::chat.unknown_user') }}
                                    </div>
                                @endif
                                @if ($quotesOn && $message->reply_to_id !== null)
                                    @php
                                        $original = $message->replyTo;
                                        $originalGone = $original !== null && isset($tombstones[$original->id]);
                                    @endphp
                                    <button
                                        type="button"
                                        data-fchat-quote="{{ $original?->ulid }}"
                                        @if ($original)
                                            x-on:click="
                                                const target = document.getElementById('fchat-msg-{{ $original->ulid }}')
                                                if (target) { $dispatch('{{ ChatWindow::EVENT_HIGHLIGHT }}', { message: '{{ $original->ulid }}' }) } else { $wire.showMessage('{{ $original->ulid }}') }
                                            "
                                        @endif
                                        @class([
                                            'mb-1 block w-full rounded-md border-s-2 px-2 py-1 text-start text-xs',
                                            'border-white/70 bg-white/15 text-white/90' => $mine,
                                            'fi-color-'.$color.' fchat-quote border-current bg-white/60 dark:bg-white/5' => !$mine,
                                        ])
                                    >
                                        <span @class(['block truncate font-semibold', 'fchat-quote-author' => !$mine])>
                                            {{ $original?->author ? ChatUsers::name($original->author) : __('filament-chat::chat.unknown_user') }}
                                        </span>
                                        <span class="block truncate opacity-80">{{ $originalGone ? __('filament-chat::chat.message_deleted') : ($original?->preview(100) ?? __('filament-chat::chat.reply_unknown')) }}</span>
                                    </button>
                                @endif
                                @if ($gone)
                                    <div data-fchat-deleted class="italic opacity-80">{{ __('filament-chat::chat.message_deleted') }}</div>
                                @else
                                    @if (trim($message->body) !== '')
                                        <div class="wrap-anywhere break-words">{{ ChatText::toHtml($message->body, $mentionsOn ? array_intersect_key($names, array_flip($message->mentionIds())) : [], $me?->getKey()) }}</div>
                                    @endif
                                    @if ($reference)
                                        @include('filament-chat::livewire.reference', ['reference' => $reference, 'mine' => $mine])
                                    @endif
                                    {{ $hook(ChatHook::MESSAGE_BODY_AFTER, ['message' => $message, 'mine' => $mine]) }}
                                @endif
                                <div @class(['mt-0.5 flex items-center justify-end gap-1 text-[0.7rem]', 'text-white/70' => $mine, 'text-gray-400' => !$mine])>
                                    @if ($message->isEdited() && !$gone)
                                        <span class="italic" title="{{ ChatTime::date($message->edited_at) }} {{ ChatTime::time($message->edited_at) }}">{{ __('filament-chat::chat.edited') }}</span>
                                    @endif
                                    {{ ChatTime::time($message->created_at) }}
                                    @if ($mine && $readStatus && !$gone)
                                        @php $read = $readStatus->isRead($message->id); @endphp
                                        <span
                                            title="{{ $readStatus->hint($message->id, $current->isGroup()) }}"
                                            @class(['tracking-tighter', 'font-semibold text-white' => $read])
                                        >{{ $read ? '✓✓' : '✓' }}</span>
                                    @endif
                                </div>
                            </div>
                            @if ($withAvatar)
                                </div>
                            @endif
                            @if ($reactionsOn && $messageReactions !== [])
                                <div @class(['mt-0.5 flex flex-wrap gap-1', 'ps-8' => $withAvatar])>
                                    @foreach (Reaction::cases() as $reaction)
                                        @continue(!isset($messageReactions[$reaction->value]))
                                        @php
                                            $who = $messageReactions[$reaction->value];
                                            $picked = $me !== null && isset($who[$me->getKey()]);
                                        @endphp
                                        @php $chipTag = $canSend ? 'button' : 'span'; @endphp
                                        <{{ $chipTag }}
                                            title="{{ implode(', ', $who) }}"
                                            @if ($canSend)
                                                type="button"
                                                wire:click="react('{{ $message->ulid }}', '{{ $reaction->value }}')"
                                            @endif
                                            @class([
                                                'flex items-center gap-1 rounded-full px-1.5 py-0.5 text-xs ring-1 transition',
                                                'bg-primary-50 text-primary-700 ring-primary-500 dark:bg-primary-500/15 dark:text-primary-300' => $picked,
                                                'bg-white text-gray-600 ring-gray-200 hover:bg-gray-50 dark:bg-white/5 dark:text-gray-300 dark:ring-white/10' => !$picked,
                                            ])
                                        >
                                            <span class="text-sm leading-none">{{ $reaction->emoji() }}</span>
                                            <span>{{ count($who) }}</span>
                                        </{{ $chipTag }}>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                        @if ($canSend && !$gone)
                            <div class="flex shrink-0 items-center">
                                {{ $hook(ChatHook::MESSAGE_MENU, ['message' => $message, 'mine' => $mine]) }}
                                @if ($repliesOn)
                                    <button
                                        type="button"
                                        wire:click="startReply('{{ $message->ulid }}')"
                                        title="{{ __('filament-chat::chat.reply') }}"
                                        class="rounded-full p-1 text-gray-400 opacity-0 transition group-hover/msg:opacity-100 hover:text-gray-600 focus:opacity-100 dark:hover:text-gray-200 [@media(hover:none)]:opacity-60"
                                    >
                                        <x-filament::icon icon="heroicon-m-arrow-uturn-left" class="size-4" />
                                    </button>
                                @endif
                                @if (in_array($message->id, $editable, true))
                                    <button
                                        type="button"
                                        wire:click="startEdit('{{ $message->ulid }}')"
                                        title="{{ __('filament-chat::chat.edit') }}"
                                        class="rounded-full p-1 text-gray-400 opacity-0 transition group-hover/msg:opacity-100 hover:text-gray-600 focus:opacity-100 dark:hover:text-gray-200 [@media(hover:none)]:opacity-60"
                                    >
                                        <x-filament::icon icon="heroicon-m-pencil-square" class="size-4" />
                                    </button>
                                @endif
                                @if ($reactionsOn)
                                <button
                                    type="button"
                                    x-on:click="picker = !picker"
                                    title="{{ __('filament-chat::chat.react') }}"
                                    class="rounded-full p-1 text-base leading-none opacity-0 transition group-hover/msg:opacity-100 focus:opacity-100 [@media(hover:none)]:opacity-60"
                                >😊</button>
                                <div
                                    x-show="picker"
                                    x-cloak
                                    x-on:click.outside="picker = false"
                                    @class([
                                        'absolute z-20 flex gap-0.5 rounded-full bg-white p-1 shadow-lg ring-1 ring-gray-950/5 dark:bg-gray-800 dark:ring-white/10',
                                        'top-full mt-1' => $loop->first,
                                        'bottom-full mb-1' => !$loop->first,
                                        'end-0' => $mine,
                                        'start-0' => !$mine,
                                    ])
                                >
                                    @foreach (Reaction::cases() as $reaction)
                                        <button
                                            type="button"
                                            title="{{ $reaction->getLabel() }}"
                                            wire:click="react('{{ $message->ulid }}', '{{ $reaction->value }}')"
                                            x-on:click="picker = false"
                                            class="rounded-full p-1 text-lg leading-none transition hover:scale-125"
                                        >{{ $reaction->emoji() }}</button>
                                    @endforeach
                                </div>
                                @endif
                            </div>
                        @endif
                    </div>
                    @php $prevAuthor = $message->user_id; @endphp
                @empty
                    <div class="py-10 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('filament-chat::chat.say_hi') }}</div>
                @endforelse
            </div>

            @if ($canSend)
                <form wire:key="fchat-composer-form" wire:submit="send" class="space-y-2 border-t border-gray-200 p-3 dark:border-white/10">
                    @if (($composerBefore = $hook(ChatHook::COMPOSER_BEFORE))->isNotEmpty())
                        <div wire:key="fchat-hook-composer-before">{{ $composerBefore }}</div>
                    @endif
                    @if ($replying && !$editing)
                        <div wire:key="fchat-replying" class="flex items-center gap-2 rounded-lg border-s-2 border-primary-500 bg-gray-50 px-2 py-1 text-xs dark:bg-white/5">
                            <x-filament::icon icon="heroicon-m-arrow-uturn-left" class="size-4 shrink-0 text-primary-600 dark:text-primary-400" />
                            <div class="min-w-0 flex-1">
                                <div class="text-primary-600 dark:text-primary-400">
                                    {{ __('filament-chat::chat.replying_to') }}
                                    <span class="font-semibold">{{ $replying->author ? ChatUsers::name($replying->author) : __('filament-chat::chat.unknown_user') }}</span>
                                </div>
                                <div class="truncate text-gray-500 dark:text-gray-400">{{ $replying->preview(100) }}</div>
                            </div>
                            <x-filament::icon-button icon="heroicon-m-x-mark" color="gray" size="sm" wire:click="cancelReply" :label="__('filament-chat::chat.cancel_reply')" />
                        </div>
                    @endif
                    @if ($editing)
                        <div wire:key="fchat-editing" class="flex items-center gap-2 text-xs text-primary-600 dark:text-primary-400">
                            <x-filament::icon icon="heroicon-m-pencil-square" class="size-4 shrink-0" />
                            <span class="flex-1 font-medium">{{ __('filament-chat::chat.editing') }}</span>
                            <x-filament::icon-button
                                icon="heroicon-m-x-mark"
                                color="gray"
                                size="sm"
                                wire:click="cancelEdit"
                                :label="__('filament-chat::chat.cancel_edit')"
                            />
                        </div>
                    @endif
                    @if ($pageReference && !$editing)
                        {{-- The slide-over is open on a record page — attach it with one click. --}}
                        <button
                            type="button"
                            wire:key="fchat-page-reference"
                            wire:click="attachPage"
                            class="flex max-w-full items-center gap-1.5 rounded-lg border border-dashed border-gray-300 px-2 py-1 text-xs text-gray-600 transition hover:border-primary-500 hover:bg-primary-50 dark:border-white/20 dark:text-gray-300 dark:hover:bg-primary-500/10"
                        >
                            <x-filament::icon icon="heroicon-m-plus" class="size-3.5 shrink-0" />
                            <span class="shrink-0">{{ __('filament-chat::chat.add_current') }}:</span>
                            <x-filament::icon
                                :icon="$pageReference['icon']"
                                @class(['size-4 shrink-0', 'fi-color-'.$pageReference['color'].' fchat-accent'])
                            />
                            <span class="shrink-0 text-gray-500 dark:text-gray-400">{{ $pageReference['type'] }}</span>
                            <span class="truncate font-medium">{{ $pageReference['label'] }}</span>
                        </button>
                    @endif
                    @if ($pendingReference && !$editing)
                        <div wire:key="fchat-pending-reference" class="flex items-center gap-2">
                            @include('filament-chat::livewire.reference', ['reference' => $pendingReference, 'mine' => false])
                            <x-filament::icon-button
                                icon="heroicon-m-x-mark"
                                color="gray"
                                size="sm"
                                wire:click="clearReference"
                                :label="__('filament-chat::chat.detach')"
                            />
                        </div>
                    @endif
                    <div wire:key="fchat-composer" class="flex items-center gap-2">
                        @if ($canAttach && !$editing)
                            {{ $this->attachRecordAction }}
                        @endif
                        {{--
                            "@" opens the list of members (data-people — re-read on every keystroke, so
                            switching conversations needs no re-init); ↑/↓ + Enter/Tab pick, Esc closes.
                            ↑ in an empty composer edits one's latest message, Esc cancels editing.
                        --}}
                        <div
                            class="relative min-w-0 flex-1"
                            data-people="{{ json_encode($mentionable, JSON_UNESCAPED_UNICODE) }}"
                            x-data="{
                                open: false,
                                items: [],
                                index: 0,
                                start: 0,
                                people() {
                                    try { return JSON.parse(this.$root.dataset.people || '[]') } catch (e) { return [] }
                                },
                                resize() {
                                    const el = this.$refs.input
                                    el.style.height = 'auto'
                                    el.style.height = Math.min(el.scrollHeight, 160) + 'px'
                                },
                                scan() {
                                    const el = this.$refs.input
                                    const before = el.value.slice(0, el.selectionStart)
                                    const match = before.match(/(^|\s)@([^@\n]{0,40})$/u)
                                    if (! match) { this.open = false; return }
                                    const query = match[2].toLowerCase()
                                    this.items = this.people().filter((person) => person.name.toLowerCase().includes(query))
                                    this.start = el.selectionStart - match[2].length - 1
                                    this.index = 0
                                    this.open = this.items.length > 0
                                },
                                pick(name) {
                                    const el = this.$refs.input
                                    const caret = el.selectionStart
                                    el.value = el.value.slice(0, this.start) + '@' + name + ' ' + el.value.slice(caret)
                                    const position = this.start + name.length + 2
                                    el.setSelectionRange(position, position)
                                    el.dispatchEvent(new Event('input'))
                                    this.open = false
                                    el.focus()
                                },
                                reveal() {
                                    this.$nextTick(() => this.$refs.list?.querySelectorAll('li')[this.index]?.scrollIntoView({ block: 'nearest' }))
                                },
                                key(event) {
                                    // Enter that confirms an IME candidate (Japanese, Chinese, Korean…) is not a send (no double quotes in here: they would end the x-data attribute).
                                    if (event.isComposing || event.keyCode === 229) return
                                    if (this.open) {
                                        if (event.key === 'ArrowDown') { event.preventDefault(); this.index = (this.index + 1) % this.items.length; this.reveal(); return }
                                        if (event.key === 'ArrowUp') { event.preventDefault(); this.index = (this.index - 1 + this.items.length) % this.items.length; this.reveal(); return }
                                        if (event.key === 'Enter' || event.key === 'Tab') { event.preventDefault(); this.pick(this.items[this.index].name); return }
                                        if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); this.open = false; return }
                                    }
                                    if (event.key === 'Enter' && ! event.shiftKey) { event.preventDefault(); this.$wire.send(); return }
                                    if (event.key === 'ArrowUp' && this.$refs.input.value === '') { event.preventDefault(); this.$wire.editLast(); return }
                                    if (event.key === 'Escape' && this.$wire.editing) { event.preventDefault(); event.stopPropagation(); this.$wire.cancelEdit() }
                                    if (event.key === 'Escape' && this.$wire.replyingTo) { event.preventDefault(); event.stopPropagation(); this.$wire.cancelReply() }
                                },
                            }"
                            x-on:{{ ChatWindow::EVENT_SENT }}.window="$nextTick(() => { resize(); $refs.input.focus() })"
                            x-on:{{ ChatWindow::EVENT_EDIT }}.window="$nextTick(() => {
                                const el = $refs.input
                                el.value = $event.detail.body
                                resize()
                                el.focus()
                                el.setSelectionRange(el.value.length, el.value.length)
                            })"
                        >
                            <ul
                                x-ref="list"
                                x-show="open"
                                x-cloak
                                class="absolute bottom-full start-0 z-20 mb-1 max-h-52 w-64 max-w-full overflow-y-auto rounded-lg bg-white p-1 shadow-lg ring-1 ring-gray-950/5 dark:bg-gray-800 dark:ring-white/10"
                            >
                                <template x-for="(person, i) in items" :key="person.key">
                                    <li>
                                        <button
                                            type="button"
                                            x-on:mousedown.prevent="pick(person.name)"
                                            x-bind:class="i === index ? 'bg-primary-50 text-primary-700 dark:bg-primary-500/15 dark:text-primary-300' : 'text-gray-700 dark:text-gray-200'"
                                            class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-start text-sm"
                                        >
                                            <img x-show="person.avatar" x-bind:src="person.avatar" alt="" class="size-6 shrink-0 rounded-full object-cover" />
                                            <span class="text-gray-400">@</span><span x-text="person.name"></span>
                                        </button>
                                    </li>
                                </template>
                            </ul>
                            <textarea
                                x-ref="input"
                                wire:model="body"
                                rows="1"
                                data-gramm="false"
                                data-gramm_editor="false"
                                data-enable-grammarly="false"
                                x-on:keydown="key($event)"
                                x-on:input="resize(); scan(); $dispatch('{{ ChatWindow::EVENT_TYPING }}')"
                                x-on:paste="$dispatch('{{ ChatWindow::EVENT_PASTE }}', { event: $event })"
                                x-on:click="scan()"
                                x-on:blur="open = false"
                                placeholder="{{ __('filament-chat::chat.placeholder') }}"
                                title="{{ __('filament-chat::chat.enter_hint') }}"
                                class="block max-h-40 w-full resize-none rounded-lg border-0 bg-gray-50 px-3 py-2 text-sm text-gray-950 ring-1 ring-gray-950/10 focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/20"
                            ></textarea>
                        </div>
                        {{ $hook(ChatHook::COMPOSER_TOOLS) }}
                        <x-filament::icon-button
                            type="submit"
                            icon="heroicon-m-paper-airplane"
                            size="lg"
                            x-on:mousedown.prevent
                            :label="$editing ? __('filament-chat::chat.save_edit') : __('filament-chat::chat.send')"
                        />
                    </div>
                    @if (($composerAfter = $hook(ChatHook::COMPOSER_AFTER))->isNotEmpty())
                        <div wire:key="fchat-hook-composer-after">{{ $composerAfter }}</div>
                    @endif
                </form>
            @else
                <div wire:key="fchat-left-hint" class="border-t border-gray-200 p-3 text-center text-sm text-gray-500 dark:border-white/10 dark:text-gray-400">
                    {{ __('filament-chat::chat.left_hint') }}
                </div>
            @endif
        @else
            <div wire:key="fchat-pick" class="flex flex-1 items-center justify-center p-6 text-center text-sm text-gray-500 dark:text-gray-400">
                {{ __('filament-chat::chat.pick') }}
            </div>
        @endif
    </section>

    <x-filament-actions::modals />
</div>
