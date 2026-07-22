<?php

namespace App\Actions\ViewAs;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Models\ViewAsSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

class StartViewAsSession
{
    /**
     * Start a View As session, switching the authenticated user to the target user.
     */
    public function handle(User $superAdmin, User $target, Team $team, TeamRole $role): ViewAsSession
    {
        abort_unless(config('view-as.enabled'), 403);
        abort_unless($superAdmin->is_super_admin, 403);
        abort_if($target->is_super_admin, 403);
        abort_if($target->is($superAdmin), 403);
        abort_unless($target->belongsToTeam($team), 403);

        $session = ViewAsSession::create([
            'super_admin_id' => $superAdmin->id,
            'target_user_id' => $target->id,
            'team_id' => $team->id,
            'role_viewed_as' => $role,
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        Auth::loginUsingId($target->id);

        Session::put('view_as_session_id', $session->id);

        return $session;
    }
}
