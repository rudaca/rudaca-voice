<?php

use App\Actions\ViewAs\StartViewAsSession;
use App\Enums\TeamRole;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component {
    public ?string $selectedRole = null;

    /**
     * Roles present in the current team, excluding the Super Admin and any
     * other Super Admins, grouped with their eligible users.
     *
     * @return Collection<int, array{role: TeamRole, users: Collection<int, User>}>
     */
    public function roleOptions(): Collection
    {
        $team = Auth::user()->currentTeam;

        if (! $team) {
            return collect();
        }

        return $team->memberships()
            ->with('user')
            ->where('user_id', '!=', Auth::id())
            ->whereHas('user', fn ($query) => $query->where('is_super_admin', false))
            ->get()
            ->groupBy(fn (Membership $membership) => $membership->role->value)
            ->map(fn (Collection $memberships, string $roleValue) => [
                'role' => TeamRole::from($roleValue),
                'users' => $memberships->pluck('user'),
            ])
            ->values();
    }

    /**
     * @return Collection<int, User>
     */
    public function usersForRole(string $roleValue): Collection
    {
        return $this->roleOptions()
            ->first(fn (array $option) => $option['role']->value === $roleValue)['users']
            ?? collect();
    }

    public function selectRole(string $roleValue): void
    {
        $users = $this->usersForRole($roleValue);

        if ($users->count() === 1) {
            $this->startViewAs($users->first()->id, $roleValue);

            return;
        }

        $this->selectedRole = $roleValue;
    }

    public function backToRoles(): void
    {
        $this->selectedRole = null;
    }

    public function startViewAs(int $userId, string $roleValue): void
    {
        $team = Auth::user()->currentTeam;
        $target = User::findOrFail($userId);
        $role = TeamRole::from($roleValue);

        app(StartViewAsSession::class)->handle(Auth::user(), $target, $team, $role);

        $this->redirect(route('dashboard', ['current_team' => $team->slug]));
    }
}; ?>

<div>
    @if (config('view-as.enabled') && auth()->user()->is_super_admin && auth()->user()->currentTeam)
        <flux:dropdown position="bottom" align="end" class="group">
            <flux:tooltip content="{{ __('View As') }}" position="bottom">
                <flux:button variant="outline" size="sm" data-test="view-as-trigger">
                    <flux:icon name="eye" variant="outline" class="size-4" />
                    <flux:icon
                        name="chevron-down"
                        variant="micro"
                        class="size-3 transition-transform duration-200 ease-out group-data-open:rotate-180"
                    />
                </flux:button>
            </flux:tooltip>

            <flux:menu
                x-data="{ selectedRole: @entangle('selectedRole') }"
                class="min-w-56 overflow-hidden opacity-0 scale-95 -translate-y-2 transition-discrete transition-all duration-200 ease-out open:translate-y-0 open:scale-100 open:opacity-100 starting:open:opacity-0 starting:open:scale-95 starting:open:-translate-y-2"
            >
                <div
                    class="flex w-[200%] transition-transform duration-300 ease-in-out"
                    :class="selectedRole ? '-translate-x-1/2' : 'translate-x-0'"
                >
                    <div class="w-1/2 shrink-0">
                        <flux:menu.heading>{{ __('View as role') }}</flux:menu.heading>

                        @forelse ($this->roleOptions() as $option)
                            <flux:menu.item
                                keep-open
                                wire:click="selectRole('{{ $option['role']->value }}')"
                                class="cursor-pointer"
                                data-test="view-as-role-option"
                            >
                                <div class="flex w-full items-center justify-between">
                                    <span>{{ $option['role']->label() }}</span>
                                    <flux:badge size="sm">{{ $option['users']->count() }}</flux:badge>
                                </div>
                            </flux:menu.item>
                        @empty
                            <div class="px-2 py-1.5 text-sm text-slate-500 dark:text-slate-600">
                                {{ __('No other members to view as in this organization.') }}
                            </div>
                        @endforelse
                    </div>

                    <div class="w-1/2 shrink-0">
                        @if ($selectedRole)
                            <flux:menu.item keep-open icon="chevron-left" wire:click="backToRoles" class="cursor-pointer" data-test="view-as-back">
                                {{ __('Back') }}
                            </flux:menu.item>

                            <flux:menu.separator />

                            <flux:menu.heading>{{ TeamRole::from($selectedRole)->label() }}</flux:menu.heading>

                            @foreach ($this->usersForRole($selectedRole) as $user)
                                <flux:menu.item
                                    wire:click="startViewAs({{ $user->id }}, '{{ $selectedRole }}')"
                                    class="cursor-pointer"
                                    data-test="view-as-user-option"
                                >
                                    {{ $user->name }}
                                </flux:menu.item>
                            @endforeach
                        @endif
                    </div>
                </div>
            </flux:menu>
        </flux:dropdown>
    @endif
</div>
