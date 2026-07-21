@props([
    'name' => '',
    'index' => 0,
    'size' => 'size-9 text-sm',
])

@php
    /**
     * Soft colored tile classes for board icons, cycled by index.
     *
     * @var array<int, string>
     */
    $tiles = [
        'bg-indigo-50 text-indigo-600 dark:bg-indigo-500/15 dark:text-indigo-300',
        'bg-sky-50 text-sky-600 dark:bg-sky-500/15 dark:text-sky-300',
        'bg-violet-50 text-violet-600 dark:bg-violet-500/15 dark:text-violet-300',
        'bg-teal-50 text-teal-600 dark:bg-teal-500/15 dark:text-teal-300',
        'bg-amber-50 text-amber-600 dark:bg-amber-500/15 dark:text-amber-300',
        'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-300',
        'bg-pink-50 text-pink-600 dark:bg-pink-500/15 dark:text-pink-300',
        'bg-purple-50 text-purple-600 dark:bg-purple-500/15 dark:text-purple-300',
    ];
@endphp

<span {{ $attributes->class(['flex shrink-0 items-center justify-center rounded-lg font-semibold', $size, $tiles[$index % count($tiles)]]) }}>
    {{ strtoupper(mb_substr($name, 0, 1)) }}
</span>
