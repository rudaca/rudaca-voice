<?php

namespace App\Actions\IdentityProviders;

use App\Enums\IdentityProviderAuditAction;
use App\Models\TeamIdentityProvider;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class EnableTeamIdentityProvider
{
    /**
     * Turn on sign-in via this provider.
     *
     * Requires a tenant id (when the provider needs one), client id, and client
     * secret to already be present — enabling never happens implicitly as a
     * side effect of saving partial configuration.
     */
    public function handle(TeamIdentityProvider $identityProvider, User $actor): TeamIdentityProvider
    {
        if (! $identityProvider->isConfigurable()) {
            throw ValidationException::withMessages([
                'enabled' => __('Tenant id, client id, and a client secret are all required before :provider sign-in can be enabled.', [
                    'provider' => $identityProvider->provider->label(),
                ]),
            ]);
        }

        $identityProvider->forceFill([
            'enabled' => true,
            'configured_by' => $actor->id,
            'configured_at' => now(),
        ])->save();

        $identityProvider->audits()->create([
            'team_id' => $identityProvider->team_id,
            'provider' => $identityProvider->provider,
            'action' => IdentityProviderAuditAction::Enabled,
            'changed_fields' => ['enabled'],
            'performed_by_user_id' => $actor->id,
        ]);

        return $identityProvider;
    }
}
