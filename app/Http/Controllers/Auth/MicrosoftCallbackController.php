<?php

namespace App\Http\Controllers\Auth;

use App\Actions\IdentityProviders\CompleteMicrosoftConnectionTest;
use App\Actions\IdentityProviders\CompleteMicrosoftLogin;
use App\Exceptions\MicrosoftSsoLoginException;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class MicrosoftCallbackController extends Controller
{
    /**
     * Handle Microsoft's redirect back after the user authenticates.
     *
     * This route is intentionally global (not org-scoped in the URL) — the
     * organization the flow belongs to travels entirely through the signed
     * `state` value validated inside CompleteMicrosoftLogin, so there is no
     * client-controlled org identifier here to spoof.
     *
     * A "Test Connection" run shares this exact callback URL (Azure only has
     * one redirect URI registered per app registration), so the two flows
     * are told apart by which cache namespace the state value lives in —
     * checked here before either flow ever pulls it — rather than by
     * anything the client sends.
     *
     * Every outcome below renders the same popup-bridge view rather than
     * redirecting directly: both entry points (org login, connection test)
     * open this callback in a popup window, and the bridge hands the result
     * to the window that opened it and closes itself, or — when there is no
     * popup opener (blocked, or opened in the same tab) — just continues on
     * in place. The server never needs to know which case it is.
     */
    public function __invoke(
        Request $request,
        CompleteMicrosoftLogin $completeMicrosoftLogin,
        CompleteMicrosoftConnectionTest $completeConnectionTest,
    ): View {
        $state = (string) $request->query('state');

        if (filled($state) && Cache::has("oidc_test_state:{$state}")) {
            return $this->completeTest($request, $completeConnectionTest);
        }

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
        return $this->bridge(route('dashboard', ['current_team' => $result->team->slug]));
    }

    /**
     * Complete a connection test and return to the settings page it was
     * started from. Never touches Auth::login or the session — the test
     * proves connectivity, it doesn't sign anyone in — so there is no
     * lingering auth context left behind to clean up either way.
     */
    private function completeTest(Request $request, CompleteMicrosoftConnectionTest $completeConnectionTest): View
    {
        try {
            $team = $completeConnectionTest->handle($request);
        } catch (MicrosoftSsoLoginException $e) {
            return $this->testRejected($e);
        }

        session()->flash('microsoft_test_status', 'success');
        session()->flash('microsoft_test_message', __('Microsoft connection verified.'));

        return $this->bridge(route('ideas.settings', ['current_team' => $team->slug, 'tab' => 'authentication']));
    }

    private function testRejected(MicrosoftSsoLoginException $e): View
    {
        $team = $e->teamId ? Team::find($e->teamId) : null;

        if (! $team) {
            session()->flash('error', $e->publicMessage);

            return $this->bridge(route('home'));
        }

        session()->flash('microsoft_test_status', 'error');
        session()->flash('microsoft_test_message', $e->publicMessage);

        return $this->bridge(route('ideas.settings', ['current_team' => $team->slug, 'tab' => 'authentication']));
    }

    private function rejected(MicrosoftSsoLoginException $e): View
    {
        $team = $e->teamId ? Team::find($e->teamId) : null;

        session()->flash('error', $e->publicMessage);

        return $this->bridge($team ? route('org.login', $team) : route('login'));
    }

    private function bridge(string $targetUrl): View
    {
        return view('auth.microsoft-popup-bridge', ['targetUrl' => $targetUrl]);
    }
}
