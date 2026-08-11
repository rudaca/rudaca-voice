<?php

namespace App\Actions\Teams;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateTeam
{
    /**
     * Create a new team and add the user as owner.
     */
    public function handle(User $user, string $name, bool $isPersonal = false, bool $allowAnonymousIdeas = true, bool $limitOneActiveVotePerBoard = false): Team
    {
        return DB::transaction(function () use ($user, $name, $isPersonal, $allowAnonymousIdeas, $limitOneActiveVotePerBoard) {
            $team = Team::create([
                'name' => $name,
                'is_personal' => $isPersonal,
                'allow_anonymous_ideas' => $allowAnonymousIdeas,
                'limit_one_active_vote_per_board' => $limitOneActiveVotePerBoard,
            ]);

            $membership = $team->memberships()->create([
                'user_id' => $user->id,
                'role' => TeamRole::Owner,
            ]);

            $user->switchTeam($team);

            return $team;
        });
    }
}
