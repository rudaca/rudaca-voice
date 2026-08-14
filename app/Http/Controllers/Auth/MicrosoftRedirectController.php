<?php

namespace App\Http\Controllers\Auth;

use App\Actions\IdentityProviders\InitiateMicrosoftLogin;
use App\Exceptions\MicrosoftSsoLoginException;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MicrosoftRedirectController extends Controller
{
    public function __invoke(Team $team, Request $request, InitiateMicrosoftLogin $initiateMicrosoftLogin): RedirectResponse
    {
        try {
            $authorizationUrl = $initiateMicrosoftLogin->handle($team, $request->query('email'));
        } catch (MicrosoftSsoLoginException $e) {
            return redirect()->route('org.login', $team)->with('error', $e->publicMessage);
        }

        return redirect()->away($authorizationUrl);
    }
}
