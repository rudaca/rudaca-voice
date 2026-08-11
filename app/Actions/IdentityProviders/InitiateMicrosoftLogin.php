<?php

namespace App\Actions\IdentityProviders;

use App\Enums\IdentityProvider;
use App\Exceptions\MicrosoftSsoLoginException;
use App\Models\Team;
use App\Services\Microsoft\MicrosoftOAuthClientFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class InitiateMicrosoftLogin
{
    public function __construct(private readonly MicrosoftOAuthClientFactory $clientFactory) {}

    /**
     * Start a Microsoft sign-in for this organization, returning the
     * authorization URL to redirect the browser to.
     *
     * Re-checks the provider is enabled and fully configured server-side —
     * the caller may only have UI-hidden the button, never actually gated
     * access — and stores the state/nonce/PKCE verifier server-side, keyed
     * by the state value, so the callback can be tied back to this
     * organization without trusting anything the client sends.
     */
    public function handle(Team $team): string
    {
        $identityProvider = $team->identityProviderFor(IdentityProvider::Microsoft);

        if (! $identityProvider || ! $identityProvider->enabled || ! $identityProvider->isConfigurable()) {
            throw new MicrosoftSsoLoginException(
                __('Microsoft sign-in is not available for this organization.'),
                'provider_disabled',
                $team->id,
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
            "oidc_state:{$provider->getState()}",
            [
                'team_id' => $team->id,
                'nonce' => $nonce,
                'code_verifier' => $provider->getPkceCode(),
            ],
            now()->addMinutes(10),
        );

        return $authorizationUrl;
    }
}
