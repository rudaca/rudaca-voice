<?php

namespace App\Livewire\Concerns;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;

/**
 * Shared board-scoped private-note access for list-style pages (ideas index,
 * dashboard) that render many ideas across potentially many boards per
 * request. Resolves the authorized board IDs once per request instead of
 * running a policy check per row.
 *
 * Host components must expose a `team(): Team` computed property.
 */
trait ScopesPrivateNoteAccess
{
    /**
     * The IDs of every board on the current team the user may create or
     * view private management notes on.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function authorizedPrivateNoteBoardIds(): array
    {
        return Auth::user()->privateNoteBoardIds($this->team);
    }

    /**
     * Whether the user may view private management notes on at least one
     * board on the current team.
     */
    #[Computed]
    public function canViewAnyPrivateNotes(): bool
    {
        return $this->authorizedPrivateNoteBoardIds !== [];
    }
}
