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
     */
    public function handle(TeamIdentityProvider $identityProvider, User $actor): TeamIdentityProvider
    {
        $identityProvider->update(['enabled' => false]);

        $identityProvider->audits()->create([
            'team_id' => $identityProvider->team_id,
            'provider' => $identityProvider->provider,
            'action' => IdentityProviderAuditAction::Disabled,
            'changed_fields' => ['enabled'],
            'performed_by_user_id' => $actor->id,
        ]);

        return $identityProvider;
    }
}
