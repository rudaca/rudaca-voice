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
    public function handle(User $user, string $name, bool $isPersonal = false, bool $allowAnonymousIdeas = true): Team
    {
        return DB::transaction(function () use ($user, $name, $isPersonal, $allowAnonymousIdeas) {
            $team = Team::create([
                'name' => $name,
                'is_personal' => $isPersonal,
                'allow_anonymous_ideas' => $allowAnonymousIdeas,
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
