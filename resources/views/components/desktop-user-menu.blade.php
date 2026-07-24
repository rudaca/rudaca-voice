@props(['showTeam' => true, 'subtitle' => null, 'role' => null])

@php
    $menuSubtitle = $role ? null : ($subtitle ?? ($showTeam ? auth()->user()->currentTeam?->name : null));
@endphp

<flux:dropdown position="bottom" align="start">
    <button type="button" class="group flex w-full items-center rounded-lg p-1 hover:bg-zinc-800/5 dark:hover:bg-white/10" data-test="sidebar-menu-button">
        <flux:avatar :initials="auth()->user()->initials()" size="sm" color="auto" color:seed="{{ auth()->id() }}" />
        <div class="in-data-flux-sidebar-collapsed-desktop:hidden mx-2 grid flex-1 text-start text-sm leading-tight">
            <span class="truncate font-medium text-slate-600 group-hover:text-slate-900 dark:text-white/80 dark:group-hover:text-white">{{ auth()->user()->name }}</span>
            @if($role)
                <flux:badge size="sm" :color="$role->badgeColor()" class="mt-0.5 w-fit">{{ $role->label() }}</flux:badge>
            @elseif($menuSubtitle)
                <span class="truncate text-xs text-slate-500 dark:text-slate-600">{{ $menuSubtitle }}</span>
            @endif
        </div>
        <flux:icon name="chevrons-up-down" variant="outline" class="in-data-flux-sidebar-collapsed-desktop:hidden ms-auto size-4.5 text-slate-500 group-hover:text-slate-900 dark:text-white/80 dark:group-hover:text-white" />
    </button>

    <flux:menu>
        <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
            <flux:avatar
                :name="auth()->user()->name"
                :initials="auth()->user()->initials()"
            />
            <div class="grid flex-1 text-start text-sm leading-tight">
                <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
            </div>
        </div>
        <flux:menu.separator />
        <flux:menu.radio.group>
            <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                {{ __('Settings') }}
            </flux:menu.item>
            <form method="POST" action="{{ route('logout') }}" class="w-full">
                @csrf
                <flux:menu.item
                    as="button"
                    type="submit"
                    icon="arrow-right-start-on-rectangle"
                    class="w-full cursor-pointer"
                    data-test="logout-button"
                >
                    {{ __('Log out') }}
                </flux:menu.item>
            </form>
        </flux:menu.radio.group>
    </flux:menu>
</flux:dropdown>
