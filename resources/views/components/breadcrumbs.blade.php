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
    $collapsed = count($trail) > 4;

    if ($collapsed) {
        $first = $trail[0];
        $hidden = array_slice($trail, 1, -1);
        $last = $trail[count($trail) - 1];
    }

    $activeClass = 'text-xs font-semibold text-slate-900 dark:text-white';
    $inactiveClass = 'text-xs';
@endphp

<flux:breadcrumbs {{ $attributes->class('flex items-center leading-none') }}>
    @if ($collapsed)
        <flux:breadcrumbs.item :href="$first['href'] ?? null" :icon="$first['icon'] ?? null" class="{{ $inactiveClass }}" />

        <div class="flex items-center">
            <flux:dropdown position="bottom" align="start">
                <button
                    type="button"
                    class="flex items-center rounded px-1 text-slate-500 transition hover:text-slate-800 dark:hover:text-slate-300"
                    data-test="breadcrumbs-ellipsis"
                >
                    <flux:icon name="ellipsis-horizontal" variant="outline" class="size-4" />
                </button>

                <flux:menu class="min-w-40">
                    <flux:menu.heading>{{ $hidden[0]['label'] }}</flux:menu.heading>
                    <div class="relative ms-4 ps-3">
                        <div class="absolute inset-y-1 start-0 w-px bg-zinc-200 dark:bg-white/30" aria-hidden="true"></div>
                        @foreach (array_slice($hidden, 1) as $step)
                            <flux:menu.item :href="$step['href'] ?? null">
                                {{ $step['label'] }}
                            </flux:menu.item>
                        @endforeach
                    </div>
                </flux:menu>
            </flux:dropdown>

            <flux:icon icon="chevron-right" variant="outline" class="mx-0.5 text-slate-400 rtl:hidden dark:text-white/80" />
            <flux:icon icon="chevron-left" variant="outline" class="mx-0.5 hidden text-slate-400 rtl:inline dark:text-white/80" />
        </div>

        <flux:breadcrumbs.item :href="$last['href'] ?? null" class="{{ ($last['href'] ?? null) === null ? $activeClass : $inactiveClass }}">
            {{ $last['label'] }}
        </flux:breadcrumbs.item>
    @else
        @foreach ($trail as $step)
            <flux:breadcrumbs.item :href="$step['href'] ?? null" :icon="$step['icon'] ?? null" class="{{ $loop->last && ($step['href'] ?? null) === null ? $activeClass : $inactiveClass }}">
                {{ $step['label'] }}
            </flux:breadcrumbs.item>
        @endforeach
    @endif
</flux:breadcrumbs>
