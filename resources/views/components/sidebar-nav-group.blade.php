{{--
    A collapsible sidebar section: a toggle header that expands or collapses a
    nested, indented list of links. Extracted from the Boards section's
    per-group markup so other collapsible sidebar sections (e.g. Organization
    Settings) share the same behavior instead of reimplementing it.

    Two visual variants:
    - `section` (default): the small-caps group header used for Boards groups
      — chevron leading, no icon. Hidden entirely when the sidebar collapses
      to its icon rail (Boards has its own icon-rail dropdown fallback,
      rendered separately at the call site).
    - `item`: sized and spaced like a `flux:sidebar.item` row, with a leading
      icon and a trailing chevron, for a top-level entry like Organization.
      When the sidebar collapses to its icon rail, this swaps to an
      icon-only trigger that opens the same nested links in a dropdown —
      the same icon-rail fallback the Boards section uses.
--}}
@props(['label', 'active' => false, 'defaultOpen' => false, 'icon' => null, 'variant' => 'section'])

{{--
    Note: this outer wrapper is deliberately NOT hidden via
    `in-data-flux-sidebar-collapsed-desktop:*` — that attribute matches any
    collapsed-sidebar ancestor, including a dropdown/menu panel that's a DOM
    descendant of the sidebar but meant to stay visible regardless (Boards'
    icon-rail dropdown reuses this same markup for its groups). Hiding is the
    call site's job: wrap the always-hidden-when-collapsed copy yourself.
--}}
<div x-data="{ open: @js($defaultOpen) }">
    {{-- Deliberately a <div role="button">, not a <button>: Flux's ui-menu
    auto-wires every descendant <button>/<a> to close the popover on click
    (intended for menu-item selection), which would close a containing
    popover whenever this group is expanded/collapsed instead of just
    toggling it. --}}
    <div
        role="button"
        tabindex="0"
        @click="open = !open"
        @keydown.enter.prevent="open = !open"
        @keydown.space.prevent="open = !open"
        {{ $attributes->class([
            'flex w-full cursor-pointer items-center justify-between gap-2 rounded-lg transition',
            'in-data-flux-sidebar-collapsed-desktop:hidden' => $variant === 'item',
            'py-1.5 ps-2 pe-0.5 text-xs font-semibold tracking-wide' => $variant === 'section',
            'h-8 px-3 text-sm font-medium' => $variant === 'item',
            'bg-zinc-800/5 text-slate-900 dark:bg-white/[7%] dark:text-slate-300' => $active && $variant === 'section',
            'text-slate-600 hover:text-slate-900 dark:text-slate-500 dark:hover:text-slate-300' => ! $active && $variant === 'section',
            'bg-zinc-800/5 font-semibold text-slate-900 dark:bg-white/[7%] dark:text-white' => $active && $variant === 'item',
            'text-slate-700 hover:bg-zinc-800/5 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-white/[7%] dark:hover:text-white' => ! $active && $variant === 'item',
        ]) }}
    >
        <span class="flex min-w-0 items-center gap-2">
            @if ($variant === 'section')
                <span class="inline-flex shrink-0 transition-transform duration-200 ease-out" :class="{ 'rotate-180': open }">
                    <flux:icon.chevron-down class="size-3.5" />
                </span>
            @elseif ($icon)
                <flux:icon :icon="$icon" variant="outline" class="size-4 shrink-0" />
            @endif

            <span class="truncate">{{ $label }}</span>
        </span>

        <span class="flex shrink-0 items-center gap-2">
            @isset($badge)
                <span class="inline-flex h-5 min-w-8 shrink-0 items-center justify-center rounded-sm bg-zinc-400/15 px-1 text-xs font-medium text-zinc-700 dark:bg-zinc-400/40 dark:text-zinc-200">
                    {{ $badge }}
                </span>
            @endisset

            @if ($variant === 'item')
                <span class="inline-flex shrink-0 transition-transform duration-200 ease-out" :class="{ 'rotate-180': open }">
                    <flux:icon.chevron-down class="size-3.5" />
                </span>
            @endif
        </span>
    </div>

    @if ($variant === 'item' && $icon)
        <div class="hidden in-data-flux-sidebar-collapsed-desktop:flex justify-center">
            <div class="group/tooltip relative">
                <flux:dropdown position="right" align="start" data-test="sidebar-nav-group-collapsed-dropdown">
                    <button
                        type="button"
                        @class([
                            'flex size-10 items-center justify-center rounded-lg transition',
                            'bg-white text-slate-900 shadow-xs dark:bg-white/[7%] dark:text-white' => $active,
                            'text-slate-600 hover:bg-zinc-800/5 hover:text-slate-900 dark:text-white/80 dark:hover:bg-white/[7%] dark:hover:text-white' => ! $active,
                        ])
                        aria-label="{{ $label }}"
                        data-test="sidebar-nav-group-collapsed-trigger"
                    >
                        <flux:icon :icon="$icon" variant="outline" class="size-5" />
                    </button>

                    <flux:menu class="max-h-[70vh] w-64 overflow-y-auto">
                        <flux:menu.heading class="font-bold! uppercase">{{ $label }}</flux:menu.heading>

                        <div class="mt-1 flex flex-col gap-0.5 overflow-hidden border-s border-zinc-300 p-1 ps-4 ms-5 dark:border-zinc-600">
                            {{ $collapsed ?? $slot }}
                        </div>
                    </flux:menu>
                </flux:dropdown>

                <div class="pointer-events-none absolute start-full top-1/2 z-50 ms-2 -translate-y-1/2 scale-95 rounded-md bg-zinc-800 px-2 py-1 text-xs font-medium whitespace-nowrap text-white opacity-0 shadow-sm transition delay-300 duration-150 group-hover/tooltip:scale-100 group-hover/tooltip:opacity-100 dark:bg-zinc-700 dark:border dark:border-white/10">
                    {{ $label }}
                </div>
            </div>
        </div>
    @endif

    <div
        @class([
            'grid transition-[grid-template-rows] duration-200 ease-out',
            'in-data-flux-sidebar-collapsed-desktop:hidden' => $variant === 'item',
        ])
        :class="open ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]'"
    >
        <div @class([
            'mt-1 flex flex-col gap-0.5 overflow-hidden border-s border-zinc-300 ps-4 dark:border-zinc-600',
            'ms-3.5' => $variant === 'section',
            'ms-5' => $variant === 'item',
        ])>
            {{ $slot }}
        </div>
    </div>
</div>
