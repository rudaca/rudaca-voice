<?php

use App\Actions\IdentityProviders\DisableTeamIdentityProvider;
use App\Actions\IdentityProviders\DisconnectTeamIdentityProvider;
use App\Actions\IdentityProviders\EnableTeamIdentityProvider;
use App\Actions\IdentityProviders\SaveTeamIdentityProvider;
use App\Actions\IdentityProviders\UnlinkUserIdentityAccount;
use App\Concerns\IdentityProviderValidationRules;
use App\Enums\IdentityProvider;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamIdentityProvider;
use App\Models\UserIdentityAccount;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    use IdentityProviderValidationRules;

    public Team $team;

    public bool $enabled = false;

    public string $tenantId = '';

    public string $clientId = '';

    /**
     * Write-only — the real secret never hydrates into a public property.
     * Left blank on load and on every re-render; only a non-blank value here
     * means "replace the stored secret".
     */
    public string $newSecretInput = '';

    public bool $hasExistingSecret = false;

    /**
     * Whether the masked secret placeholder has been swapped for a real input.
     */
    public bool $replacingSecret = false;

    public bool $autoProvisionUsers = false;

    public string $defaultRole = '';

    /**
     * @var array<int, string>
     */
    public array $allowedDomains = [];

    /**
     * Comma-separated editable form of $allowedDomains.
     */
    public string $allowedDomainsInput = '';

    public bool $enforceSso = false;

    // --- Linked identities (unlink confirmation) ---
    public ?int $pendingUnlinkId = null;

    public string $pendingUnlinkUserName = '';

    /**
     * Whether the last unlink attempt was blocked because it was the user's
     * only linked identity — the confirmation modal escalates to a starker
     * warning and offers to retry with $force.
     */
    public bool $unlinkNeedsForce = false;

    public function mount(Team $team): void
    {
        Gate::authorize('viewAny', [TeamIdentityProvider::class, $team]);

        $this->team = $team;

        $this->hydrateFromRecord();
    }

    /**
     * @return Collection<int, UserIdentityAccount>
     */
    #[Computed]
    public function identityLinks(): Collection
    {
        return $this->team->identityAccounts()
            ->provider(IdentityProvider::Microsoft)
            ->with('user')
            ->latest('last_login_at')
            ->get();
    }

    public function confirmUnlink(int $id): void
    {
        $identityAccount = UserIdentityAccount::findOrFail($id);

        Gate::authorize('delete', $identityAccount);

        $this->pendingUnlinkId = $id;
        $this->pendingUnlinkUserName = $identityAccount->user->name;
        $this->unlinkNeedsForce = false;

        $this->dispatch('modal-show', name: 'confirm-unlink-identity');
    }

    public function unlink(bool $force = false): void
    {
        $identityAccount = UserIdentityAccount::findOrFail($this->pendingUnlinkId);

        Gate::authorize('delete', $identityAccount);

        try {
            app(UnlinkUserIdentityAccount::class)->handle($identityAccount, Auth::user(), $force);
        } catch (ValidationException) {
            $this->unlinkNeedsForce = true;

            return;
        }

        unset($this->identityLinks);
        $this->dispatch('modal-close', name: 'confirm-unlink-identity');
        Flux::toast(variant: 'success', text: __('Identity unlinked.'));
    }

    #[Computed]
    public function identityProviderRecord(): ?TeamIdentityProvider
    {
        return $this->team->identityProviderFor(IdentityProvider::Microsoft);
    }

    #[Computed]
    public function redirectUrl(): string
    {
        return IdentityProvider::Microsoft->redirectUrl();
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    #[Computed]
    public function availableRoles(): array
    {
        return TeamRole::assignable();
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'tenantId' => __('tenant ID'),
            'clientId' => __('client ID'),
            'newSecretInput' => __('client secret'),
            'defaultRole' => __('default role'),
            'allowedDomains' => __('allowed domains'),
            'allowedDomains.*' => __('domain'),
        ];
    }

    public function save(): void
    {
        $existing = $this->identityProviderRecord;

        if ($existing) {
            Gate::authorize('update', $existing);
        } else {
            Gate::authorize('create', [TeamIdentityProvider::class, $this->team]);
        }

        $this->allowedDomains = $this->parseAllowedDomains();

        if ($this->hasDuplicateDomains($this->allowedDomains)) {
            $this->addError('allowedDomainsInput', __('Allowed domains must be unique.'));

            return;
        }

        $requireSecret = $this->enabled && ! $this->hasExistingSecret;

        $validated = $this->validate($this->microsoftIdentityProviderRules($this->enabled, $requireSecret));

        $wasEnabled = $existing?->enabled ?? false;

        try {
            $identityProvider = app(SaveTeamIdentityProvider::class)->handle(
                $this->team,
                Auth::user(),
                IdentityProvider::Microsoft,
                [
                    'tenant_id' => $validated['tenantId'] !== '' ? $validated['tenantId'] : null,
                    'client_id' => $validated['clientId'] !== '' ? $validated['clientId'] : null,
                    'client_secret' => $validated['newSecretInput'] !== '' ? $validated['newSecretInput'] : null,
                    'enforce_sso' => $validated['enforceSso'],
                    'auto_provision_users' => $validated['autoProvisionUsers'],
                    'default_role' => $validated['defaultRole'] !== '' ? $validated['defaultRole'] : null,
                    'allowed_domains' => $validated['allowedDomains'],
                ],
            );
        } catch (ValidationException $e) {
            $this->forwardActionErrors($e);

            return;
        }

        try {
            if ($this->enabled && ! $wasEnabled) {
                app(EnableTeamIdentityProvider::class)->handle($identityProvider, Auth::user());
            } elseif (! $this->enabled && $wasEnabled) {
                app(DisableTeamIdentityProvider::class)->handle($identityProvider, Auth::user());
            }
        } catch (ValidationException $e) {
            $this->forwardActionErrors($e);

            return;
        }

        unset($this->identityProviderRecord);
        $this->hydrateFromRecord();

        Flux::toast(variant: 'success', text: __('Authentication settings saved.'));
    }

    public function disable(): void
    {
        $existing = $this->identityProviderRecord;

        abort_if(! $existing, 404);

        Gate::authorize('update', $existing);

        app(DisableTeamIdentityProvider::class)->handle($existing, Auth::user());

        unset($this->identityProviderRecord);
        $this->hydrateFromRecord();

        Flux::toast(variant: 'success', text: __('Microsoft sign-in disabled.'));
    }

    public function disconnect(): void
    {
        $existing = $this->identityProviderRecord;

        abort_if(! $existing, 404);

        Gate::authorize('delete', $existing);

        app(DisconnectTeamIdentityProvider::class)->handle($existing, Auth::user());

        unset($this->identityProviderRecord);
        $this->hydrateFromRecord();

        $this->dispatch('modal-close', name: 'disconnect-microsoft');
        Flux::toast(variant: 'success', text: __('Microsoft sign-in disconnected.'));
    }

    /**
     * Reset every field from the current (possibly just-saved) database state.
     */
    private function hydrateFromRecord(): void
    {
        $identityProvider = $this->identityProviderRecord;

        $this->enabled = $identityProvider?->enabled ?? false;
        $this->tenantId = $identityProvider?->tenant_id ?? '';
        $this->clientId = $identityProvider?->client_id ?? '';
        $this->hasExistingSecret = $identityProvider?->hasClientSecret() ?? false;
        $this->newSecretInput = '';
        $this->replacingSecret = false;
        $this->autoProvisionUsers = $identityProvider?->auto_provision_users ?? false;
        $this->defaultRole = $identityProvider?->default_role?->value ?? '';
        $this->allowedDomains = $identityProvider?->allowed_domains ?? [];
        $this->allowedDomainsInput = implode(', ', $this->allowedDomains);
        $this->enforceSso = $identityProvider?->enforce_sso ?? false;
    }

    /**
     * @return array<int, string>
     */
    private function parseAllowedDomains(): array
    {
        return collect(explode(',', $this->allowedDomainsInput))
            ->map(fn (string $domain) => strtolower(trim($domain)))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Re-key a ValidationException raised inside the action layer (which
     * speaks its own snake_case attribute names) onto this component's actual
     * property names, so the error renders next to the right field.
     */
    private function forwardActionErrors(ValidationException $e): void
    {
        $keyMap = [
            'client_secret' => 'newSecretInput',
            'default_role' => 'defaultRole',
            'enabled' => 'enabled',
        ];

        foreach ($e->errors() as $key => $messages) {
            $this->addError($keyMap[$key] ?? $key, $messages[0]);
        }
    }

    public function render()
    {
        return $this->view();
    }
}; ?>

<div class="space-y-8" data-test="authentication-settings">
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        {{-- Microsoft 365 Configuration --}}
        <div class="mt-3 rounded-lg border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-center justify-between gap-4">
                <div class="flex items-center gap-2">
                    <flux:icon.microsoft class="size-6" />
                    <div>
                        <flux:heading size="lg" class="font-bold">{{ __('Microsoft 365 Configuration') }}</flux:heading>
                        <flux:subheading>{{ __('Microsoft Entra ID (Microsoft 365)') }}</flux:subheading>
                    </div>
                </div>

                @if ($this->identityProviderRecord?->enabled)
                    <flux:badge color="green" size="sm" data-test="microsoft-status-connected">{{ __('Enabled') }}</flux:badge>
                @elseif ($this->identityProviderRecord)
                    <flux:badge color="zinc" size="sm" data-test="microsoft-status-disabled">{{ __('Disabled') }}</flux:badge>
                @else
                    <flux:badge color="zinc" size="sm" data-test="microsoft-status-unconfigured">{{ __('Not configured') }}</flux:badge>
                @endif
            </div>

            <form wire:submit="save" class="mt-6 space-y-8">
                <flux:switch
                    wire:model="enabled"
                    :label="__('Enable Microsoft sign-in')"
                    :description="__('Members will be able to sign in with their Microsoft account once tenant ID, client ID, and a client secret are saved.')"
                    data-test="microsoft-enabled-toggle"
                />
                <flux:separator />

                <div class="space-y-8">
                    <div class="space-y-6">
                        <flux:heading size="sm">{{ __('App registration') }}</flux:heading>

                        <flux:input
                            wire:model="tenantId"
                            :label="__('Tenant ID')"
                            :description="__('Your Entra ID directory (tenant) GUID, or common / organizations / consumers.')"
                            :required="$enabled"
                            data-test="tenant-id-input"
                        />

                        <flux:input
                            wire:model="clientId"
                            :label="__('Application (client) ID')"
                            :required="$enabled"
                            data-test="client-id-input"
                        />

                        @if ($hasExistingSecret && ! $replacingSecret)
                            <div class="space-y-1">
                                <flux:input value="••••••••••••••••" readonly :label="__('Client secret')" data-test="client-secret-masked" />
                                <flux:button variant="ghost" size="sm" wire:click="$set('replacingSecret', true)" data-test="replace-secret-button">
                                    {{ __('Replace secret') }}
                                </flux:button>
                            </div>
                        @else
                            <flux:input
                                type="password"
                                wire:model="newSecretInput"
                                :label="__('Client secret')"
                                :description="$hasExistingSecret ? __('Leave blank to keep the current secret.') : __('Required to enable Microsoft sign-in.')"
                                :required="$enabled && ! $hasExistingSecret"
                                data-test="client-secret-input"
                            />
                        @endif

                        <flux:input :value="$this->redirectUrl" readonly :label="__('Redirect URL')" :description="__('Register this URL as the redirect URI in your Microsoft app registration.')" data-test="redirect-url-input" />
                    </div>

                    <div class="space-y-6">
                        <flux:heading size="sm">{{ __('Provisioning & access') }}</flux:heading>

                        <flux:switch
                            wire:model.live="autoProvisionUsers"
                            :label="__('Automatically create users on first sign-in')"
                            :description="__('New Microsoft accounts that sign in for the first time are added to this organization automatically.')"
                            data-test="auto-provision-toggle"
                        />

                        @if ($autoProvisionUsers)
                            <flux:select
                                wire:model="defaultRole"
                                :label="__('Default role for provisioned users')"
                                :placeholder="__('Select a role')"
                                data-test="default-role-select"
                            >
                                @foreach ($this->availableRoles as $role)
                                    <flux:select.option value="{{ $role['value'] }}">{{ $role['label'] }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        @endif

                        <flux:input
                            wire:model="allowedDomainsInput"
                            :label="__('Allowed email domains')"
                            :description="__('Comma-separated list, e.g. example.com, example.org. Leave blank to allow any domain.')"
                            data-test="allowed-domains-input"
                        />

                        <flux:switch
                            wire:model="enforceSso"
                            :label="__('Require Microsoft sign-in')"
                            :description="__('Stores the preference only — password sign-in is not blocked yet.')"
                            data-test="enforce-sso-toggle"
                        />
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2 border-t border-zinc-200 pt-6 dark:border-zinc-700">
                    @if ($this->identityProviderRecord)
                        <flux:button
                            variant="ghost"
                            type="button"
                            wire:click="disable"
                            :disabled="! $this->identityProviderRecord->enabled"
                            data-test="disable-provider-button"
                        >
                            {{ __('Disable') }}
                        </flux:button>

                        <flux:modal.trigger name="disconnect-microsoft">
                            <flux:button variant="danger" type="button" data-test="disconnect-provider-button">
                                {{ __('Disconnect') }}
                            </flux:button>
                        </flux:modal.trigger>
                    @endif

                    <flux:button variant="primary" type="submit" data-test="save-authentication-button">
                        {{ __('Save') }}
                    </flux:button>
                </div>
            </form>
        </div>

        {{-- Linked accounts --}}
        <div class="mt-3 rounded-lg border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" data-test="identity-links">
            <div class="flex items-center gap-2">
                <flux:icon.cable class="size-6" />
                <div>
                    <flux:heading size="lg" class="font-bold">{{ __('Linked accounts') }}</flux:heading>
                    <flux:subheading>{{ __('Microsoft accounts linked to members of this organization.') }}</flux:subheading>
                </div>
            </div>

            <div class="mt-5 overflow-x-auto">
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('User') }}</flux:table.column>
                        <flux:table.column>{{ __('Tenant ID') }}</flux:table.column>
                        <flux:table.column>{{ __('Email at link time') }}</flux:table.column>
                        <flux:table.column>{{ __('Last login') }}</flux:table.column>
                        <flux:table.column>{{ __('Linked') }}</flux:table.column>
                        <flux:table.column>{{ __('Actions') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @forelse ($this->identityLinks as $link)
                            <flux:table.row :key="'identity-link-'.$link->id" data-test="identity-link-row">
                                <flux:table.cell>
                                    <div>
                                        <span class="font-bold text-slate-900 dark:text-slate-200">{{ $link->user->name }}</span>
                                        <flux:text class="text-xs text-slate-600 dark:text-slate-500">{{ $link->user->email }}</flux:text>
                                    </div>
                                </flux:table.cell>
                                <flux:table.cell class="font-mono text-xs text-slate-600 dark:text-slate-500">{{ Str::limit($link->provider_tenant_id, 8, '…') }}</flux:table.cell>
                                <flux:table.cell class="text-slate-600 dark:text-slate-500">{{ $link->email_at_link_time }}</flux:table.cell>
                                <flux:table.cell class="text-slate-600 dark:text-slate-500">{{ $link->last_login_at?->forUser()->format('M d, Y h:i A') ?? __('Never') }}</flux:table.cell>
                                <flux:table.cell class="text-slate-600 dark:text-slate-500">{{ $link->created_at?->forUser()->format('M d, Y') }}</flux:table.cell>
                                <flux:table.cell>
                                    <flux:button
                                        wire:click="confirmUnlink({{ $link->id }})"
                                        variant="outline"
                                        size="sm"
                                        class="px-2! py-1! text-xs! text-rose-700! border-rose-700! dark:text-rose-400! dark:border-rose-800!"
                                        data-test="unlink-identity-button"
                                    >
                                        {{ __('Unlink') }}
                                    </flux:button>
                                </flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="6">
                                    <div class="py-10 text-center" data-test="identity-links-empty">
                                        <flux:text class="text-slate-600 dark:text-slate-500">{{ __('No Microsoft accounts have been linked yet.') }}</flux:text>
                                    </div>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </div>
        </div>
    </div>

    <flux:modal name="confirm-unlink-identity" :dismissible="false" class="max-w-lg" data-test="confirm-unlink-modal">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Unlink Microsoft account?') }}</flux:heading>
                @if ($unlinkNeedsForce)
                    <flux:subheading>
                        {{ __('This is the only sign-in method linked to :name. Unlinking it may lock them out of their account. Continue anyway?', ['name' => $pendingUnlinkUserName]) }}
                    </flux:subheading>
                @else
                    <flux:subheading>
                        {{ __(':name will need to sign in again and be re-matched or re-linked.', ['name' => $pendingUnlinkUserName]) }}
                    </flux:subheading>
                @endif
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost" data-test="confirm-unlink-cancel">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button
                    variant="danger"
                    wire:click="unlink({{ $unlinkNeedsForce ? 'true' : 'false' }})"
                    data-test="confirm-unlink-button"
                >
                    {{ $unlinkNeedsForce ? __('Unlink anyway') : __('Unlink') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>

    @if ($this->identityProviderRecord)
        <flux:modal name="disconnect-microsoft" :dismissible="false" class="max-w-lg" data-test="disconnect-modal">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Disconnect Microsoft sign-in?') }}</flux:heading>
                    <flux:subheading>
                        {{ __('This permanently deletes the stored configuration, including the client secret. To reconnect, you will need to enter everything again.') }}
                    </flux:subheading>
                </div>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="danger" wire:click="disconnect" data-test="disconnect-confirm-button">
                        {{ __('Disconnect') }}
                    </flux:button>
                </div>
            </div>
        </flux:modal>
    @endif
</div>
