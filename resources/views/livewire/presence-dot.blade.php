{{--
    The green dot on the corner of an avatar: only for somebody who is online
    (offline is the absence of the dot; "last seen" is text in the header). The parent
    is `relative`; `end-0` is the logical side, so the dot mirrors in RTL.
--}}
@if ($status?->online)
    <span
        data-fchat-online
        role="img"
        aria-label="{{ __('filament-chat::chat.online') }}"
        title="{{ __('filament-chat::chat.online') }}"
        class="absolute bottom-0 end-0 size-2.5 rounded-full bg-success-500 ring-2 ring-white dark:ring-gray-900"
    ></span>
@endif
