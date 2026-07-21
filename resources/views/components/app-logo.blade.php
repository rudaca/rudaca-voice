@props([
    'sidebar' => false,
    'name' => 'Rudaca Voice',
])

@if($sidebar && isset($teamSwitcher))
    <div class="min-w-0 in-data-flux-sidebar-collapsed-desktop:w-10 px-2 in-data-flux-sidebar-collapsed-desktop:px-0">
        <a href="{{ $attributes->get('href', '/') }}" {{ $attributes->except('href')->class('flex min-w-0 items-center gap-2 in-data-flux-sidebar-collapsed-desktop:justify-center') }}>
            <span class="flex aspect-square size-8 shrink-0 items-center justify-center rounded-md bg-accent-content text-accent-foreground">
                <x-app-logo-icon class="size-5 fill-current text-white dark:text-black" />
            </span>
            <span class="min-w-0 truncate text-sm font-semibold text-zinc-800 in-data-flux-sidebar-collapsed-desktop:hidden dark:text-zinc-100">{{ $name }}</span>
        </a>

        <div class="mt-0.5 ps-10 in-data-flux-sidebar-collapsed-desktop:hidden">
            {{ $teamSwitcher }}
        </div>
    </div>
@elseif($sidebar)
    <flux:sidebar.brand :name="$name" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-md bg-accent-content text-accent-foreground">
            <x-app-logo-icon class="size-5 fill-current text-white dark:text-black" />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand :name="$name" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-md bg-accent-content text-accent-foreground">
            <x-app-logo-icon class="size-5 fill-current text-white dark:text-black" />
        </x-slot>
    </flux:brand>
@endif
