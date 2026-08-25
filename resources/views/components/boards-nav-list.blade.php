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
        <x-sidebar-nav-group
            :label="$group->name"
            :active="$group->id === $activeGroupId"
            :default-open="true"
            data-test="board-group-toggle"
        >
            <x-slot:badge>
                <x-rolling-number name="{{ $scope }}-group-{{ $group->id }}" :value="$group->boards->sum('ideas_count')" />
            </x-slot:badge>

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
        </x-sidebar-nav-group>
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
