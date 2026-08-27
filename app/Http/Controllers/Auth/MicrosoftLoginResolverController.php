<?php

namespace App\Http\Controllers\Auth;

use App\Actions\IdentityProviders\ResolveTeamsForEmailDomain;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MicrosoftLoginResolverController extends Controller
{
    /**
     * Resolve which organization(s) a "Continue with Microsoft" click on the
     * common login page belongs to, from the email the user typed.
     *
     * Each organization owns its own Azure app registration, so — unlike the
     * org-scoped login page, which already knows the team from its URL — the
     * common login page cannot build a Microsoft authorization redirect
     * without first knowing which organization's credentials to use. This
     * asks for the email up front, resolves the matching organization(s) by
     * domain, and only then hands off into the existing per-organization
     * redirect flow.
     */
    public function store(Request $request, ResolveTeamsForEmailDomain $resolveTeams): RedirectResponse|View
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:254'],
        ]);

        $teams = $resolveTeams->handle($data['email']);

        if ($teams->isEmpty()) {
            return redirect()->route('login')->withInput()->with('error', __('No organization was found for that email address. You can still sign in with your password below.'));
        }

        if ($teams->count() === 1) {
            return redirect()->route('org.login.microsoft', ['team' => $teams->first(), 'email' => $data['email']]);
        }

        return view('pages::auth.login-microsoft-select', [
            'teams' => $teams,
            'email' => $data['email'],
        ]);
    }
}
