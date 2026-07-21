@props(['items' => []])

@php
    $home = [
        'label' => __('Home'),
        'href' => auth()->user()?->currentTeam
            ? route('dashboard', ['current_team' => auth()->user()->currentTeam->slug])
            : route('teams.index'),
        'icon' => 'home',
    ];

    $trail = [$home, ...$items];
    $collapsed = count($trail) > 3;

    if ($collapsed) {
        $first = $trail[0];
        $hidden = array_slice($trail, 1, -1);
        $last = $trail[count($trail) - 1];
    }

    $activeClass = 'text-xs font-semibold text-zinc-800 dark:text-white';
    $inactiveClass = 'text-xs';
@endphp

<flux:breadcrumbs {{ $attributes->class('flex items-center leading-none') }}>
    @if ($collapsed)
        <flux:breadcrumbs.item :href="$first['href'] ?? null" :icon="$first['icon'] ?? null" class="{{ $inactiveClass }}" />

        <div class="flex items-center">
            <flux:dropdown position="bottom" align="start">
                <button
                    type="button"
                    class="flex items-center rounded px-1 text-zinc-400 transition hover:text-zinc-700 dark:hover:text-zinc-200"
                    data-test="breadcrumbs-ellipsis"
                >
                    <flux:icon name="ellipsis-horizontal" variant="mini" class="size-3.5" />
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

            <flux:icon icon="chevron-right" variant="mini" class="mx-0.5 text-zinc-300 rtl:hidden dark:text-white/80" />
            <flux:icon icon="chevron-left" variant="mini" class="mx-0.5 hidden text-zinc-300 rtl:inline dark:text-white/80" />
        </div>

        <flux:breadcrumbs.item :href="$last['href'] ?? null" class="{{ ($last['href'] ?? null) === null ? $activeClass : $inactiveClass }}">
            {{ $last['label'] }}
        </flux:breadcrumbs.item>
    @else
        @foreach ($trail as $step)
            <flux:breadcrumbs.item :href="$step['href'] ?? null" :icon="$step['icon'] ?? null" class="{{ ($step['href'] ?? null) === null ? $activeClass : $inactiveClass }}">
                {{ $step['label'] }}
            </flux:breadcrumbs.item>
        @endforeach
    @endif
</flux:breadcrumbs>
