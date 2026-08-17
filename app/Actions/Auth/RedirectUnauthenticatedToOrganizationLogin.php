<?php

namespace App\Actions\Auth;

use App\Models\Team;
use Illuminate\Http\Request;

class RedirectUnauthenticatedToOrganizationLogin
{
    /**
     * Get the URL a guest should be redirected to for the current request.
     *
     * Routes scoped to a team carry its slug as the `current_team` or `team`
     * route parameter. Sending guests to that team's own login page (rather
     * than the generic one) keeps its SSO options in front of them and,
     * because Laravel still records the originally requested URL as the
     * post-login "intended" destination, doesn't affect where they land
     * once signed in.
     */
    public function handle(Request $request): string
    {
        $team = $this->team($request);

        return $team ? route('org.login', $team) : route('login');
    }

    protected function team(Request $request): ?Team
    {
        $parameter = $request->route('current_team') ?? $request->route('team');

        if ($parameter instanceof Team) {
            return $parameter;
        }

        return is_string($parameter) ? Team::where('slug', $parameter)->first() : null;
    }
}
