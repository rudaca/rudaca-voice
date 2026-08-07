<?php

namespace App\Actions\IdentityProviders;

use App\Enums\IdentityProviderAuditAction;
use App\Models\TeamIdentityProvider;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DisconnectTeamIdentityProvider
{
    /**
     * Permanently remove this provider's configuration, including its
     * encrypted secret. Unlike disable, there is nothing left to re-enable —
     * reconnecting means configuring it again from scratch.
     */
    public function handle(TeamIdentityProvider $identityProvider, User $actor): void
    {
        DB::transaction(function () use ($identityProvider, $actor) {
            // Recorded before the delete so the row still exists to satisfy the
            // audit's foreign key; the FK's ON DELETE SET NULL then nulls
            // identity_provider_id on this (and every other) audit row once the
            // configuration itself is gone, matching the rest of the trail.
            $identityProvider->audits()->create([
                'team_id' => $identityProvider->team_id,
                'provider' => $identityProvider->provider,
                'action' => IdentityProviderAuditAction::Disconnected,
                'changed_fields' => [],
                'performed_by_user_id' => $actor->id,
            ]);

            $identityProvider->delete();
        });
    }
}
