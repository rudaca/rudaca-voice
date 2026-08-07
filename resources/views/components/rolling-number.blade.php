{{--
    A number rendered as an odometer: each digit is a vertical strip of faces
    that sits at the offset for its digit, and every freshly-rendered strip
    rolls up through three faces before settling on it.

    The roll is triggered by the `wire:key` on each digit column carrying the
    digit itself. When a Livewire re-render changes a digit, Livewire replaces
    that column instead of morphing it in place, so the strip mounts fresh and
    its CSS animation plays -- the digit visibly spins before the new value is
    readable. Digits that didn't change keep their element and stay still, the
    way a real odometer behaves.

    `name` only needs to be unique among the rolling numbers on the same page,
    since it namespaces those wire:key values.

    See `.rolling-number-strip` in resources/css/app.css for the face maths.
--}}
@props([
    'name',
    'value' => 0,
])

@php
    $digits = str_split((string) $value);

    /**
     * The strip's faces. The leading 7-8-9 are the run-up the animation rolls
     * through, which is why the face for digit N sits at index N + 3.
     *
     * @var array<int, int>
     */
    $faces = [7, 8, 9, 0, 1, 2, 3, 4, 5, 6, 7, 8, 9];
@endphp

<span {{ $attributes->class('inline-flex tabular-nums') }} data-test="rolling-number">
    {{-- The digit columns are decorative; screen readers get the plain number. --}}
    <span class="sr-only" wire:key="rolling-{{ $name }}-label">{{ $value }}</span>

    @foreach ($digits as $index => $digit)
        <span
            class="relative block h-[1em] w-[0.62em] overflow-hidden"
            wire:key="rolling-{{ $name }}-{{ $index }}-{{ $digit }}"
            aria-hidden="true"
        >
            <span class="rolling-number-strip absolute inset-x-0 top-0 flex flex-col" style="--rolling-digit: {{ (int) $digit }}">
                @foreach ($faces as $face)
                    <span class="block h-[1em] text-center leading-[1em]">{{ $face }}</span>
                @endforeach
            </span>
        </span>
    @endforeach
</span>
