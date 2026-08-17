<?php

use App\Actions\IdentityProviders\DisableTeamIdentityProvider;
use App\Actions\IdentityProviders\DisconnectTeamIdentityProvider;
use App\Actions\IdentityProviders\EnableTeamIdentityProvider;
use App\Actions\IdentityProviders\InitiateMicrosoftConnectionTest;
use App\Actions\IdentityProviders\SaveTeamIdentityProvider;
use App\Actions\IdentityProviders\UnlinkUserIdentityAccount;
use App\Concerns\IdentityProviderValidationRules;
use App\Enums\IdentityProvider;
use App\Enums\IdentityProviderConfigurationStatus;
use App\Enums\SsoEnforcementScope;
use App\Enums\TeamRole;
use App\Exceptions\MicrosoftSsoLoginException;
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

    public string $enforceSsoScope = '';

    // --- Linked identities (unlink confirmation) ---
    public ?int $pendingUnlinkId = null;

    public string $pendingUnlinkUserName = '';

    /**
     * Whether the last unlink attempt was blocked because it was the user's
     * only linked identity — the confirmation modal escalates to a starker
     * warning and offers to retry with $force.
     */
    public bool $unlinkNeedsForce = false;

    // --- Require-Microsoft-sign-in confirmation ---
    /**
     * The validated form payload staged while the confirmation modal is
     * open — populated by save(), consumed by confirmEnableEnforcement().
     *
     * @var array<string, mixed>|null
     */
    public ?array $pendingEnforceSsoSave = null;

    public function mount(Team $team): void
    {
        Gate::authorize('viewAny', [TeamIdentityProvider::class, $team]);

        $this->team = $team;

        $this->hydrateFromRecord();

        if ($status = session('microsoft_test_status')) {
            Flux::toast(variant: $status === 'success' ? 'success' : 'danger', text: session('microsoft_test_message'));
        }
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
    public function configurationStatus(): IdentityProviderConfigurationStatus
    {
        return $this->identityProviderRecord?->configurationStatus() ?? IdentityProviderConfigurationStatus::NotConfigured;
    }

    #[Computed]
    public function showVerificationDetails(): bool
    {
        return $this->configurationStatus === IdentityProviderConfigurationStatus::Verified;
    }

    #[Computed]
    public function showTestFailureDetails(): bool
    {
        return $this->configurationStatus === IdentityProviderConfigurationStatus::ConfigurationError;
    }

    #[Computed]
    public function redirectUrl(): string
    {
        return IdentityProvider::Microsoft->redirectUrl();
    }

    #[Computed]
    public function loginUrl(): string
    {
        return route('org.login', $this->team);
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
     * @return array<int, array{value: string, label: string, description: string}>
     */
    #[Computed]
    public function enforceSsoScopes(): array
    {
        return array_map(fn (SsoEnforcementScope $scope) => [
            'value' => $scope->value,
            'label' => $scope->label(),
            'description' => $scope->description(),
        ], SsoEnforcementScope::cases());
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
            'enforceSsoScope' => __('enforcement scope'),
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

        $requireSecret = ! $this->hasExistingSecret;

        $validated = $this->validate($this->microsoftIdentityProviderRules($requireSecret));

        if ($validated['enforceSso'] && ! $this->enabled) {
            $this->addError('enforceSso', __('Enable Microsoft sign-in before requiring it.'));

            return;
        }

        // Turning enforcement on for the first time requires the admin to
        // explicitly confirm they understand password login goes away for
        // everyone else — stage the validated payload and let
        // confirmEnableEnforcement() finish the save once they do.
        $wasEnforcing = $existing?->enforce_sso ?? false;

        if ($validated['enforceSso'] && ! $wasEnforcing) {
            if (! app(SaveTeamIdentityProvider::class)->ownerHasLinkedIdentity($this->team, IdentityProvider::Microsoft)) {
                $this->addError('enforceSso', __('At least one organization owner must sign in with Microsoft before you can require it for everyone.'));

                return;
            }

            $this->pendingEnforceSsoSave = $validated;
            $this->dispatch('modal-show', name: 'confirm-enforce-sso');

            return;
        }

        $this->persistIdentityProvider($validated);
    }

    /**
     * Finish saving after the admin confirms the require-Microsoft-sign-in
     * warning from save().
     */
    public function confirmEnableEnforcement(): void
    {
        if (! $this->pendingEnforceSsoSave) {
            return;
        }

        $validated = $this->pendingEnforceSsoSave;
        $this->pendingEnforceSsoSave = null;

        $this->persistIdentityProvider($validated);

        $this->dispatch('modal-close', name: 'confirm-enforce-sso');
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function persistIdentityProvider(array $validated): void
    {
        $existing = $this->identityProviderRecord;
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
                    'enforce_sso_scope' => $validated['enforceSsoScope'],
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
     * Start a Microsoft connection test, returning the authorization URL for
     * the browser to open (in a popup, opened synchronously by the caller —
     * see the button markup below — with a same-tab fallback if popups are
     * blocked). Returns null on failure, after toasting the reason.
     *
     * Configuration completeness is checked here, server-side, before ever
     * contacting Microsoft — a caller can't attempt a test just because the
     * button happened to be clickable.
     */
    public function testConnection(): ?string
    {
        $existing = $this->identityProviderRecord;

        abort_if(! $existing, 404);

        Gate::authorize('test', $existing);

        try {
            return app(InitiateMicrosoftConnectionTest::class)->handle($existing, Auth::user());
        } catch (MicrosoftSsoLoginException $e) {
            Flux::toast(variant: 'danger', text: $e->publicMessage);

            return null;
        }
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
        $this->enforceSsoScope = ($identityProvider?->enforce_sso_scope ?? SsoEnforcementScope::Global)->value;
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
            'enforce_sso' => 'enforceSso',
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

<div class="space-y-6" data-test="authentication-settings">
    <div class="space-y-6">
        {{-- Microsoft 365 Configuration --}}
        <div class="mt-3 rounded-lg border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" x-data="{ open: true }">
            <div class="flex items-center justify-between gap-4">
                <div class="flex items-center gap-2">
                    <flux:icon.microsoft class="size-6" />
                    <div>
                        <flux:heading size="lg" class="font-bold">{{ __('Microsoft 365 Configuration') }}</flux:heading>
                        <flux:subheading>{{ __('Microsoft Entra ID (Microsoft 365)') }}</flux:subheading>
                        <a href="https://github.com/rudaca/rudaca-voice/blob/main/docs/microsoft-sso-setup.md" target="_blank" rel="noopener" class="text-xs text-indigo-600 hover:underline dark:text-indigo-400" data-test="microsoft-setup-guide-link">
                            {{ __('View setup guide') }}
                        </a>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <div class="text-right">
                        <flux:badge color="{{ $this->configurationStatus->color() }}" size="sm" data-test="microsoft-status-{{ $this->configurationStatus->value }}">
                            {{ $this->configurationStatus->label() }}
                        </flux:badge>

                        @if ($this->showVerificationDetails)
                            <flux:text class="mt-1 block text-xs text-slate-600 dark:text-slate-500" data-test="microsoft-verified-details">
                                {{ __('Tested :date by :name', [
                                    'date' => $this->identityProviderRecord->verified_at->forUser()->format('M d, Y h:i A'),
                                    'name' => $this->identityProviderRecord->verifiedBy?->name ?? __('an administrator'),
                                ]) }}
                            </flux:text>
                        @elseif ($this->showTestFailureDetails)
                            <flux:text class="mt-1 block text-xs text-red-600 dark:text-red-400" data-test="microsoft-test-failure-details">
                                {{ $this->identityProviderRecord->last_test_failure_message }}
                            </flux:text>
                        @endif
                    </div>

                    @if ($this->identityProviderRecord?->isConfigurable())
                        <flux:button
                            size="sm"
                            type="button"
                            class="bg-black! text-white! hover:bg-zinc-800! dark:bg-gray-500! dark:text-white! dark:hover:bg-gray-600!"
                            x-data
                            x-on:click="
                                const popup = window.open('about:blank', 'microsoft-test-connection', 'width=500,height=650');

                                $wire.testConnection().then((url) => {
                                    if (!url) {
                                        if (popup) { popup.close(); }
                                        return;
                                    }

                                    if (popup && !popup.closed) {
                                        popup.location = url;
                                    } else {
                                        window.location.href = url;
                                    }
                                });
                            "
                            data-test="test-connection-button"
                        >
                            {{ __('Test Connection') }}
                        </flux:button>
                    @endif

                    <flux:button
                        variant="ghost"
                        size="sm"
                        square
                        type="button"
                        x-on:click="open = ! open"
                        x-bind:aria-expanded="open"
                        aria-label="{{ __('Toggle Microsoft 365 Configuration') }}"
                        data-test="toggle-microsoft-config"
                    >
                        <flux:icon.chevron-down class="size-4 transition-transform duration-200" x-bind:class="{ 'rotate-180': open }" />
                    </flux:button>
                </div>
            </div>

            <div x-show="open" x-collapse>
                <form wire:submit="save" class="mt-6 space-y-8">
                    <flux:switch
                        wire:model="enabled"
                        :label="__('Enable Microsoft sign-in')"
                        :description="__('Members will be able to sign in with their Microsoft account once tenant ID, client ID, and a client secret are saved.')"
                        data-test="microsoft-enabled-toggle"
                    />
                    <flux:separator />

                    <div class="grid grid-cols-1 gap-8 lg:grid-cols-2">
                        <div class="space-y-6">
                            <flux:heading size="sm" class="flex items-center gap-2 font-bold normal-case">
                                <flux:icon.key class="size-4" />
                                {{ __('App Registration') }}
                            </flux:heading>

                            <flux:input
                                wire:model="tenantId"
                                :label="__('Tenant ID')"
                                :description="__('Your Entra ID directory (tenant) GUID, or common / organizations / consumers.')"
                                required
                                data-test="tenant-id-input"
                            />

                            <flux:input
                                wire:model="clientId"
                                :label="__('Application (client) ID')"
                                required
                                data-test="client-id-input"
                            />

                            @if ($hasExistingSecret && ! $replacingSecret)
                                <flux:field>
                                    <flux:label>{{ __('Client secret') }}</flux:label>
                                    <flux:input.group>
                                        <flux:input value="••••••••••••••••" readonly data-test="client-secret-masked" />
                                        <flux:button wire:click="$set('replacingSecret', true)" data-test="replace-secret-button">
                                            {{ __('Replace secret') }}
                                        </flux:button>
                                    </flux:input.group>
                                </flux:field>
                            @else
                                <flux:input
                                    type="password"
                                    wire:model="newSecretInput"
                                    :label="__('Client secret')"
                                    :description="$hasExistingSecret ? __('Leave blank to keep the current secret.') : __('Required to save your Microsoft configuration.')"
                                    :required="! $hasExistingSecret"
                                    data-test="client-secret-input"
                                />
                            @endif

                            <flux:field>
                                <flux:label>{{ __('Redirect URL') }}</flux:label>
                                <flux:input.group>
                                    <flux:input :value="$this->redirectUrl" readonly data-test="redirect-url-input" />
                                    <flux:button
                                        type="button"
                                        x-data="{
                                            copied: false,
                                            copy() {
                                                const text = @js($this->redirectUrl);

                                                if (navigator.clipboard && window.isSecureContext) {
                                                    navigator.clipboard.writeText(text);
                                                } else {
                                                    const input = document.createElement('textarea');
                                                    input.value = text;
                                                    input.style.position = 'fixed';
                                                    input.style.opacity = '0';
                                                    document.body.appendChild(input);
                                                    input.focus();
                                                    input.select();
                                                    document.execCommand('copy');
                                                    document.body.removeChild(input);
                                                }

                                                this.copied = true;
                                                setTimeout(() => (this.copied = false), 2000);
                                            },
                                        }"
                                        x-on:click="copy()"
                                        data-test="copy-redirect-url-button"
                                    >
                                        <flux:icon.clipboard-document x-show="!copied" x-cloak class="size-4" />
                                        <flux:icon.clipboard-document-check x-show="copied" x-cloak class="size-4" />
                                    </flux:button>
                                </flux:input.group>
                                <flux:description>{{ __('Register this URL as the redirect URI in your Microsoft app registration.') }}</flux:description>
                            </flux:field>
                        </div>

                        <div class="space-y-6">
                            <flux:heading size="sm" class="flex items-center gap-2 font-bold normal-case">
                                <flux:icon.users class="size-4" />
                                {{ __('Provisioning & Access') }}
                            </flux:heading>

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
                                wire:model.live="enforceSso"
                                :label="__('Require Microsoft sign-in')"
                                :description="__('Requires Microsoft sign-in to be enabled. Choose the scope below.')"
                                data-test="enforce-sso-toggle"
                            />

                            @if ($enforceSso)
                                <flux:radio.group wire:model="enforceSsoScope" :label="__('Enforcement scope')" data-test="enforce-sso-scope">
                                    @foreach ($this->enforceSsoScopes as $scope)
                                        <flux:radio
                                            value="{{ $scope['value'] }}"
                                            :label="$scope['label']"
                                            :description="$scope['description']"
                                            data-test="enforce-sso-scope-{{ $scope['value'] }}"
                                        />
                                    @endforeach
                                </flux:radio.group>
                            @endif
                        </div>
                    </div>

                    <div class="flex items-center justify-between gap-2 border-t border-zinc-200 pt-6 dark:border-zinc-700">
                        <flux:button
                            variant="ghost"
                            type="button"
                            x-data="{
                                copied: false,
                                copy() {
                                    const text = @js($this->loginUrl);

                                    if (navigator.clipboard && window.isSecureContext) {
                                        navigator.clipboard.writeText(text);
                                    } else {
                                        const input = document.createElement('textarea');
                                        input.value = text;
                                        input.style.position = 'fixed';
                                        input.style.opacity = '0';
                                        document.body.appendChild(input);
                                        input.focus();
                                        input.select();
                                        document.execCommand('copy');
                                        document.body.removeChild(input);
                                    }

                                    this.copied = true;
                                    setTimeout(() => (this.copied = false), 2000);
                                },
                            }"
                            x-on:click="copy()"
                            data-test="copy-login-link-button"
                        >
                            <span x-show="!copied" x-cloak class="flex items-center gap-2">
                                <flux:icon.clipboard-document class="size-4" />
                                {{ __('Copy Login Link') }}
                            </span>
                            <span x-show="copied" x-cloak class="flex items-center gap-2">
                                <flux:icon.clipboard-document-check class="size-4" />
                                {{ __('Copied Login Link') }}
                            </span>
                        </flux:button>

                        <div class="flex items-center gap-2">
                            @if ($this->identityProviderRecord)
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
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="space-y-6">
        {{-- Linked accounts --}}
        <div class="mt-3 rounded-lg border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" data-test="identity-links" x-data="{ open: true }">
            <div class="flex items-center justify-between gap-2">
                <div class="flex items-center gap-2">
                    <flux:icon.plug class="size-6" />
                    <div>
                        <flux:heading size="lg" class="font-bold">{{ __('Linked Accounts') }}</flux:heading>
                        <flux:subheading>{{ __('Microsoft accounts linked to members of this organization.') }}</flux:subheading>
                    </div>
                </div>

                <flux:button
                    variant="ghost"
                    size="sm"
                    square
                    type="button"
                    x-on:click="open = ! open"
                    x-bind:aria-expanded="open"
                    aria-label="{{ __('Toggle Linked accounts') }}"
                    data-test="toggle-linked-accounts"
                >
                    <flux:icon.chevron-down class="size-4 transition-transform duration-200" x-bind:class="{ 'rotate-180': open }" />
                </flux:button>
            </div>

            <div x-show="open" x-collapse>
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
    </div>

    <flux:modal name="confirm-enforce-sso" :dismissible="false" class="max-w-lg" data-test="confirm-enforce-sso-modal">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Require Microsoft sign-in for this organization?') }}</flux:heading>
                <flux:subheading>
                    {{ __("Members will no longer be able to use normal password login. Confirm that at least one owner can sign in with Microsoft before continuing.") }}
                </flux:subheading>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost" data-test="confirm-enforce-sso-cancel">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="confirmEnableEnforcement" data-test="confirm-enforce-sso-button">
                    {{ __('Require Microsoft sign-in') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>

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
