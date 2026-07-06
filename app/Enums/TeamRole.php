<?php

namespace App\Enums;

enum TeamRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Manager = 'manager';
    case Employee = 'employee';
    case Viewer = 'viewer';

    /**
     * Legacy basic role retained for backwards compatibility with the
     * Livewire starter kit (factories, existing team_members rows, and
     * the feature tests under tests/Feature/Teams). New invitations use
     * the roles returned by self::assignable() instead. Treat as the
     * equivalent of Employee for hierarchy purposes.
     */
    case Member = 'member';

    /**
     * Get the display label for the role.
     */
    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * Get all the permissions for this role.
     *
     * @return array<TeamPermission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => TeamPermission::cases(),
            self::Admin => [
                TeamPermission::UpdateTeam,
                TeamPermission::CreateInvitation,
                TeamPermission::CancelInvitation,
            ],
            self::Manager, self::Employee, self::Viewer, self::Member => [],
        };
    }

    /**
     * Determine if the role has the given permission.
     */
    public function hasPermission(TeamPermission $permission): bool
    {
        return in_array($permission, $this->permissions());
    }

    /**
     * Get the hierarchy level for this role.
     * Higher numbers indicate higher privileges.
     */
    public function level(): int
    {
        return match ($this) {
            self::Owner => 5,
            self::Admin => 4,
            self::Manager => 3,
            self::Employee, self::Member => 2, // Member is the legacy equivalent of Employee
            self::Viewer => 1,
        };
    }

    /**
     * Check if this role is at least as privileged as another role.
     */
    public function isAtLeast(TeamRole $role): bool
    {
        return $this->level() >= $role->level();
    }

    /**
     * Get the roles that can be assigned to team members.
     *
     * Excludes Owner (there is only ever one, set at team creation) and the
     * legacy Member role (kept valid for existing data but no longer offered
     * for new invitations or role changes).
     *
     * @return array<array{value: string, label: string}>
     */
    public static function assignable(): array
    {
        return collect(self::cases())
            ->reject(fn (self $role) => in_array($role, [self::Owner, self::Member], true))
            ->map(fn (self $role) => ['value' => $role->value, 'label' => $role->label()])
            ->values()
            ->toArray();
    }
}
