{{--
    Vote count for the vote button/badge. Stays a plain number until it
    reaches four digits, then abbreviates so a hot idea's score doesn't
    outgrow its fixed-width container (1,000 -> "1k", 1,200 -> "1.2k").
    The exact count is always available on hover via the native title
    tooltip, since abbreviating hides the precision and this sits inside
    elements (vote buttons) that already carry their own Flux tooltip.
--}}
@props([
    'count',
])

<span
    {{ $attributes->class('tabular-nums') }}
    title="{{ \Illuminate\Support\Number::format($count) }} voted"
    data-test="vote-count-value"
>{{ \Illuminate\Support\Str::lower(\Illuminate\Support\Number::abbreviate($count, maxPrecision: 1)) }}</span>
