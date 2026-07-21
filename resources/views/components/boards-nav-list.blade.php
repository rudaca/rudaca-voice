@props(['groups', 'ungrouped'])

@php
    $boardTileIndex = 0;
    $isIdeasIndex = request()->routeIs('ideas.index');
    $activeBoardId = $isIdeasIndex ? (int) request()->query('board', 0) : 0;
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
                    'flex w-full cursor-pointer items-center gap-1 rounded-lg px-2 py-1.5 text-xs font-semibold tracking-wide uppercase',
                    'bg-zinc-800/5 text-zinc-800 dark:bg-white/[7%] dark:text-zinc-200' => $group->id === $activeGroupId,
                    'text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200' => $group->id !== $activeGroupId,
                ])
                data-test="board-group-toggle"
            >
                <flux:icon.chevron-right class="size-3.5 shrink-0 transition-transform duration-200 ease-out rtl:-scale-x-100" :class="{ 'rotate-90': open }" />
                <span class="truncate">{{ $group->name }}</span>
                <span class="ms-auto font-normal text-zinc-400 dark:text-zinc-500">{{ $group->boards->sum('ideas_count') }}</span>
            </div>

            <div class="grid transition-[grid-template-rows] duration-200 ease-out" :class="open ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]'">
                <div class="flex flex-col gap-0.5 overflow-hidden ps-2">
                    @foreach ($group->boards as $board)
                        <a
                            href="{{ route('ideas.index', ['board' => $board->id]) }}"
                            wire:navigate
                            @class([
                                'flex items-center gap-2.5 rounded-lg px-2 py-1.5 text-sm transition',
                                'bg-zinc-800/5 font-semibold text-zinc-900 dark:bg-white/[7%] dark:text-white' => $board->id === $activeBoardId,
                                'text-zinc-600 hover:bg-zinc-800/5 hover:text-zinc-900 dark:text-zinc-300 dark:hover:bg-white/[7%] dark:hover:text-white' => $board->id !== $activeBoardId,
                            ])
                            data-test="sidebar-board-link"
                        >
                            <x-board-avatar :name="$board->name" :index="$boardTileIndex++" size="size-6 text-xs" />
                            <span class="min-w-0 flex-1 truncate">{{ $board->name }}</span>
                            <span class="text-xs text-zinc-400 dark:text-zinc-500">{{ $board->ideas_count }}</span>
                        </a>
                    @endforeach
                </div>
            </div>
        </div>
    @endforeach

    @foreach ($ungrouped as $board)
        <a
            href="{{ route('ideas.index', ['board' => $board->id]) }}"
            wire:navigate
            @class([
                'flex items-center gap-2.5 rounded-lg px-2 py-1.5 text-sm transition',
                'bg-zinc-800/5 text-zinc-900 dark:bg-white/[7%] dark:text-white' => $board->id === $activeBoardId,
                'text-zinc-600 hover:bg-zinc-800/5 hover:text-zinc-900 dark:text-zinc-300 dark:hover:bg-white/[7%] dark:hover:text-white' => $board->id !== $activeBoardId,
            ])
            data-test="sidebar-board-link"
        >
            <x-board-avatar :name="$board->name" :index="$boardTileIndex++" size="size-6 text-xs" />
            <span class="min-w-0 flex-1 truncate">{{ $board->name }}</span>
            <span class="text-xs text-zinc-400 dark:text-zinc-500">{{ $board->ideas_count }}</span>
        </a>
    @endforeach
</div>
