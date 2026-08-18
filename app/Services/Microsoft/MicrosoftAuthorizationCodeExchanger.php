<?php

namespace App\Services\Microsoft;

use App\Exceptions\MicrosoftSsoLoginException;
use App\Models\TeamIdentityProvider;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;

/**
 * Exchanges an OIDC authorization code for the raw ID token, shared by both
 * the real sign-in flow and the connection test — both need identical
 * failure semantics, and neither ever calls the resource owner (userinfo)
 * endpoint since every claim needed already lives in the ID token.
 */
class MicrosoftAuthorizationCodeExchanger
{
    public function __construct(private readonly MicrosoftOAuthClientFactory $clientFactory) {}

    /**
     * @throws MicrosoftSsoLoginException if the exchange fails or the response carries no ID token.
     */
    public function exchange(TeamIdentityProvider $identityProvider, string $code, string $codeVerifier): string
    {
        $provider = $this->clientFactory->make($identityProvider);
        $provider->setPkceCode($codeVerifier);

        try {
            $accessToken = $provider->getAccessToken('authorization_code', ['code' => $code]);
        } catch (IdentityProviderException $e) {
            $response = $e->getResponseBody();

            throw new MicrosoftSsoLoginException(
                __('Your Microsoft sign-in could not be completed. Please try again.'),
                'token_exchange_failed',
                $identityProvider->team_id,
                [
                    'provider_error' => $e->getMessage(),
                    'provider_error_description' => is_array($response) ? (string) ($response['error_description'] ?? '') : '',
                ],
            );
        }

        $idToken = $accessToken->getValues()['id_token'] ?? null;

        if (! is_string($idToken) || blank($idToken)) {
            throw new MicrosoftSsoLoginException(
                __('Your Microsoft sign-in could not be completed. Please try again.'),
                'missing_id_token',
                $identityProvider->team_id,
            );
        }

        return $idToken;
    }
}
