{{--
    `scope` namespaces the badge odometers' keys. This list is rendered twice on
    every page -- once expanded in the sidebar and once inside the collapsed
    sidebar's dropdown -- so the two copies need distinct names.
--}}
@props(['groups', 'ungrouped', 'scope' => 'sidebar'])

@php
    $isIdeasIndex = request()->routeIs('ideas.index');
    $boardParam = request()->query('board', 0);
    $activeBoardId = $isIdeasIndex ? (int) (is_array($boardParam) ? reset($boardParam) : $boardParam) : 0;
    $activeGroupId = $isIdeasIndex ? (int) request()->query('group', 0) : 0;
@endphp

<div class="flex flex-col gap-0.5">
    @foreach ($groups as $group)
        <div x-data="{ open: true }">
            {{-- Deliberately a <div role="button">, not a <button>: Flux's ui-menu
            auto-wires every descendant <button>/<a> to close the popover on click
            (intended for menu-item selection), which would close the Boards
            popover whenever a group is expanded/collapsed instead of just toggling it. --}}
            <div
                role="button"
                tabindex="0"
                @click="open = !open"
                @keydown.enter.prevent="open = !open"
                @keydown.space.prevent="open = !open"
                @class([
                    'flex w-full cursor-pointer items-center justify-between gap-2 rounded-lg py-1.5 ps-2 pe-0.5 text-xs font-semibold tracking-wide',
                    'bg-zinc-800/5 text-slate-900 dark:bg-white/[7%] dark:text-slate-300' => $group->id === $activeGroupId,
                    'text-slate-600 hover:text-slate-900 dark:text-slate-500 dark:hover:text-slate-300' => $group->id !== $activeGroupId,
                ])
                data-test="board-group-toggle"
            >
                <span class="flex min-w-0 items-center gap-1">
                    <span class="inline-flex shrink-0 transition-transform duration-200 ease-out" :class="{ 'rotate-180': open }">
                        <flux:icon.chevron-down class="size-3.5" />
                    </span>
                    <span class="truncate">{{ $group->name }}</span>
                </span>
                <span class="inline-flex h-5 min-w-8 shrink-0 items-center justify-center rounded-sm bg-zinc-400/15 px-1 text-xs font-medium text-zinc-700 dark:bg-zinc-400/40 dark:text-zinc-200">
                    <x-rolling-number name="{{ $scope }}-group-{{ $group->id }}" :value="$group->boards->sum('ideas_count')" />
                </span>
            </div>

            <div class="grid transition-[grid-template-rows] duration-200 ease-out" :class="open ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]'">
                <div class="ms-3.5 flex flex-col gap-0.5 overflow-hidden border-s border-zinc-300 ps-2.5 dark:border-zinc-600">
                    @foreach ($group->boards as $board)
                        <a
                            href="{{ $board->filterUrl() }}"
                            wire:navigate
                            @class([
                                'flex w-full items-center gap-2.5 rounded-lg py-1.5 ps-2 pe-0.5 text-sm transition',
                                'bg-zinc-800/5 font-semibold text-slate-900 dark:bg-white/[7%] dark:text-white' => $board->id === $activeBoardId,
                                'text-slate-700 hover:bg-zinc-800/5 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-white/[7%] dark:hover:text-white' => $board->id !== $activeBoardId,
                            ])
                            data-test="sidebar-board-link"
                        >
                            <span class="min-w-0 flex-1 truncate">{{ $board->name }}</span>
                            <span class="inline-flex h-5 min-w-8 shrink-0 items-center justify-center rounded-sm bg-zinc-400/15 px-1 text-xs font-medium text-zinc-700 dark:bg-zinc-400/40 dark:text-zinc-200">
                                <x-rolling-number name="{{ $scope }}-board-{{ $board->id }}" :value="$board->ideas_count" />
                            </span>
                        </a>
                    @endforeach
                </div>
            </div>
        </div>
    @endforeach

    @foreach ($ungrouped as $board)
        <a
            href="{{ $board->filterUrl() }}"
            wire:navigate
            @class([
                'flex w-full items-center gap-2.5 rounded-lg py-1.5 ps-2 pe-0.5 text-sm transition',
                'bg-zinc-800/5 text-slate-900 dark:bg-white/[7%] dark:text-white' => $board->id === $activeBoardId,
                'text-slate-700 hover:bg-zinc-800/5 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-white/[7%] dark:hover:text-white' => $board->id !== $activeBoardId,
            ])
            data-test="sidebar-board-link"
        >
            <span class="min-w-0 flex-1 truncate">{{ $board->name }}</span>
            <span class="inline-flex h-5 min-w-8 shrink-0 items-center justify-center rounded-sm bg-zinc-400/15 px-1 text-xs font-medium text-zinc-700 dark:bg-zinc-400/40 dark:text-zinc-200">
                <x-rolling-number name="{{ $scope }}-board-{{ $board->id }}" :value="$board->ideas_count" />
            </span>
        </a>
    @endforeach
</div>
