{{--
    The Organization nav group's tab links. Rendered twice on every page --
    once in the always-in-DOM expanded accordion, once inside the collapsed
    sidebar's icon-rail dropdown -- so `scope` namespaces the rolling-number
    badges' wire:keys the same way `x-boards-nav-list` does for board counts.
--}}
@props(['tabs', 'counts', 'active', 'scope'])

@foreach ($tabs as $key => $label)
    <a
        href="{{ route('ideas.settings', ['tab' => $key]) }}"
        wire:navigate
        @class([
            'flex w-full items-center justify-between gap-2 rounded-lg py-1.5 ps-2 pe-0.5 text-sm transition',
            'bg-zinc-800/5 font-semibold text-slate-900 dark:bg-white/[7%] dark:text-white' => $active === $key,
            'text-slate-700 hover:bg-zinc-800/5 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-white/[7%] dark:hover:text-white' => $active !== $key,
        ])
        data-test="organization-nav-tab-link"
    >
        <span class="truncate">{{ $label }}</span>

        @if (($counts[$key] ?? 0) > 0)
            <span class="inline-flex h-5 min-w-8 shrink-0 items-center justify-center rounded-sm bg-zinc-400/15 px-1 text-xs font-medium text-zinc-700 dark:bg-zinc-400/40 dark:text-zinc-200">
                <x-rolling-number name="org-tab-{{ $scope }}-{{ $key }}" :value="$counts[$key]" />
            </span>
        @endif
    </a>
@endforeach
