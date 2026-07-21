@props([
    'name' => '',
    'index' => 0,
    'size' => 'size-9 text-sm',
])

<span {{ $attributes->class(['flex shrink-0 items-center justify-center rounded-lg bg-indigo-50 font-semibold text-indigo-600 dark:bg-indigo-500/15 dark:text-indigo-300', $size]) }}>
    {{ strtoupper(mb_substr($name, 0, 1)) }}
</span>
