<?php

namespace App\Actions\IdentityProviders;

use App\Enums\IdentityProvider;
use App\Enums\IdentityProviderAuditAction;
use App\Enums\SsoEnforcementScope;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamIdentityProvider;
use App\Models\User;
use App\Policies\TeamIdentityProviderPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveTeamIdentityProvider
{
    /**
     * Create or update an organization's configuration for a provider.
     *
     * Never touches `enabled` — flipping that on or off is EnableTeamIdentityProvider /
     * DisableTeamIdentityProvider's job, since enabling has its own prerequisites.
     *
     * @param  array{
     *     tenant_id?: ?string,
     *     client_id?: ?string,
     *     client_secret?: ?string,
     *     enforce_sso?: bool,
     *     enforce_sso_scope?: SsoEnforcementScope|string|null,
     *     auto_provision_users?: bool,
     *     default_role?: TeamRole|string|null,
     *     allowed_domains?: array<int, string>,
     * }  $attributes
     */
    public function handle(Team $team, User $actor, IdentityProvider $provider, array $attributes): TeamIdentityProvider
    {
        return DB::transaction(function () use ($team, $actor, $provider, $attributes) {
            $identityProvider = TeamIdentityProvider::query()
                ->forTeam($team)
                ->provider($provider)
                ->lockForUpdate()
                ->first() ?? new TeamIdentityProvider([
                    'team_id' => $team->id,
                    'provider' => $provider,
                ]);

            $wasNew = ! $identityProvider->exists;

            $defaultRole = $this->resolveDefaultRole($attributes['default_role'] ?? null);
            $autoProvisionUsers = (bool) ($attributes['auto_provision_users'] ?? false);
            $enforceSsoScope = $this->resolveEnforceSsoScope($attributes['enforce_sso_scope'] ?? null);

            $this->guardPrivilegedDefaultRole($actor, $team, $autoProvisionUsers, $defaultRole);

            $changed = [];

            $this->fill($identityProvider, 'tenant_id', $attributes['tenant_id'] ?? null, $changed);
            $this->fill($identityProvider, 'client_id', $attributes['client_id'] ?? null, $changed);
            $this->fill($identityProvider, 'enforce_sso', (bool) ($attributes['enforce_sso'] ?? false), $changed);
            $this->fill($identityProvider, 'enforce_sso_scope', $enforceSsoScope, $changed);
            $this->fill($identityProvider, 'auto_provision_users', $autoProvisionUsers, $changed);
            $this->fill($identityProvider, 'default_role', $defaultRole, $changed);
            $this->fill($identityProvider, 'allowed_domains', $this->normalizeDomains($attributes['allowed_domains'] ?? []), $changed);

            // An empty/omitted secret means "keep what's already there" — only a
            // non-empty value overwrites the existing encrypted secret. A secret
            // is only ever *required* on initial enablement, which is enforced by
            // EnableTeamIdentityProvider (via isConfigurable()), not here — saving
            // partial configuration before enabling is a legitimate step.
            $newSecret = $attributes['client_secret'] ?? null;

            if (filled($newSecret)) {
                $identityProvider->client_secret_encrypted = $newSecret;
                $changed[] = 'client_secret';
            }

            // Any credential change invalidates a prior connection test — it
            // proved the *previous* tenant/client/secret worked, which says
            // nothing about the new values.
            if (array_intersect(['tenant_id', 'client_id', 'client_secret'], $changed)) {
                $identityProvider->verified_at = null;
                $identityProvider->verified_by = null;
                $identityProvider->last_test_failed_at = null;
                $identityProvider->last_test_failure_message = null;
            }

            if ($identityProvider->enforce_sso && blank($identityProvider->verified_at)) {
                throw ValidationException::withMessages([
                    'enforce_sso' => __('Run a successful connection test before requiring Microsoft sign-in.'),
                ]);
            }

            $identityProvider->configured_by = $actor->id;
            $identityProvider->configured_at = now();
            $identityProvider->save();

            $this->audit(
                $identityProvider,
                $wasNew ? IdentityProviderAuditAction::Created : IdentityProviderAuditAction::Updated,
                $actor,
                $changed,
            );

            return $identityProvider;
        });
    }

    /**
     * Normalize submitted attribute value onto the model, recording the field
     * name (never the value) in $changed when it actually differs.
     *
     * @param  array<int, string>  $changed
     */
    private function fill(TeamIdentityProvider $identityProvider, string $attribute, mixed $value, array &$changed): void
    {
        if ($identityProvider->{$attribute} === $value) {
            return;
        }

        $identityProvider->{$attribute} = $value;
        $changed[] = $attribute;
    }

    /**
     * Lowercase and dedupe the submitted domain list.
     *
     * Defense in depth: the Livewire component's validation rules already
     * reject case-insensitive duplicates, but this action normalizes again so
     * any other caller gets the same guarantee.
     *
     * @param  array<int, string>  $domains
     * @return array<int, string>
     */
    private function normalizeDomains(array $domains): array
    {
        return collect($domains)
            ->map(fn (string $domain) => strtolower(trim($domain)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Resolve the submitted default role into a TeamRole, or null.
     */
    private function resolveDefaultRole(TeamRole|string|null $role): ?TeamRole
    {
        if ($role === null || $role === '') {
            return null;
        }

        return $role instanceof TeamRole ? $role : TeamRole::from($role);
    }

    /**
     * Resolve the submitted enforcement scope, defaulting to Global — the
     * safer choice when a caller omits it, matching the column's own default.
     */
    private function resolveEnforceSsoScope(SsoEnforcementScope|string|null $scope): SsoEnforcementScope
    {
        if ($scope === null || $scope === '') {
            return SsoEnforcementScope::Global;
        }

        return $scope instanceof SsoEnforcementScope ? $scope : SsoEnforcementScope::from($scope);
    }

    /**
     * Auto-provisioning requires a default role, and assigning a privileged one
     * requires the same authorization already used for member role changes —
     * enforced via TeamIdentityProviderPolicy::assignDefaultRole so this feature
     * can't become a side door around that check.
     */
    private function guardPrivilegedDefaultRole(User $actor, Team $team, bool $autoProvisionUsers, ?TeamRole $defaultRole): void
    {
        if (! $autoProvisionUsers) {
            return;
        }

        if ($defaultRole === null) {
            throw ValidationException::withMessages([
                'default_role' => __('A default role is required when new users are provisioned automatically.'),
            ]);
        }

        if (! app(TeamIdentityProviderPolicy::class)->assignDefaultRole($actor, $team, $defaultRole)) {
            throw ValidationException::withMessages([
                'default_role' => __('You are not authorized to assign this role to automatically provisioned users.'),
            ]);
        }
    }

    /**
     * Record an audit entry. `changed_fields` carries field names only — the
     * client secret's value must never appear here, only the fact that
     * "client_secret" changed.
     *
     * @param  array<int, string>  $changedFields
     */
    private function audit(TeamIdentityProvider $identityProvider, IdentityProviderAuditAction $action, User $actor, array $changedFields): void
    {
        $identityProvider->audits()->create([
            'team_id' => $identityProvider->team_id,
            'provider' => $identityProvider->provider,
            'action' => $action,
            'changed_fields' => $changedFields,
            'performed_by_user_id' => $actor->id,
        ]);
    }
}
