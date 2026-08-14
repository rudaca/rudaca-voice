<?php

namespace App\Http\Controllers\Auth;

use App\Enums\IdentityProvider;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\View\View;

class OrganizationLoginController extends Controller
{
    /**
     * Show an organization's login page.
     *
     * Route-model binding resolves `$team` by slug; a missing or
     * soft-deleted organization 404s automatically, before any view logic
     * runs.
     */
    public function show(Team $team): View
    {
        $microsoftProvider = $team->identityProviderFor(IdentityProvider::Microsoft);
        $showMicrosoft = $microsoftProvider !== null && $microsoftProvider->enabled && $microsoftProvider->isConfigurable();

        return view('pages::auth.org-login', [
            'team' => $team,
            'showMicrosoft' => $showMicrosoft,
            'enforceSso' => $showMicrosoft && $microsoftProvider->enforce_sso,
        ]);
    }
}
