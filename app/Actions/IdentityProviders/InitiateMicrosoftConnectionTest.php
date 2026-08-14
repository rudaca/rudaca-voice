<?php

namespace App\Actions\IdentityProviders;

use App\Exceptions\MicrosoftSsoLoginException;
use App\Models\TeamIdentityProvider;
use App\Models\User;
use App\Services\Microsoft\MicrosoftOAuthClientFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class InitiateMicrosoftConnectionTest
{
    public function __construct(private readonly MicrosoftOAuthClientFactory $clientFactory) {}

    /**
     * Start a Microsoft connection test for this organization's saved
     * configuration, returning the authorization URL to redirect to.
     *
     * Unlike InitiateMicrosoftLogin, this only requires the configuration to
     * be complete — not enabled — so an admin can prove connectivity before
     * turning sign-in on. The initiating admin's id is stashed alongside the
     * state so the callback can refuse to complete the test for anyone else,
     * and the cache key is namespaced separately from a real login's state
     * (`oidc_test_state:` vs `oidc_state:`) so MicrosoftCallbackController
     * can tell the two flows apart without touching the login path at all.
     */
    public function handle(TeamIdentityProvider $identityProvider, User $admin): string
    {
        if (! $identityProvider->isConfigurable()) {
            throw new MicrosoftSsoLoginException(
                __('Tenant ID, client ID, and a client secret are all required before testing the connection.'),
                'configuration_incomplete',
                $identityProvider->team_id,
            );
        }

        $provider = $this->clientFactory->make($identityProvider);
        $nonce = Str::random(32);

        $authorizationUrl = $provider->getAuthorizationUrl([
            'nonce' => $nonce,
            'scope' => ['openid', 'profile', 'email'],
            'response_mode' => 'query',
        ]);

        Cache::put(
            "oidc_test_state:{$provider->getState()}",
            [
                'team_id' => $identityProvider->team_id,
                'identity_provider_id' => $identityProvider->id,
                'nonce' => $nonce,
                'code_verifier' => $provider->getPkceCode(),
                'initiated_by' => $admin->id,
            ],
            now()->addMinutes(10),
        );

        return $authorizationUrl;
    }
}
