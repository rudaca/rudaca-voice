<?php

use App\Http\Controllers\Auth\MicrosoftCallbackController;
use App\Http\Controllers\Auth\MicrosoftRedirectController;
use App\Http\Controllers\Auth\OrganizationLoginController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/o/{team:slug}/login', [OrganizationLoginController::class, 'show'])->name('org.login');
    Route::get('/o/{team:slug}/login/microsoft', MicrosoftRedirectController::class)->name('org.login.microsoft');
});

// Global, fixed path: this is the callback URL organizations register with
// their own Azure app registration (see IdentityProvider::redirectUrl()).
// It deliberately carries no org segment — the org travels through the
// signed `state` value instead — and stays reachable while authenticated
// (a browser signed into org A may legitimately be completing a flow for
// org B).
Route::get('/auth/microsoft/callback', MicrosoftCallbackController::class)->name('auth.microsoft.callback');
