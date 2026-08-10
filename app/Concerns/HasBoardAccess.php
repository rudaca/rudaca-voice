<?php

namespace App\Concerns;

use App\Enums\AccessLevel;
use App\Enums\TeamRole;
use App\Models\IdeaBoard;
use App\Models\Team;

trait HasBoardAccess
{
    /**
     * Determine whether the user may create or view private management
     * notes on the given board.
     */
    public function canManagePrivateNotesOn(IdeaBoard $board): bool
    {
        return in_array($board->id, $this->privateNoteBoardIds($board->team), true);
    }

    /**
     * Get the IDs of every board in the team on which the user may create or
     * view private management notes.
     *
     * Team Managers and above may manage private notes on every board. Below
     * that, a user needs an explicit grant scoped to the specific board —
     * either a per-user override (`IdeaBoardUserAccess` at the Manage level)
     * or a per-role grant (`IdeaBoardRoleAccess` for their team role).
     *
     * @return array<int, int>
     */
    public function privateNoteBoardIds(Team $team): array
    {
        $role = $this->teamRole($team);

        if ($role === null) {
            return [];
        }

        if ($role->isAtLeast(TeamRole::Manager)) {
            return $team->boards()->pluck('id')->all();
        }

        return IdeaBoard::query()
            ->where('team_id', $team->id)
            ->where(fn ($query) => $query
                ->whereHas('userAccess', fn ($query) => $query
                    ->where('user_id', $this->id)
                    ->where('access_level', AccessLevel::Manage->value))
                ->orWhereHas('roleAccess', fn ($query) => $query
                    ->where('role', $role->value)))
            ->pluck('id')
            ->all();
    }
}
