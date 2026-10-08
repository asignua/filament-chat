{{--
    A pinned slide-over pushes the page aside — the class goes on <html> before
    the first paint, otherwise every navigation would jump sideways.
    No slide-over (and no offset) on the Chat page itself.
--}}
@use('Asignua\FilamentChat\Livewire\ChatDock')
@use('Asignua\FilamentChat\Pages\Chat')
@if (auth()->check() && !request()->routeIs(Chat::getRouteName()))
    <script>
        try {
            if (window.localStorage.getItem(@js(ChatDock::STORAGE_PINNED)) === '1') {
                document.documentElement.classList.add(@js(ChatDock::PINNED_CLASS));
            }
        } catch (e) {}
    </script>
    {{-- With ->spa() Livewire swaps the class of <html> on every navigation and does not re-run the script above. --}}
    <script data-navigate-once>
        document.addEventListener('livewire:navigated', () => {
            try {
                // The slide-over of the new page (hooks/dock-panel) re-applies it in init(); this covers the gap before it mounts.
                document.documentElement.classList.toggle(
                    @js(ChatDock::PINNED_CLASS),
                    window.localStorage.getItem(@js(ChatDock::STORAGE_PINNED)) === '1' && !document.querySelector('.fchat-page'),
                );
            } catch (e) {}
        });
    </script>
@endif
