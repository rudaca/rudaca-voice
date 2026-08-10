@props(['isSuperAdmin' => false, 'isSystemOwner' => false])

@php
    $label = match (true) {
        $isSuperAdmin => __('Super Admin'),
        $isSystemOwner => __('System Owner'),
        default => null,
    };

    $colorClasses = $isSuperAdmin
        ? 'bg-red-700! text-white! dark:bg-red-600! dark:text-white!'
        : 'bg-teal-800! text-white! dark:bg-teal-600! dark:text-white!';
@endphp

@if ($label)
    <flux:badge size="sm" {{ $attributes->class([$colorClasses]) }}>{{ $label }}</flux:badge>
@endif
