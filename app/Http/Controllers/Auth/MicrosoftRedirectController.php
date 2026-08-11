<?php

namespace App\Http\Controllers\Auth;

use App\Actions\IdentityProviders\InitiateMicrosoftLogin;
use App\Exceptions\MicrosoftSsoLoginException;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;

class MicrosoftRedirectController extends Controller
{
    public function __invoke(Team $team, InitiateMicrosoftLogin $initiateMicrosoftLogin): RedirectResponse
    {
        try {
            $authorizationUrl = $initiateMicrosoftLogin->handle($team);
        } catch (MicrosoftSsoLoginException $e) {
            return redirect()->route('org.login', $team)->with('error', $e->publicMessage);
        }

        return redirect()->away($authorizationUrl);
    }
}
