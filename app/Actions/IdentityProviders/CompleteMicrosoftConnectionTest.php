<?php

namespace App\Actions\IdentityProviders;

use App\Enums\IdentityProviderAuditAction;
use App\Exceptions\MicrosoftSsoLoginException;
use App\Models\Team;
use App\Models\TeamIdentityProvider;
use App\Services\Microsoft\MicrosoftAuthorizationCodeExchanger;
use App\Services\Microsoft\MicrosoftIdTokenValidator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CompleteMicrosoftConnectionTest
{
    public function __construct(
        private readonly MicrosoftAuthorizationCodeExchanger $codeExchanger,
        private readonly MicrosoftIdTokenValidator $tokenValidator,
    ) {}

    /**
     * Complete a Microsoft connection test callback.
     *
     * Reuses the same tenant-scoped token exchange and ID token validation
     * (issuer, audience, nonce, and — critically — tenant match) that real
     * sign-in relies on, so a mismatched tenant fails exactly the same way it
     * would for an actual login. This never authenticates anyone: it only
     * proves the organization's configuration can complete a full OIDC round
     * trip, so there is no session established here and none to clean up
     * afterward.
     *
     * @return Team the organization the test belonged to, for the controller's redirect
     *
     * @throws MicrosoftSsoLoginException
     */
    public function handle(Request $request): Team
    {
        $state = (string) $request->query('state');
        $stored = filled($state) ? Cache::pull("oidc_test_state:{$state}") : null;

        if (! $stored) {
            Log::warning('microsoft_connection_test_failed', ['reason' => 'invalid_state']);

            throw new MicrosoftSsoLoginException(
                __('Your connection test request has expired. Please try again.'),
                'invalid_state',
            );
        }

        $team = Team::find($stored['team_id']);
        $identityProvider = $team
            ? TeamIdentityProvider::query()->forTeam($team)->find($stored['identity_provider_id'])
            : null;

        if (! $team || ! $identityProvider) {
            Log::warning('microsoft_connection_test_failed', ['reason' => 'organization_not_found', 'team_id' => $stored['team_id']]);

            throw new MicrosoftSsoLoginException(
                __('This organization could not be found.'),
                'organization_not_found',
                $stored['team_id'],
            );
        }

        if ((int) $stored['initiated_by'] !== Auth::id()) {
            $this->reject($identityProvider, 'different_administrator', __('Please run the connection test from the same session that started it.'));
        }

        if ($request->filled('error')) {
            $this->reject($identityProvider, 'provider_error', __('Microsoft reported the connection attempt was cancelled or denied.'), [
                'provider_error' => (string) $request->query('error'),
                'provider_error_description' => (string) $request->query('error_description'),
            ]);
        }

        $code = (string) $request->query('code');

        if (blank($code)) {
            $this->reject($identityProvider, 'missing_code', __('The connection test could not be completed. Please try again.'));
        }

        try {
            $idToken = $this->codeExchanger->exchange($identityProvider, $code, $stored['code_verifier']);
            $this->tokenValidator->validate($idToken, $identityProvider, $stored['nonce']);
        } catch (MicrosoftSsoLoginException $e) {
            $this->reject($identityProvider, $e->logReason, $e->publicMessage, $e->context);
        }

        $this->succeed($identityProvider);

        return $team;
    }

    private function succeed(TeamIdentityProvider $identityProvider): void
    {
        $identityProvider->forceFill([
            'verified_at' => now(),
            'verified_by' => Auth::id(),
            'last_test_failed_at' => null,
            'last_test_failure_message' => null,
        ])->save();

        $identityProvider->audits()->create([
            'team_id' => $identityProvider->team_id,
            'provider' => $identityProvider->provider,
            'action' => IdentityProviderAuditAction::ConnectionTestSucceeded,
            'changed_fields' => [],
            'performed_by_user_id' => Auth::id(),
        ]);
    }

    /**
     * Record the failed attempt and throw. Unlike a real login's rejection,
     * `verified_at` is left untouched here — a failed re-test doesn't erase
     * proof that the configuration worked at some point, it just means the
     * configuration no longer currently tests clean.
     *
     * `$context` carries diagnostic detail (e.g. Microsoft's own error code)
     * that is safe to log and store but deliberately never becomes
     * `$publicMessage` — it's for troubleshooting a report like "the
     * connection test failed", not for showing to the admin who ran it.
     *
     * @param  array<string, string>  $context
     */
    private function reject(TeamIdentityProvider $identityProvider, string $reason, string $publicMessage, array $context = []): never
    {
        $identityProvider->forceFill([
            'last_test_failed_at' => now(),
            'last_test_failure_message' => $publicMessage,
        ])->save();

        $identityProvider->audits()->create([
            'team_id' => $identityProvider->team_id,
            'provider' => $identityProvider->provider,
            'action' => IdentityProviderAuditAction::ConnectionTestFailed,
            'changed_fields' => [$reason],
            'error_context' => $context !== [] ? $context : null,
            'performed_by_user_id' => Auth::id(),
        ]);

        if ($context !== []) {
            Log::warning('microsoft_connection_test_failed', ['reason' => $reason, 'team_id' => $identityProvider->team_id, ...$context]);
        }

        throw new MicrosoftSsoLoginException($publicMessage, $reason, $identityProvider->team_id);
    }
}
