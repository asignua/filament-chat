{{--
    A record reference in a message: the type's icon and colour, type and title.
    Without the right to view — no link.
--}}
@php
    $tag = $reference['url'] ? 'a' : 'span';
@endphp
<{{ $tag }}
    @if ($reference['url']) href="{{ $reference['url'] }}" wire:navigate @endif
    @class([
        'mt-1 flex max-w-full items-center gap-2 rounded-lg px-2 py-1.5 text-xs',
        'bg-white/15 hover:bg-white/25' => $mine,
        'bg-white ring-1 ring-gray-950/5 hover:bg-gray-50 dark:bg-gray-900 dark:ring-white/10 dark:hover:bg-white/5' => !$mine,
    ])
>
    <x-filament::icon
        :icon="$reference['icon']"
        @class(['size-4 shrink-0', 'fi-color-'.$reference['color'].' fchat-accent' => !$mine])
    />
    <span @class(['shrink-0', 'text-white/70' => $mine, 'text-gray-500 dark:text-gray-400' => !$mine])>{{ $reference['type'] }}</span>
    <span class="truncate font-medium">{{ $reference['label'] }}</span>
</{{ $tag }}>
