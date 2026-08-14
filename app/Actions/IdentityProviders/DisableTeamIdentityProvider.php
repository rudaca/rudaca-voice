<?php

namespace App\Actions\IdentityProviders;

use App\Enums\IdentityProviderAuditAction;
use App\Models\TeamIdentityProvider;
use App\Models\User;

class DisableTeamIdentityProvider
{
    /**
     * Turn off sign-in via this provider, keeping its configuration in place
     * so it can be re-enabled later without re-entering everything.
     *
     * Also clears `enforce_sso`: leaving it on with the provider disabled
     * would lock out every affected member, since they'd have neither a
     * working password (blocked by enforce_sso) nor Microsoft sign-in
     * (disabled) to get back in with.
     */
    public function handle(TeamIdentityProvider $identityProvider, User $actor): TeamIdentityProvider
    {
        $wasEnforcingSso = $identityProvider->enforce_sso;

        $identityProvider->update([
            'enabled' => false,
            'enforce_sso' => false,
        ]);

        $identityProvider->audits()->create([
            'team_id' => $identityProvider->team_id,
            'provider' => $identityProvider->provider,
            'action' => IdentityProviderAuditAction::Disabled,
            'changed_fields' => $wasEnforcingSso ? ['enabled', 'enforce_sso'] : ['enabled'],
            'performed_by_user_id' => $actor->id,
        ]);

        return $identityProvider;
    }
}
