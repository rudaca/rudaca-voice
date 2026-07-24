<?php

use App\Actions\Teams\CreateTeam;
use App\Models\User;
use Flux\Flux;
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

    // --- User form (Add/Edit modal) ---
    public ?int $userId = null;

    public string $name = '';

    public string $email = '';

    public ?string $password = null;

    public ?string $password_confirmation = null;

    public bool $isSuperAdmin = false;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingRole(): void
    {
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, User>
     */
    #[Computed]
    public function users(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return User::query()
            ->when($search !== '', fn ($query) => $query
                ->where(fn ($query) => $query
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")))
            ->when($this->role !== '', fn ($query) => $query->where('is_super_admin', $this->role === 'super_admin'))
            ->orderBy('name')
            ->paginate(15);
    }

    public function newUser(): void
    {
        $this->reset('userId', 'name', 'email', 'password', 'password_confirmation', 'isSuperAdmin');
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

            // Editing yourself never changes your own super admin status, even
            // if the checkbox were somehow submitted — the modal hides it for
            // your own row so a super admin can't accidentally lock themselves
            // out of this screen.
            if ($user->id !== Auth::id()) {
                $user->is_super_admin = $this->isSuperAdmin;
            }

            $user->save();
        } else {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
            ]);

            $user->is_active = true;
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
        </div>
    </x-sticky-toolbar>

    <div class="mt-4 overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
        <table class="w-full text-sm">
            <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                <tr class="text-xs font-semibold tracking-wide text-slate-600 uppercase dark:text-slate-500">
                    <th class="px-4 py-2.5 text-start">{{ __('User') }}</th>
                    <th class="px-4 py-2.5 text-start">{{ __('Email') }}</th>
                    <th class="px-4 py-2.5 text-start">{{ __('Role') }}</th>
                    <th class="px-4 py-2.5 text-start">{{ __('Status') }}</th>
                    <th class="px-4 py-2.5 text-end">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($this->users as $user)
                    <tr wire:key="user-{{ $user->id }}" data-test="user-row">
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-3">
                                <flux:avatar :name="$user->name" size="xs" color="auto" color:seed="{{ $user->id }}" />
                                <span class="font-bold text-slate-900 dark:text-slate-200">{{ $user->name }}</span>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-slate-600 dark:text-slate-500">{{ $user->email }}</td>
                        <td class="px-4 py-3">
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
                        </td>
                        <td class="px-4 py-3">
                            <flux:badge size="sm" :color="$user->is_active ? 'green' : 'zinc'">
                                {{ $user->is_active ? __('Active') : __('Inactive') }}
                            </flux:badge>
                        </td>
                        <td class="px-4 py-3 text-end">
                            <div class="flex items-center justify-end gap-1">
                                @if ($user->id === auth()->id())
                                    <flux:badge size="sm" color="zinc">{{ __('You') }}</flux:badge>
                                @else
                                    <flux:button
                                        wire:click="toggleActive({{ $user->id }})"
                                        variant="outline"
                                        size="sm"
                                        data-test="toggle-user-active"
                                        :class="$user->is_active
                                            ? 'text-rose-700! border-rose-700! dark:text-rose-400! dark:border-rose-800!'
                                            : 'text-teal-700! border-teal-700! dark:text-teal-400! dark:border-teal-800!'"
                                    >
                                        {{ $user->is_active ? __('Deactivate') : __('Activate') }}
                                    </flux:button>
                                @endif
                                <flux:button wire:click="editUser({{ $user->id }})" variant="ghost" size="sm" icon="pencil" data-test="edit-user" />
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-10 text-center">
                            <flux:icon.user class="mx-auto size-8 text-slate-400 dark:text-slate-700" />
                            <flux:text class="mt-2 text-slate-600 dark:text-slate-500">{{ __('No users found.') }}</flux:text>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
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
