<?php

namespace App\Policies;

use App\Enums\TeamPermission;
use App\Models\Team;
use App\Models\User;
use App\Models\UserIdentityAccount;

/**
 * Authorization for viewing and unlinking an organization's linked external
 * identities.
 *
 * Every gate resolves the organization from the record (or from the explicitly
 * passed team for the class-level gate) and checks the acting user's membership
 * role in *that* organization, so a guessed or tampered id can never grant access.
 */
class UserIdentityAccountPolicy
{
    /**
     * Determine whether the user can view the organization's identity links.
     */
    public function viewAny(User $user, Team $team): bool
    {
        return $this->canManage($user, $team);
    }

    /**
     * Determine whether the user can view the identity link.
     */
    public function view(User $user, UserIdentityAccount $identityAccount): bool
    {
        return $this->canManage($user, $identityAccount->team);
    }

    /**
     * Determine whether the user can unlink the identity link.
     */
    public function delete(User $user, UserIdentityAccount $identityAccount): bool
    {
        return $this->canManage($user, $identityAccount->team);
    }

    /**
     * Whether the user owns the organization or holds the explicit permission to
     * manage its authentication settings.
     */
    protected function canManage(User $user, Team $team): bool
    {
        return $user->ownsTeam($team)
            || $user->hasTeamPermission($team, TeamPermission::ManageAuthentication);
    }
}
