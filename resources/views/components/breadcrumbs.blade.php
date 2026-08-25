@props(['items' => []])

@php
    $currentTeam = auth()->user()?->currentTeam;

    $home = [
        'label' => __('Home'),
        'href' => $currentTeam
            ? route('dashboard', ['current_team' => $currentTeam->slug])
            : route('teams.index'),
        'icon' => 'home',
    ];

    $team = $currentTeam ? [
        'label' => $currentTeam->name,
        'href' => null,
    ] : null;

    $trail = [$home, ...($team ? [$team] : []), ...$items];

    // On mobile, pin the lead (home icon + team) and current page, and
    // collapse everything between them into a "…" dropdown so a deep trail
    // never overflows. Desktop always shows the trail in full.
    $leadCount = $team ? 2 : 1;
    $mobileCollapsed = count($trail) > $leadCount + 1;

    if ($mobileCollapsed) {
        $lead = array_slice($trail, 0, $leadCount);
        $hidden = array_slice($trail, $leadCount, -1);
        $last = $trail[count($trail) - 1];
    }

    $activeClass = 'text-xs font-semibold text-slate-900 dark:text-white';
    $inactiveClass = 'text-xs';
@endphp

<flux:breadcrumbs {{ $attributes->class('flex items-center leading-none') }}>
    @if ($mobileCollapsed)
        <div class="hidden items-center sm:flex">
            @foreach ($trail as $step)
                <flux:breadcrumbs.item :href="$step['href'] ?? null" :icon="$step['icon'] ?? null" class="{{ $loop->last && ($step['href'] ?? null) === null ? $activeClass : $inactiveClass }}">
                    {{ $step['label'] }}
                </flux:breadcrumbs.item>
            @endforeach
        </div>

        <div class="flex items-center sm:hidden">
            @foreach ($lead as $step)
                <flux:breadcrumbs.item :href="$step['href'] ?? null" :icon="$step['icon'] ?? null" class="{{ $inactiveClass }}">
                    {{ $step['label'] ?? '' }}
                </flux:breadcrumbs.item>
            @endforeach

            <div class="flex items-center">
                <flux:dropdown position="bottom" align="start">
                    <button
                        type="button"
                        class="flex items-center rounded px-1 text-slate-700 transition hover:text-slate-800 dark:hover:text-slate-300"
                        data-test="breadcrumbs-ellipsis"
                    >
                        <flux:icon name="ellipsis-horizontal" variant="outline" class="size-4" />
                    </button>

                    <flux:menu class="min-w-40">
                        @foreach ($hidden as $step)
                            <flux:menu.item :href="$step['href'] ?? null">
                                {{ $step['label'] }}
                            </flux:menu.item>
                        @endforeach
                    </flux:menu>
                </flux:dropdown>

                <flux:icon icon="chevron-right" variant="outline" class="mx-0.5 text-slate-400 rtl:hidden dark:text-white/80" />
                <flux:icon icon="chevron-left" variant="outline" class="mx-0.5 hidden text-slate-400 rtl:inline dark:text-white/80" />
            </div>

            <flux:breadcrumbs.item :href="$last['href'] ?? null" class="{{ ($last['href'] ?? null) === null ? $activeClass : $inactiveClass }}">
                {{ $last['label'] }}
            </flux:breadcrumbs.item>
        </div>
    @else
        @foreach ($trail as $step)
            <flux:breadcrumbs.item :href="$step['href'] ?? null" :icon="$step['icon'] ?? null" class="{{ $loop->last && ($step['href'] ?? null) === null ? $activeClass : $inactiveClass }}">
                {{ $step['label'] }}
            </flux:breadcrumbs.item>
        @endforeach
    @endif
</flux:breadcrumbs>
