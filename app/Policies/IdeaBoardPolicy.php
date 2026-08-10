<?php

namespace App\Policies;

use App\Models\IdeaBoard;
use App\Models\User;

class IdeaBoardPolicy
{
    /**
     * Determine whether the user may create or view private management
     * notes on the board.
     */
    public function managePrivateNotes(User $user, IdeaBoard $board): bool
    {
        return $user->canManagePrivateNotesOn($board);
    }
}
