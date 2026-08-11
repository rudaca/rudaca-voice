<?php

namespace App\Data;

use App\Models\Team;
use App\Models\User;

readonly class MicrosoftLoginResult
{
    public function __construct(
        public User $user,
        public Team $team,
    ) {
        //
    }
}
