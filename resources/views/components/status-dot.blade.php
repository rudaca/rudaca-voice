{{--
    A small colored dot that pairs with a <flux:badge> of the same color.

    The shades below mirror the *text* color Flux renders for each badge color
    in its default (soft) variant, so the dot always reads as the same color as
    the badge sitting next to it. Flux isn't uniform here -- some colors sit at
    700 and others at 800 -- which is why this is an explicit map rather than
    an interpolated "bg-{$color}-800". Keep it in sync with Flux's badge
    component if the vendor palette ever changes.

    Pass `pulse` to mark the dot as live -- it grows a second copy of itself
    underneath that expands and fades on a loop (see .status-dot-pulse in
    app.css). Used for the newest entry in the idea Activity timeline, so the
    current status reads apart from the ones it moved through.
--}}
@props([
    'color' => 'zinc',
    'size' => 'size-2',
    'pulse' => false,
])

@php
$dotClasses = match ($color) {
    default => 'bg-zinc-700 dark:bg-zinc-200',
    'red' => 'bg-red-700 dark:bg-red-200',
    'orange' => 'bg-orange-700 dark:bg-orange-200',
    'amber' => 'bg-amber-700 dark:bg-amber-200',
    'yellow' => 'bg-yellow-800 dark:bg-yellow-200',
    'lime' => 'bg-lime-800 dark:bg-lime-200',
    'green' => 'bg-green-800 dark:bg-green-200',
    'emerald' => 'bg-emerald-800 dark:bg-emerald-200',
    'teal' => 'bg-teal-800 dark:bg-teal-200',
    'cyan' => 'bg-cyan-800 dark:bg-cyan-200',
    'sky' => 'bg-sky-800 dark:bg-sky-200',
    'blue' => 'bg-blue-800 dark:bg-blue-200',
    'indigo' => 'bg-indigo-700 dark:bg-indigo-200',
    'violet' => 'bg-violet-700 dark:bg-violet-200',
    'purple' => 'bg-purple-700 dark:bg-purple-200',
    'fuchsia' => 'bg-fuchsia-700 dark:bg-fuchsia-200',
    'pink' => 'bg-pink-700 dark:bg-pink-200',
    'rose' => 'bg-rose-700 dark:bg-rose-200',
};
@endphp

@if ($pulse)
    <span {{ $attributes->class(['relative inline-flex shrink-0', $size]) }} data-test="status-dot" data-pulse>
        <span class="status-dot-pulse absolute inset-0 rounded-full {{ $dotClasses }}" aria-hidden="true"></span>
        <span class="relative block size-full rounded-full {{ $dotClasses }}"></span>
    </span>
@else
    <span {{ $attributes->class(['inline-block shrink-0 rounded-full', $size, $dotClasses]) }} data-test="status-dot"></span>
@endif
