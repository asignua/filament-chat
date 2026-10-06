{{--
    The Chat page — the same window as in the slide-over, full width. The
    height runs from the window's actual top to the bottom of the screen: the
    top bar may take one or two rows, so a fixed calc() left a page scroll.
    The wrapper sits outside the component — its re-render keeps the measured height.
--}}
@use('Asignua\FilamentChat\Support\ChatConfig')
<x-filament-panels::page>
    <div
        class="min-h-[28rem]"
        x-data="{
            fit() {
                // First down to the screen bottom, then minus whatever is left below (page paddings).
                let height = window.innerHeight - ($el.getBoundingClientRect().top + window.scrollY)
                $el.style.height = height + 'px'
                height -= Math.max(0, document.documentElement.scrollHeight - window.innerHeight)
                $el.style.height = Math.max(448, height) + 'px'
            },
        }"
        x-init="$nextTick(() => fit())"
        x-on:resize.window.debounce.100ms="fit()"
    >
        @livewire(ChatConfig::windowComponent(), ['conversation' => $this->initialConversation()], key('filament-chat-page-window'))
    </div>
</x-filament-panels::page>
