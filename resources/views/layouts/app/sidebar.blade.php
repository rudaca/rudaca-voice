<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <livewire:view-as-banner />

        @php
            $__currentTeam = auth()->user()?->currentTeam;
            $__currentRole = $__currentTeam ? auth()->user()->teamRole($__currentTeam) : null;
            $__canSubmitIdea = $__currentRole?->isAtLeast(\App\Enums\TeamRole::Employee) ?? false;
            $__canReview = $__currentRole?->isAtLeast(\App\Enums\TeamRole::Manager) ?? false;
            $__canManageBoards = $__currentRole?->isAtLeast(\App\Enums\TeamRole::Admin) ?? false;

            $__reviewQueueCount = $__canReview
                ? $__currentTeam->ideas()->whereIn('status', ['new', 'under_review'])->count()
                : 0;

            $__ideasCountScope = fn ($query) => $query->visibleTo($__currentRole, auth()->id());

            $__boardGroups = $__currentTeam
                ? $__currentTeam->boardGroups()
                    ->where('is_active', true)
                    ->orderBy('sort_order')
                    ->orderBy('name')
                    ->with(['boards' => fn ($query) => $query->where('is_active', true)->withCount(['ideas' => $__ideasCountScope])->orderBy('sort_order')->orderBy('name')])
                    ->get()
                : collect();

            $__ungroupedBoards = $__currentTeam
                ? $__currentTeam->boards()
                    ->where('is_active', true)
                    ->whereNull('board_group_id')
                    ->withCount(['ideas' => $__ideasCountScope])
                    ->orderBy('sort_order')
                    ->orderBy('name')
                    ->get()
                : collect();
        @endphp

        <flux:sidebar sticky collapsible class="overflow-x-hidden border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate class="in-data-flux-sidebar-collapsed-desktop:hidden" />
                <flux:sidebar.collapse class="in-data-flux-sidebar-on-desktop:not-in-data-flux-sidebar-collapsed-desktop:-mr-2" />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                <div class="grid">
                    <flux:sidebar.item icon="layout-grid" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                        {{ __('Dashboard') }}
                    </flux:sidebar.item>

                    <flux:sidebar.item
                        icon="list-bullet"
                        :href="route('ideas.index')"
                        :current="request()->routeIs('ideas.index') && ! request()->filled('board') && ! request()->filled('group')"
                        wire:navigate
                    >
                        {{ __('All Ideas') }}
                    </flux:sidebar.item>

                    @if ($__canSubmitIdea)
                        <flux:sidebar.item icon="plus" :href="route('ideas.create')" :current="request()->routeIs('ideas.create')" wire:navigate>
                            {{ __('Submit Idea') }}
                        </flux:sidebar.item>
                    @endif
                </div>
            </flux:sidebar.nav>

            @if ($__canReview || $__canManageBoards)
                <flux:separator variant="subtle" class="sidebar-divider" />

                <flux:sidebar.nav>
                    <div class="in-data-flux-sidebar-collapsed-desktop:hidden px-4 pt-1 pb-1 text-xs font-semibold tracking-wide text-zinc-400 uppercase dark:text-zinc-500">
                        {{ __('Administration') }}
                    </div>

                    <div class="grid">
                        @if ($__canReview)
                            <flux:sidebar.item
                                icon="clipboard-document-check"
                                :href="route('ideas.review')"
                                :current="request()->routeIs('ideas.review')"
                                :badge="$__reviewQueueCount > 0 ? (string) $__reviewQueueCount : null"
                                badge:color="amber"
                                wire:navigate
                            >
                                {{ __('Review Queue') }}
                            </flux:sidebar.item>
                        @endif

                        @if ($__canManageBoards)
                            <flux:sidebar.item icon="cog" :href="route('ideas.settings')" :current="request()->routeIs('ideas.settings')" wire:navigate>
                                {{ __('Organization') }}
                            </flux:sidebar.item>

                            <flux:sidebar.item
                                icon="chat-bubble-left-right"
                                :href="route('ideas.moderate-comments')"
                                :current="request()->routeIs('ideas.moderate-comments')"
                                wire:navigate
                            >
                                {{ __('Moderate Comments') }}
                            </flux:sidebar.item>
                        @endif
                    </div>
                </flux:sidebar.nav>
            @endif

            @if ($__boardGroups->isNotEmpty() || $__ungroupedBoards->isNotEmpty())
                <flux:separator variant="subtle" class="sidebar-divider" />

                <div class="in-data-flux-sidebar-collapsed-desktop:hidden mt-2 px-3">
                    <div class="flex items-center gap-1.5 px-1 py-2 text-xs font-semibold tracking-wide text-zinc-400 uppercase dark:text-zinc-500">
                        <flux:icon.chalkboard class="size-3.5" />
                        {{ __('Boards') }}
                    </div>

                    <x-boards-nav-list :groups="$__boardGroups" :ungrouped="$__ungroupedBoards" />
                </div>

                <div class="hidden in-data-flux-sidebar-collapsed-desktop:flex justify-center px-3">
                    <div class="group/tooltip relative">
                        <flux:dropdown position="right" align="start" data-test="sidebar-boards-dropdown">
                            <button
                                type="button"
                                class="flex size-10 items-center justify-center rounded-lg text-zinc-500 transition hover:bg-zinc-800/5 hover:text-zinc-800 dark:text-white/80 dark:hover:bg-white/[7%] dark:hover:text-white"
                                aria-label="{{ __('Boards') }}"
                                data-test="sidebar-boards-trigger"
                            >
                                <flux:icon.ellipsis-horizontal class="size-5" />
                            </button>

                            <flux:menu class="max-h-[70vh] w-64 overflow-y-auto">
                                <flux:menu.heading>{{ __('Boards') }}</flux:menu.heading>

                                <div class="mt-1 p-1">
                                    <x-boards-nav-list :groups="$__boardGroups" :ungrouped="$__ungroupedBoards" />
                                </div>
                            </flux:menu>
                        </flux:dropdown>

                        <div class="pointer-events-none absolute start-full top-1/2 z-50 ms-2 -translate-y-1/2 scale-95 rounded-md bg-zinc-800 px-2 py-1 text-xs font-medium whitespace-nowrap text-white opacity-0 shadow-sm transition delay-300 duration-150 group-hover/tooltip:scale-100 group-hover/tooltip:opacity-100 dark:bg-zinc-700 dark:border dark:border-white/10">
                            {{ __('Boards') }}
                        </div>
                    </div>
                </div>
            @endif

            <flux:spacer />

            <flux:separator variant="subtle" class="sidebar-divider" />

            <x-desktop-user-menu class="hidden lg:block" :subtitle="$__currentRole?->label()" />
        </flux:sidebar>

        <!-- Header -->
        <flux:header sticky class="z-30 gap-3 border-b border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <livewire:team-switcher :compact="true" />

            <div class="min-w-0 flex-1 sm:w-64 sm:flex-none md:w-96 lg:w-[28rem]">
                <livewire:global-search />
            </div>

            <flux:spacer />

            @if ($__canSubmitIdea)
                <flux:button :href="route('ideas.create')" wire:navigate variant="primary" icon="plus" size="sm" data-test="header-new-idea-button">
                    <span class="hidden sm:inline">{{ __('New Idea') }}</span>
                </flux:button>
            @endif

            <livewire:view-as-switcher />

            <flux:dropdown position="top" align="end" class="lg:hidden">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
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
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('Settings') }}
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

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
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        <livewire:create-team-modal />

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
