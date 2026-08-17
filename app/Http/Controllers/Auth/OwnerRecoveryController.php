<?php

namespace App\Http\Controllers\Auth;

use App\Actions\OwnerRecovery\RequestOwnerRecovery;
use App\Actions\OwnerRecovery\VerifyOwnerRecoveryCode;
use App\Exceptions\OwnerRecoveryException;
use App\Http\Controllers\Controller;
use App\Models\OwnerRecoveryToken;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class OwnerRecoveryController extends Controller
{
    /**
     * Show the recovery request form.
     */
    public function create(Team $team): View
    {
        return view('pages::auth.owner-recovery-request', ['team' => $team]);
    }

    /**
     * Handle a recovery request.
     *
     * Always shows the same generic confirmation, whether or not the email
     * matched this organization's owner — RequestOwnerRecovery is what
     * decides, silently, whether anything is actually sent.
     */
    public function store(Team $team, Request $request, RequestOwnerRecovery $requestOwnerRecovery): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $requestOwnerRecovery->handle($team, $validated['email'], $request->ip());

        return redirect()->route('org.recovery', $team)->with(
            'status',
            __("If that email belongs to an organization owner, we've sent recovery instructions."),
        );
    }

    /**
     * Show the one-time code entry form.
     *
     * The same generic message is shown for every reason a token can't be
     * used (expired, used, attempts exceeded, wrong organization) — never
     * distinguishing which, so a requester can't learn anything from it.
     */
    public function show(Team $team, OwnerRecoveryToken $token): View|RedirectResponse
    {
        if ($token->team_id !== $team->id || ! $token->isUsable()) {
            return redirect()->route('org.recovery', $team)->with(
                'error',
                __('This recovery link is invalid or has expired.'),
            );
        }

        return view('pages::auth.owner-recovery-verify', ['team' => $team, 'token' => $token]);
    }

    /**
     * Verify the one-time code and, on success, log the owner in.
     */
    public function confirm(Team $team, OwnerRecoveryToken $token, Request $request, VerifyOwnerRecoveryCode $verifyOwnerRecoveryCode): RedirectResponse
    {
        abort_if($token->team_id !== $team->id, 404);

        $validated = $request->validate([
            'code' => ['required', 'string'],
        ]);

        try {
            $owner = $verifyOwnerRecoveryCode->handle($token, $validated['code']);
        } catch (OwnerRecoveryException $e) {
            return redirect()->route('org.recovery.show', ['team' => $team, 'token' => $token])
                ->with('error', $e->publicMessage);
        }

        Auth::login($owner);
        $request->session()->regenerate();
        $owner->switchTeam($team);

        return redirect()->route('ideas.settings', ['current_team' => $team->slug, 'tab' => 'authentication'])
            ->with('status', __('Owner access recovered. Please review your Microsoft sign-in configuration.'));
    }
}
