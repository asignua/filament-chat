{{-- The chat button before the user menu; on the Chat page (or without the dock) only a quiet counter for the tab badge. --}}
@use('Asignua\FilamentChat\Livewire\ChatDock')
@use('Asignua\FilamentChat\Pages\Chat')
@auth
    @livewire(ChatDock::class, ['quiet' => !$dock || request()->routeIs(Chat::getRouteName())], key('filament-chat-dock'))
@endauth
