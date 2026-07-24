<?php

use App\Actions\Teams\CreateTeam;
use App\Models\Team;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('System Users')] class extends Component {
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'role')]
    public string $role = '';

    /**
     * @var array<int, string>
     */
    #[Url(as: 'status')]
    public array $status = [];

    /**
     * @var array<int, int>
     */
    #[Url(as: 'org')]
    public array $organization = [];

    // --- User form (Add/Edit modal) ---
    public ?int $userId = null;

    public string $name = '';

    public string $email = '';

    public ?string $password = null;

    public ?string $password_confirmation = null;

    public bool $isSuperAdmin = false;

    public bool $isActive = true;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingRole(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function updatingOrganization(): void
    {
        $this->resetPage();
    }

    /**
     * Active organizations selectable in the filter dropdown. Personal teams
     * are excluded — they're one-per-user and not meaningful to filter by.
     *
     * @return Collection<int, Team>
     */
    #[Computed]
    public function organizations(): Collection
    {
        return Team::query()
            ->where('is_personal', false)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * Whether any filter differs from its default, used to show/hide the "Clear" button.
     */
    #[Computed]
    public function hasActiveFilters(): bool
    {
        return $this->search !== ''
            || $this->role !== ''
            || $this->status !== []
            || $this->organization !== [];
    }

    /**
     * Reset every filter control back to its default.
     */
    public function clearFilters(): void
    {
        $this->reset(['search', 'role', 'status', 'organization']);
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, User>
     */
    #[Computed]
    public function users(): LengthAwarePaginator
    {
        $search = trim($this->search);
        $statusValues = collect($this->status)->map(fn ($value) => $value === 'active')->all();

        return User::query()
            ->when($search !== '', fn ($query) => $query
                ->where(fn ($query) => $query
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")))
            ->when($this->role !== '', fn ($query) => $query->where('is_super_admin', $this->role === 'super_admin'))
            ->when($this->status !== [], fn ($query) => $query->whereIn('is_active', $statusValues))
            ->when($this->organization !== [], fn ($query) => $query->whereHas('teams', fn ($query) => $query->whereIn('teams.id', $this->organization)))
            ->orderBy('name')
            ->paginate(15);
    }

    public function newUser(): void
    {
        $this->reset('userId', 'name', 'email', 'password', 'password_confirmation', 'isSuperAdmin');
        $this->isActive = true;
        $this->resetValidation();
        $this->dispatch('modal-show', name: 'user');
    }

    public function editUser(int $id): void
    {
        $user = User::findOrFail($id);

        $this->userId = $user->id;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->password = null;
        $this->password_confirmation = null;
        $this->isSuperAdmin = $user->is_super_admin;
        $this->isActive = $user->is_active;

        $this->resetValidation();
        $this->dispatch('modal-show', name: 'user');
    }

    public function saveUser(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->userId)],
            'password' => [$this->userId ? 'nullable' : 'required', 'string', Password::default(), 'confirmed'],
        ]);

        if ($this->userId) {
            $user = User::findOrFail($this->userId);
            $user->update([
                'name' => $validated['name'],
                'email' => $validated['email'],
            ]);

            if (filled($validated['password'])) {
                $user->password = $validated['password'];
            }

            // Editing yourself never changes your own super admin status or
            // active status, even if the checkboxes were somehow submitted —
            // the modal hides them for your own row so a super admin can't
            // accidentally lock themselves out of this screen.
            if ($user->id !== Auth::id()) {
                $user->is_super_admin = $this->isSuperAdmin;
                $user->is_active = $this->isActive;
            }

            $user->save();
        } else {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
            ]);

            $user->is_active = $this->isActive;
            $user->is_super_admin = $this->isSuperAdmin;
            $user->save();

            // Every user needs a personal team to log in against (Fortify's
            // LoginResponse redirects into the current/personal team) — the
            // normal registration flow creates one automatically, but this is
            // the only other place accounts are created from scratch.
            app(CreateTeam::class)->handle($user, __(":name's Team", ['name' => $user->name]), isPersonal: true);
        }

        unset($this->users);
        $this->dispatch('modal-close', name: 'user');
        Flux::toast(variant: 'success', text: __('User saved.'));
    }

    public function assignRole(int $id, bool $isSuperAdmin): void
    {
        if ($id === Auth::id()) {
            Flux::toast(variant: 'danger', text: __('You cannot change your own role.'));

            return;
        }

        $user = User::findOrFail($id);
        $user->is_super_admin = $isSuperAdmin;
        $user->save();

        unset($this->users);
        Flux::toast(variant: 'success', text: __('Role updated.'));
    }

    public function toggleActive(int $id): void
    {
        if ($id === Auth::id()) {
            Flux::toast(variant: 'danger', text: __('You cannot deactivate your own account.'));

            return;
        }

        $user = User::findOrFail($id);
        $user->is_active = ! $user->is_active;
        $user->save();

        unset($this->users);
    }
}; ?>

@push('breadcrumbs')
    <x-breadcrumbs :items="[
        ['label' => __('System Users'), 'href' => null],
    ]" />
@endpush

<section class="mx-auto w-full px-6 pb-7 lg:px-8">
    <div class="flex items-start justify-between gap-4">
        <div class="flex flex-col gap-1">
            <flux:heading size="xl">{{ __('System Users') }}</flux:heading>
            <flux:text class="text-slate-600 dark:text-slate-500">{{ __('Manage user accounts, roles, and access across the application.') }}</flux:text>
        </div>

        <flux:button wire:click="newUser" variant="primary" icon="plus" size="sm" data-test="new-user">{{ __('New user') }}</flux:button>
    </div>

    <x-sticky-toolbar class="mt-6 py-2.5">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex flex-wrap items-center gap-3">
                <div
                    class="relative inline-flex rounded-lg bg-zinc-100 p-0.5 dark:bg-zinc-800"
                    role="group"
                    aria-label="{{ __('Filter by role') }}"
                    data-role="{{ $role }}"
                    x-data="{
                        role: null,
                        indicator: { left: 0, width: 0 },
                        updateIndicator() {
                            let el = this.$refs['role-' + (this.role === '' ? 'all' : this.role)];
                            if (el) {
                                this.indicator = { left: el.offsetLeft, width: el.offsetWidth };
                            }
                        },
                    }"
                    x-init="role = $el.dataset.role; updateIndicator()"
                    x-effect="role; updateIndicator()"
                >
                    <div
                        class="absolute inset-y-0.5 rounded-md bg-white shadow-sm transition-all duration-200 ease-out dark:bg-zinc-700"
                        :style="`transform: translateX(${indicator.left}px); width: ${indicator.width}px`"
                    ></div>

                    @foreach (['' => __('All'), 'super_admin' => __('Super Admin'), 'user' => __('User')] as $value => $label)
                        <button
                            type="button"
                            x-ref="role-{{ $value === '' ? 'all' : $value }}"
                            x-on:click="role = '{{ $value }}'"
                            wire:click="$set('role', '{{ $value }}')"
                            @class([
                                'relative rounded-md px-3 py-1 text-sm font-medium transition-colors',
                                'text-slate-900 dark:text-white' => $role === $value,
                                'text-slate-600 hover:text-slate-900 dark:text-slate-500 dark:hover:text-slate-300' => $role !== $value,
                            ])
                            data-test="role-filter-{{ $value === '' ? 'all' : $value }}"
                        >
                            {{ $label }}
                        </button>
                    @endforeach
                </div>

                <flux:input
                    wire:model.live.debounce.300ms="search"
                    icon="magnifying-glass"
                    size="sm"
                    clearable
                    :placeholder="__('Search by name or email...')"
                    class="w-full sm:w-64 md:w-80"
                    data-test="user-search-input"
                />

                <flux:dropdown position="bottom" align="start">
                    <flux:button size="sm" icon:trailing="chevron-down" data-test="filter-status-trigger" @class([
                        'w-auto',
                        'border-gray-800! font-semibold! dark:border-gray-400!' => $status !== [],
                    ])>
                        {{ __('Status') }}
                        @if ($status !== [])
                            <flux:badge size="sm" color="zinc">{{ count($status) }}</flux:badge>
                        @endif
                    </flux:button>

                    <flux:menu class="w-48">
                        <flux:menu.item
                            keep-open
                            wire:click="$set('status', [])"
                            icon:trailing="{{ $status === [] ? 'check' : '' }}"
                            class="{{ $status === [] ? 'font-semibold' : '' }}"
                            data-test="filter-status-all"
                        >
                            {{ __('All Status') }}
                        </flux:menu.item>
                        <flux:menu.separator />

                        <flux:menu.checkbox.group wire:model.live="status">
                            <flux:menu.checkbox value="active" keep-open data-test="filter-status-active">{{ __('Active') }}</flux:menu.checkbox>
                            <flux:menu.checkbox value="inactive" keep-open data-test="filter-status-inactive">{{ __('Inactive') }}</flux:menu.checkbox>
                        </flux:menu.checkbox.group>
                    </flux:menu>
                </flux:dropdown>

                <flux:dropdown position="bottom" align="start">
                    <flux:button size="sm" icon:trailing="chevron-down" data-test="filter-organization-trigger" @class([
                        'w-auto',
                        'border-gray-800! font-semibold! dark:border-gray-400!' => $organization !== [],
                    ])>
                        {{ __('Organization') }}
                        @if ($organization !== [])
                            <flux:badge size="sm" color="zinc">{{ count($organization) }}</flux:badge>
                        @endif
                    </flux:button>

                    <flux:menu class="w-56">
                        <flux:menu.item
                            keep-open
                            wire:click="$set('organization', [])"
                            icon:trailing="{{ $organization === [] ? 'check' : '' }}"
                            class="{{ $organization === [] ? 'font-semibold' : '' }}"
                            data-test="filter-organization-all"
                        >
                            {{ __('All Organizations') }}
                        </flux:menu.item>
                        <flux:menu.separator />

                        <flux:menu.checkbox.group wire:model.live="organization">
                            @foreach ($this->organizations as $org)
                                <flux:menu.checkbox value="{{ $org->id }}" keep-open data-test="filter-organization-{{ $org->id }}">{{ $org->name }}</flux:menu.checkbox>
                            @endforeach
                        </flux:menu.checkbox.group>
                    </flux:menu>
                </flux:dropdown>
            </div>

            @if ($this->hasActiveFilters)
                <flux:button
                    wire:click="clearFilters"
                    variant="outline"
                    size="sm"
                    icon="x-mark"
                    class="border-red-500! text-red-500! hover:bg-red-50! dark:hover:bg-red-500/10!"
                    data-test="clear-filters"
                >
                    {{ __('Clear') }}
                </flux:button>
            @endif
        </div>
    </x-sticky-toolbar>

    <div class="mt-4">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('User') }}</flux:table.column>
                <flux:table.column>{{ __('Email') }}</flux:table.column>
                <flux:table.column>{{ __('Role') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column>{{ __('Actions') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($this->users as $user)
                    <flux:table.row :key="'user-'.$user->id" data-test="user-row">
                        <flux:table.cell>
                            <div class="flex items-center gap-3">
                                <flux:avatar :name="$user->name" size="lg" color="auto" color:seed="{{ $user->id }}" />
                                <div>
                                    <span class="font-bold text-slate-900 dark:text-slate-200">{{ $user->name }}</span>
                                    <flux:tooltip content="{{ __('Last Login') }}">
                                        <div class="mt-1 flex items-center gap-1 text-xs text-slate-500">
                                            <flux:icon.clock class="size-3.5" />
                                            {{ $user->last_login_at?->format('M d, Y h:i A') ?? __('Never') }}
                                        </div>
                                    </flux:tooltip>
                                </div>
                            </div>
                        </flux:table.cell>

                        <flux:table.cell class="text-slate-600 dark:text-slate-500">{{ $user->email }}</flux:table.cell>

                        <flux:table.cell>
                            @if ($user->id === auth()->id())
                                <flux:badge size="sm" :color="$user->is_super_admin ? 'pink' : 'zinc'">
                                    {{ $user->is_super_admin ? __('Super Admin') : __('User') }}
                                </flux:badge>
                            @else
                                <flux:dropdown position="bottom" align="start">
                                    <flux:button variant="outline" size="sm" icon:trailing="chevron-down" data-test="user-role-trigger">
                                        {{ $user->is_super_admin ? __('Super Admin') : __('User') }}
                                    </flux:button>
                                    <flux:menu>
                                        <flux:menu.item as="button" type="button" wire:click="assignRole({{ $user->id }}, true)" data-test="assign-role-super-admin">
                                            {{ __('Super Admin') }}
                                        </flux:menu.item>
                                        <flux:menu.item as="button" type="button" wire:click="assignRole({{ $user->id }}, false)" data-test="assign-role-user">
                                            {{ __('User') }}
                                        </flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell>
                            <flux:badge size="sm" :color="$user->is_active ? 'green' : 'zinc'">
                                {{ $user->is_active ? __('Active') : __('Inactive') }}
                            </flux:badge>
                        </flux:table.cell>

                        <flux:table.cell>
                            <div class="flex items-center justify-end gap-1.5">
                                @if ($user->id === auth()->id())
                                    <flux:badge size="sm" color="zinc">{{ __('You') }}</flux:badge>
                                @else
                                    <flux:button
                                        wire:click="toggleActive({{ $user->id }})"
                                        variant="outline"
                                        size="sm"
                                        class="px-2! py-1! text-xs!"
                                        data-test="toggle-user-active"
                                        :class="$user->is_active
                                            ? 'text-rose-700! border-rose-700! dark:text-rose-400! dark:border-rose-800!'
                                            : 'text-teal-700! border-teal-700! dark:text-teal-400! dark:border-teal-800!'"
                                    >
                                        {{ $user->is_active ? __('Deactivate') : __('Activate') }}
                                    </flux:button>
                                @endif
                                <flux:button
                                    wire:click="editUser({{ $user->id }})"
                                    variant="outline"
                                    size="sm"
                                    class="px-2! py-1! text-xs! text-slate-600! border-slate-300! dark:text-slate-400! dark:border-zinc-700!"
                                    data-test="edit-user"
                                >
                                    {{ __('Edit') }}
                                </flux:button>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5">
                            <div class="py-14 text-center" data-test="users-empty">
                                <flux:icon.user class="mx-auto size-8 text-slate-400 dark:text-slate-700" />
                                <flux:text class="mt-2 text-slate-600 dark:text-slate-500">{{ __('No users found.') }}</flux:text>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>

    <div class="mt-4">
        {{ $this->users->links() }}
    </div>

    {{-- Add/Edit user modal --}}
    <flux:modal name="user" class="w-full max-w-xl" :dismissible="false" data-test="user-modal">
        <form wire:submit="saveUser" class="space-y-5">
            <flux:heading size="lg">{{ $userId ? __('Edit user') : __('New user') }}</flux:heading>

            <flux:input wire:model="name" :label="__('Name')" required data-test="user-name-input" />
            <flux:input wire:model="email" :label="__('Email')" type="email" required data-test="user-email-input" />
            <flux:input
                wire:model="password"
                :label="__('Password')"
                type="password"
                :description="$userId ? __('Leave blank to keep the current password.') : null"
                :required="! $userId"
                autocomplete="new-password"
                viewable
                data-test="user-password-input"
            />
            <flux:input
                wire:model="password_confirmation"
                :label="__('Confirm password')"
                type="password"
                :required="! $userId"
                autocomplete="new-password"
                viewable
                data-test="user-password-confirmation-input"
            />

            @if ($userId !== auth()->id())
                <flux:checkbox
                    wire:model="isActive"
                    :label="__('Active')"
                    :description="__('Unchecking this locks the account out of logging in.')"
                    data-test="user-active-checkbox"
                />

                <flux:checkbox
                    wire:model="isSuperAdmin"
                    :label="__('Super Admin')"
                    :description="__('Grants full access to manage every organization and user in the application.')"
                    data-test="user-super-admin-checkbox"
                />
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="save-user">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
