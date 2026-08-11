<?php

namespace App\Services\Microsoft;

use App\Exceptions\MicrosoftSsoLoginException;
use App\Models\TeamIdentityProvider;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Verifies a Microsoft Entra ID token belongs to the organization that
 * requested it: valid signature (against the tenant's own JWKS), issuer,
 * audience, expiry, nonce, and — for organizations pinned to a specific
 * tenant — the `tid` claim.
 */
class MicrosoftIdTokenValidator
{
    /**
     * Decode and verify the ID token, returning its claims.
     *
     * @return array<string, mixed>
     */
    public function validate(string $idToken, TeamIdentityProvider $identityProvider, string $expectedNonce): array
    {
        $tenant = $identityProvider->tenant_id;

        $keys = $this->fetchSigningKeys($tenant);

        try {
            $claims = (array) JWT::decode($idToken, $keys);
        } catch (Throwable) {
            throw new MicrosoftSsoLoginException(
                __('Your Microsoft sign-in could not be verified. Please try again.'),
                'signature_invalid',
                $identityProvider->team_id,
            );
        }

        $this->assertIssuer($claims, $tenant, $identityProvider->team_id);
        $this->assertAudience($claims, $identityProvider);
        $this->assertNonce($claims, $expectedNonce, $identityProvider->team_id);
        $this->assertTenant($claims, $identityProvider);

        return $claims;
    }

    /**
     * Fetch and cache the tenant's JSON Web Key Set.
     *
     * @return array<string, Key>
     */
    private function fetchSigningKeys(string $tenant): array
    {
        $jwks = Cache::remember(
            "microsoft_jwks:{$tenant}",
            now()->addHour(),
            fn () => Http::get("https://login.microsoftonline.com/{$tenant}/discovery/v2.0/keys")->throw()->json(),
        );

        return JWK::parseKeySet($jwks, 'RS256');
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function assertIssuer(array $claims, string $tenant, int $teamId): void
    {
        $tid = $claims['tid'] ?? null;

        if (! is_string($tid) || ($claims['iss'] ?? null) !== "https://login.microsoftonline.com/{$tid}/v2.0") {
            throw new MicrosoftSsoLoginException(
                __('Your Microsoft sign-in could not be verified. Please try again.'),
                'issuer_mismatch',
                $teamId,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function assertAudience(array $claims, TeamIdentityProvider $identityProvider): void
    {
        if (($claims['aud'] ?? null) !== $identityProvider->client_id) {
            throw new MicrosoftSsoLoginException(
                __('Your Microsoft sign-in could not be verified. Please try again.'),
                'audience_mismatch',
                $identityProvider->team_id,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function assertNonce(array $claims, string $expectedNonce, int $teamId): void
    {
        if (! hash_equals($expectedNonce, (string) ($claims['nonce'] ?? ''))) {
            throw new MicrosoftSsoLoginException(
                __('Your Microsoft sign-in request has expired. Please try signing in again.'),
                'nonce_mismatch',
                $teamId,
            );
        }
    }

    /**
     * Reject a token from a tenant other than the one this organization is
     * pinned to. Skipped only when the organization deliberately configured
     * a multi-tenant identifier (`common`, `organizations`, `consumers`),
     * since there is then no single tenant to compare against.
     *
     * @param  array<string, mixed>  $claims
     */
    private function assertTenant(array $claims, TeamIdentityProvider $identityProvider): void
    {
        $tenant = $identityProvider->tenant_id;

        if (in_array($tenant, TeamIdentityProvider::MULTI_TENANT_IDENTIFIERS, true)) {
            return;
        }

        if (($claims['tid'] ?? null) !== $tenant) {
            throw new MicrosoftSsoLoginException(
                __('Your Microsoft account belongs to a different organization and cannot sign in here.'),
                'tenant_mismatch',
                $identityProvider->team_id,
            );
        }
    }
}
