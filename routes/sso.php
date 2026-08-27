<?php

use App\Http\Controllers\Auth\MicrosoftCallbackController;
use App\Http\Controllers\Auth\MicrosoftLoginResolverController;
use App\Http\Controllers\Auth\MicrosoftRedirectController;
use App\Http\Controllers\Auth\OrganizationLoginController;
use App\Http\Controllers\Auth\OwnerRecoveryController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/o/{team:slug}/login', [OrganizationLoginController::class, 'show'])->name('org.login');
    Route::get('/o/{team:slug}/login/microsoft', MicrosoftRedirectController::class)->name('org.login.microsoft');

    // Common (non-org-scoped) login's "Continue with Microsoft" entry point:
    // resolves which organization(s) the typed email's domain belongs to,
    // then hands off into the redirect above for that organization.
    Route::post('/login/microsoft', [MicrosoftLoginResolverController::class, 'store'])->name('login.microsoft.resolve');

    // Owner-only fallback for when an organization has locked itself out of
    // both password login (enforce_sso) and Microsoft sign-in. Deliberately
    // not linked from anywhere in the UI — an owner who needs it must be
    // told the URL out of band.
    Route::get('/o/{team:slug}/recovery', [OwnerRecoveryController::class, 'create'])->name('org.recovery');
    Route::post('/o/{team:slug}/recovery', [OwnerRecoveryController::class, 'store'])
        ->middleware('throttle:owner-recovery-request')
        ->name('org.recovery.store');
    // ->withoutScopedBindings(): without it, Laravel's implicit nested-model
    // binding treats {token} as a relation on {team} (guessing `$team->tokens()`,
    // which doesn't exist) since it has an explicit :code binding field.
    // The controller itself already verifies the token belongs to this team.
    Route::get('/o/{team:slug}/recovery/{token:code}', [OwnerRecoveryController::class, 'show'])
        ->withoutScopedBindings()
        ->name('org.recovery.show');
    Route::post('/o/{team:slug}/recovery/{token:code}', [OwnerRecoveryController::class, 'confirm'])
        ->middleware('throttle:owner-recovery-verify')
        ->withoutScopedBindings()
        ->name('org.recovery.confirm');
});

// Global, fixed path: this is the callback URL organizations register with
// their own Azure app registration (see IdentityProvider::redirectUrl()).
// It deliberately carries no org segment — the org travels through the
// signed `state` value instead — and stays reachable while authenticated
// (a browser signed into org A may legitimately be completing a flow for
// org B).
Route::get('/auth/microsoft/callback', MicrosoftCallbackController::class)->name('auth.microsoft.callback');
