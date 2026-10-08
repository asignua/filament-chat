{{--
    The chat slide-over on the right (render hook BODY_END; not on the Chat page).

    Deliberately WITHOUT its own Livewire component: the "open" state lives in
    Alpine only and nothing on the server re-renders this wrapper. A re-render
    would re-initialise the nested window (the composer lost focus) and the
    morph would break x-show of a freshly opened panel.

    "Pin" (from lg): the panel stays open on every page and pushes it aside
    (html.fchat-pinned; hooks/pinned sets the class before the first paint).
    Closing unpins. The last open conversation is remembered and reopened until
    one goes back to the list. Both in localStorage — per-browser comfort, not data.

    The window is lazy: it loads when the panel first becomes visible. A
    conversation requested before that (toast, record action) waits in `pending`;
    the window reports readiness with an event.
--}}
@use('Asignua\FilamentChat\Livewire\ChatDock')
@use('Asignua\FilamentChat\Livewire\ChatWindow')
@use('Asignua\FilamentChat\Pages\Chat')
@use('Asignua\FilamentChat\Support\ChatConfig')
@if (auth()->check() && !request()->routeIs(Chat::getRouteName()))
    <div
        class="fchat-scope"
        x-data="{
            open: false,
            pinned: false,
            ready: false,
            pending: null,
            escapeTaken: false,
            read(key) {
                try { return window.localStorage.getItem(key) } catch (e) { return null }
            },
            write(key, value) {
                try { value === null ? window.localStorage.removeItem(key) : window.localStorage.setItem(key, value) } catch (e) {}
            },
            init() {
                this.pinned = @js($pinnable) && this.read(@js(ChatDock::STORAGE_PINNED)) === '1'
                // wire:navigate replaces the class of <html> with the server's: put it back (hooks/pinned only runs on a full load).
                document.documentElement.classList.toggle(@js(ChatDock::PINNED_CLASS), this.pinned)
                if (this.pinned) {
                    // Pinned: open right away, no animation, on the last conversation.
                    this.open = true
                    this.pending = this.read(@js(ChatDock::STORAGE_CONVERSATION))
                    this.$nextTick(() => this.announce())
                }
            },
            announce() {
                Livewire.dispatch(@js(ChatWindow::EVENT_VISIBILITY), { visible: this.open })
                Livewire.dispatch(@js(ChatDock::EVENT_PANEL_VISIBILITY), { open: this.open })
            },
            set(open) {
                this.open = open
                if (! open && this.pinned) this.pin(false)
                this.announce()
            },
            pin(pinned) {
                this.pinned = pinned
                this.write(@js(ChatDock::STORAGE_PINNED), pinned ? '1' : null)
                document.documentElement.classList.toggle(@js(ChatDock::PINNED_CLASS), pinned)
            },
            show(conversation) {
                conversation = conversation || this.read(@js(ChatDock::STORAGE_CONVERSATION))
                if (! this.open) this.set(true)
                if (! conversation) return
                if (this.ready) Livewire.dispatch(@js(ChatWindow::EVENT_OPEN), { conversation: conversation })
                else this.pending = conversation
            },
            remember(conversation) {
                this.write(@js(ChatDock::STORAGE_CONVERSATION), conversation || null)
            },
            windowReady() {
                this.ready = true
                if (this.pending) {
                    Livewire.dispatch(@js(ChatWindow::EVENT_OPEN), { conversation: this.pending })
                    this.pending = null
                }
            },
        }"
        x-on:{{ ChatDock::EVENT_TOGGLE }}.window="open ? set(false) : show(null)"
        x-on:{{ ChatDock::EVENT_OPEN_DOCK }}.window="show($event.detail?.conversation)"
        x-on:{{ ChatWindow::EVENT_READY }}.window="windowReady()"
        x-on:{{ ChatWindow::EVENT_OPENED }}.window="remember($event.detail?.conversation)"
        {{-- Escape belongs to an open modal or dropdown first. The capture listener looks before they close themselves; the bubbling one acts. --}}
        x-on:keydown.escape.capture.window="escapeTaken = !! (document.querySelector('.fi-modal-open') || document.activeElement?.closest('[aria-expanded=true]'))"
        x-on:keydown.escape.window="if (open && ! pinned && ! escapeTaken && ! $event.defaultPrevented) set(false)"
    >
        <div
            x-show="open"
            x-cloak
            class="fchat-dock fixed inset-y-0 end-0 z-40 flex w-full max-w-md flex-col bg-white shadow-2xl ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="translate-x-full"
            x-transition:enter-end="translate-x-0"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="translate-x-0"
            x-transition:leave-end="translate-x-full"
        >
            <div class="flex items-center gap-2 border-b border-gray-200 px-3 py-2 dark:border-white/10">
                <x-filament::icon :icon="Chat::icon()" class="size-5 text-gray-400" />
                <span class="flex-1 font-semibold">{{ __('filament-chat::chat.chat') }}</span>
                <x-filament::icon-button
                    icon="heroicon-m-arrows-pointing-out"
                    color="gray"
                    tag="a"
                    :href="Chat::getUrl()"
                    :label="__('filament-chat::chat.expand')"
                />
                @if ($pinnable)
                    <x-filament::icon-button
                        icon="heroicon-m-map-pin"
                        color="gray"
                        class="max-lg:hidden"
                        x-on:click="pin(! pinned)"
                        x-bind:class="pinned && 'fchat-pin-active'"
                        x-bind:aria-pressed="pinned"
                        :label="__('filament-chat::chat.pin')"
                    />
                @endif
                <x-filament::icon-button
                    icon="heroicon-m-x-mark"
                    color="gray"
                    x-on:click="set(false)"
                    :label="__('filament-chat::chat.close')"
                />
            </div>
            <div class="min-h-0 flex-1">
                {{-- The configured window class (an extension may swap it); `lazy` goes in the parameters, as <livewire:… lazy> compiles it. --}}
                @livewire(ChatConfig::windowComponent(), ['compact' => true, 'pageUrl' => request()->fullUrl(), 'lazy' => true], key('filament-chat-panel-window'))
            </div>
        </div>
    </div>
@endif
