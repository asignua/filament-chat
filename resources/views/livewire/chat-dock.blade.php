{{--
    The chat button in the top bar: unread counter. Show / hide the slide-over
    is a browser event the slide-over catches without a server round trip.
    Polling once a minute backs the counter up without a socket.
    x-effect — the same number on the tab badge (hooks/tab-badge).
--}}
@use('Asignua\FilamentChat\Livewire\ChatDock')
@use('Asignua\FilamentChat\Pages\Chat')
<div wire:poll.{{ $polling }}s class="fchat-dock-button" x-data x-effect="window.filamentChatBadge?.($wire.unread)">
    @unless ($quiet)
        <x-filament::icon-button
            :icon="Chat::icon()"
            color="gray"
            size="lg"
            x-on:click="$dispatch('{{ ChatDock::EVENT_TOGGLE }}')"
            :label="__('filament-chat::chat.chat')"
            :badge="$unread > 0 ? $unread : null"
            badge-color="danger"
        />
    @endunless
</div>
