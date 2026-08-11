<?php

namespace App\Services\Microsoft;

use App\Enums\IdentityProvider;
use App\Models\TeamIdentityProvider;
use GuzzleHttp\ClientInterface;
use League\OAuth2\Client\Provider\GenericProvider;

/**
 * Builds a league/oauth2-client provider scoped to one organization's
 * Microsoft Entra tenant and app registration.
 *
 * Always reads the organization's *current* configuration — never a
 * snapshot — so an admin's mid-flow change (disabling, rotating the
 * secret) takes effect immediately rather than being silently bypassed.
 */
class MicrosoftOAuthClientFactory
{
    /**
     * `$httpClient` is injectable (and container-bindable) so tests can
     * supply a Guzzle client backed by a mock handler instead of reaching
     * the real Microsoft endpoints.
     */
    public function __construct(private readonly ?ClientInterface $httpClient = null) {}

    /**
     * Build a provider for the given organization's Microsoft configuration.
     */
    public function make(TeamIdentityProvider $identityProvider): GenericProvider
    {
        $tenant = $identityProvider->tenant_id;

        $options = [
            'clientId' => $identityProvider->client_id,
            'clientSecret' => $identityProvider->client_secret_encrypted,
            'redirectUri' => IdentityProvider::Microsoft->redirectUrl(),
            'urlAuthorize' => "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/authorize",
            'urlAccessToken' => "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token",
            // Never called — ID token claims are read directly instead — but
            // GenericProvider requires this option to be set regardless.
            'urlResourceOwnerDetails' => 'https://graph.microsoft.com/oidc/userinfo',
            'pkceMethod' => GenericProvider::PKCE_METHOD_S256,
        ];

        $collaborators = $this->httpClient ? ['httpClient' => $this->httpClient] : [];

        return new GenericProvider($options, $collaborators);
    }
}
