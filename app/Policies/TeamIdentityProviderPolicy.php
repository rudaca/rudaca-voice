<?php

namespace App\Policies;

use App\Enums\TeamPermission;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamIdentityProvider;
use App\Models\User;

/**
 * Authorization for an organization's external sign-in configuration.
 *
 * Every gate resolves the organization from the record (or from the explicitly
 * passed team for the class-level gates) and checks the acting user's membership
 * role in *that* organization, so a guessed or tampered id can never grant access.
 */
class TeamIdentityProviderPolicy
{
    /**
     * Determine whether the user can view the organization's configurations.
     */
    public function viewAny(User $user, Team $team): bool
    {
        return $this->canManage($user, $team);
    }

    /**
     * Determine whether the user can view the configuration.
     */
    public function view(User $user, TeamIdentityProvider $identityProvider): bool
    {
        return $this->canManage($user, $identityProvider->team);
    }

    /**
     * Determine whether the user can configure a provider for the organization.
     */
    public function create(User $user, Team $team): bool
    {
        return $this->canManage($user, $team);
    }

    /**
     * Determine whether the user can update the configuration.
     */
    public function update(User $user, TeamIdentityProvider $identityProvider): bool
    {
        return $this->canManage($user, $identityProvider->team);
    }

    /**
     * Determine whether the user can disconnect (delete) the configuration.
     */
    public function delete(User $user, TeamIdentityProvider $identityProvider): bool
    {
        return $this->canManage($user, $identityProvider->team);
    }

    /**
     * Determine whether the user may assign the given role as the default role
     * for auto-provisioned users.
     *
     * Privileged roles are held to the same bar as changing an existing member's
     * role, so managing authentication settings alone can never be used to mint
     * admin-tier accounts.
     */
    public function assignDefaultRole(User $user, Team $team, TeamRole $role): bool
    {
        if (! $this->canManage($user, $team)) {
            return false;
        }

        if (! $role->isPrivileged()) {
            return true;
        }

        return $user->hasTeamPermission($team, TeamPermission::UpdateMember);
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
