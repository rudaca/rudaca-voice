{{--
    A minimal Alpine-driven hover tooltip for a user's org role, meant to
    wrap an avatar. Used instead of <flux:tooltip> because it sits inside
    rows that may already carry their own hover affordances, and instead
    of a persistent badge because the role rarely needs to be visible at
    a glance -- just discoverable on hover.
--}}
@props([
    'role',
])

<span class="relative inline-flex" x-data="{ open: false }" x-on:mouseenter="open = true" x-on:mouseleave="open = false">
    {{ $slot }}

    <span
        x-show="open"
        x-cloak
        x-transition.opacity
        class="pointer-events-none absolute bottom-full left-1/2 z-10 mb-1.5 -translate-x-1/2 rounded-md bg-zinc-800 px-2.5 py-1 text-xs font-medium whitespace-nowrap text-white dark:bg-zinc-700"
        data-test="role-tooltip"
    >{{ $role }}</span>
</span>
