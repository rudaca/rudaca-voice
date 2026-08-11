<?php

namespace App\Http\Controllers\Auth;

use App\Actions\IdentityProviders\CompleteMicrosoftLogin;
use App\Exceptions\MicrosoftSsoLoginException;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MicrosoftCallbackController extends Controller
{
    /**
     * Handle Microsoft's redirect back after the user authenticates.
     *
     * This route is intentionally global (not org-scoped in the URL) — the
     * organization the flow belongs to travels entirely through the signed
     * `state` value validated inside CompleteMicrosoftLogin, so there is no
     * client-controlled org identifier here to spoof.
     */
    public function __invoke(Request $request, CompleteMicrosoftLogin $completeMicrosoftLogin): RedirectResponse
    {
        try {
            $result = $completeMicrosoftLogin->handle($request);
        } catch (MicrosoftSsoLoginException $e) {
            return $this->rejected($e);
        }

        Auth::login($result->user);
        $request->session()->regenerate();
        $result->user->switchTeam($result->team);

        // The redirect target is always this fixed, server-computed route —
        // never a client-supplied redirect/next param — so there is nothing
        // here an attacker could use for an open redirect.
        return redirect()->route('dashboard', ['current_team' => $result->team->slug]);
    }

    private function rejected(MicrosoftSsoLoginException $e): RedirectResponse
    {
        $team = $e->teamId ? Team::find($e->teamId) : null;

        return $team
            ? redirect()->route('org.login', $team)->with('error', $e->publicMessage)
            : redirect()->route('login')->with('error', $e->publicMessage);
    }
}
