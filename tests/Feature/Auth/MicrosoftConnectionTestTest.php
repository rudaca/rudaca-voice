<?php

use App\Actions\IdentityProviders\InitiateMicrosoftConnectionTest;
use App\Enums\IdentityProvider;
use App\Enums\IdentityProviderAuditAction;
use App\Enums\IdentityProviderConfigurationStatus;
use App\Enums\TeamRole;
use App\Exceptions\MicrosoftSsoLoginException;
use App\Models\TeamIdentityProvider;
use App\Models\TeamIdentityProviderAudit;
use App\Models\User;
use App\Services\Microsoft\MicrosoftOAuthClientFactory;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Tests\Support\MicrosoftOidcFixture;

function bindMicrosoftConnectionTestTokenExchange(MicrosoftOidcFixture $fixture, string $idToken): void
{
    app()->instance(
        MicrosoftOAuthClientFactory::class,
        new MicrosoftOAuthClientFactory($fixture->tokenExchangeClient($idToken)),
    );
}

/**
 * Start a connection test for the given configuration and return the state
 * value stashed by InitiateMicrosoftConnectionTest, reading it out of the
 * authorization URL the same way the callback controller will read it from
 * the request's query string.
 */
function startMicrosoftConnectionTest(TeamIdentityProvider $identityProvider, User $admin): string
{
    $authorizationUrl = app(InitiateMicrosoftConnectionTest::class)->handle($identityProvider, $admin);

    parse_str((string) parse_url($authorizationUrl, PHP_URL_QUERY), $query);

    return $query['state'];
}

test('the redirect URI is derived from the current application URL', function () {
    expect(IdentityProvider::Microsoft->redirectUrl())->toBe(url('/auth/microsoft/callback'));
});

test('the redirect URI never varies by organization and carries no org-specific segment', function () {
    ['team' => $teamA] = teamWithMember(TeamRole::Owner);
    ['team' => $teamB] = teamWithMember(TeamRole::Owner);

    $url = IdentityProvider::Microsoft->redirectUrl();

    expect($url)
        ->not->toContain((string) $teamA->id)
        ->not->toContain($teamA->slug)
        ->not->toContain((string) $teamB->id)
        ->not->toContain($teamB->slug);
});

test('the copyable redirect URI carries no secrets or query parameters', function () {
    $url = IdentityProvider::Microsoft->redirectUrl();

    expect($url)
        ->not->toContain('?')
        ->not->toContain('client_id')
        ->not->toContain('secret');
});

test('the connection test requires complete configuration before attempting anything', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);

    $incomplete = TeamIdentityProvider::factory()->create([
        'team_id' => $team->id,
        'tenant_id' => null,
    ]);

    Http::fake();

    expect(fn () => app(InitiateMicrosoftConnectionTest::class)->handle($incomplete, $owner))
        ->toThrow(MicrosoftSsoLoginException::class);

    Http::assertNothingSent();
});

test('the settings page blocks a connection test attempt when configuration is incomplete, without calling Microsoft', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);

    TeamIdentityProvider::factory()->create(['team_id' => $team->id, 'tenant_id' => null]);

    Http::fake();

    Livewire::actingAs($owner)
        ->test('pages::ideas.authentication-settings', ['team' => $team])
        ->call('testConnection')
        ->assertNoRedirect();

    Http::assertNothingSent();
});

test('a successful connection test records the verification date and administrator', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);
    $provider = TeamIdentityProvider::factory()->enabled()->create(['team_id' => $team->id]);

    $state = startMicrosoftConnectionTest($provider, $owner);
    $stored = Cache::get("oidc_test_state:{$state}");

    $fixture = new MicrosoftOidcFixture;
    $idToken = $fixture->idToken(MicrosoftOidcFixture::baseClaims([
        'tid' => $provider->tenant_id,
        'aud' => $provider->client_id,
        'nonce' => $stored['nonce'],
    ]));

    Http::fake(["login.microsoftonline.com/{$provider->tenant_id}/discovery/v2.0/keys" => Http::response($fixture->jwks())]);
    bindMicrosoftConnectionTestTokenExchange($fixture, $idToken);

    $this->actingAs($owner);

    $response = $this->get(route('auth.microsoft.callback', ['code' => 'test-code', 'state' => $state]));

    assertMicrosoftBridgeTo($response, route('ideas.settings', ['current_team' => $team->slug, 'tab' => 'authentication']));

    // Still authenticated as themself — the test never signs anyone in or
    // switches their team, so there is nothing left over to clean up.
    $this->assertAuthenticatedAs($owner);

    $provider->refresh();
    expect($provider->verified_at)->not->toBeNull()
        ->and($provider->verified_by)->toBe($owner->id)
        ->and($provider->isVerified())->toBeTrue()
        ->and($provider->configurationStatus())->toBe(IdentityProviderConfigurationStatus::Verified);

    $audit = TeamIdentityProviderAudit::where('team_id', $team->id)->latest('id')->first();
    expect($audit->action)->toBe(IdentityProviderAuditAction::ConnectionTestSucceeded)
        ->and($audit->performed_by_user_id)->toBe($owner->id);
});

test('a tenant mismatch fails the connection test and leaves the organization unverified', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);
    $provider = TeamIdentityProvider::factory()->enabled()->create(['team_id' => $team->id]);

    $state = startMicrosoftConnectionTest($provider, $owner);
    $stored = Cache::get("oidc_test_state:{$state}");

    $fixture = new MicrosoftOidcFixture;
    $idToken = $fixture->idToken(MicrosoftOidcFixture::baseClaims([
        'tid' => (string) fake()->uuid(), // deliberately does not match $provider->tenant_id
        'aud' => $provider->client_id,
        'nonce' => $stored['nonce'],
    ]));

    Http::fake(['login.microsoftonline.com/*' => Http::response($fixture->jwks())]);
    bindMicrosoftConnectionTestTokenExchange($fixture, $idToken);

    $this->actingAs($owner);
    $response = $this->get(route('auth.microsoft.callback', ['code' => 'test-code', 'state' => $state]));

    assertMicrosoftBridgeTo($response, route('ideas.settings', ['current_team' => $team->slug, 'tab' => 'authentication']));
    expect(session('microsoft_test_status'))->toBe('error');

    $provider->refresh();
    expect($provider->verified_at)->toBeNull()
        ->and($provider->last_test_failed_at)->not->toBeNull()
        ->and($provider->configurationStatus())->toBe(IdentityProviderConfigurationStatus::ConfigurationError);

    $audit = TeamIdentityProviderAudit::where('team_id', $team->id)->latest('id')->first();
    expect($audit->action)->toBe(IdentityProviderAuditAction::ConnectionTestFailed)
        ->and($audit->changed_fields)->toBe(['tenant_mismatch']);
});

test('a non-admin cannot run the connection test', function () {
    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);
    TeamIdentityProvider::factory()->create(['team_id' => $team->id]);

    Livewire::actingAs($manager)
        ->test('pages::ideas.authentication-settings', ['team' => $team])
        ->assertForbidden();
});

test('a failed connection test never surfaces a raw provider error', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);
    $provider = TeamIdentityProvider::factory()->enabled()->create(['team_id' => $team->id]);

    $state = startMicrosoftConnectionTest($provider, $owner);

    $sensitiveBody = json_encode([
        'error' => 'invalid_client',
        'error_description' => 'AADSTS7000215: Invalid client secret provided. super-sensitive-internal-detail',
    ]);
    $mock = new MockHandler([new Response(400, ['Content-Type' => 'application/json'], $sensitiveBody)]);
    app()->instance(MicrosoftOAuthClientFactory::class, new MicrosoftOAuthClientFactory(new Client(['handler' => HandlerStack::create($mock)])));

    $this->actingAs($owner);
    $response = $this->get(route('auth.microsoft.callback', ['code' => 'test-code', 'state' => $state]));

    assertMicrosoftBridgeTo($response, route('ideas.settings', ['current_team' => $team->slug, 'tab' => 'authentication']));

    expect(session('microsoft_test_message'))
        ->not->toContain('AADSTS7000215')
        ->not->toContain('super-sensitive-internal-detail')
        ->not->toContain('invalid_client');

    $provider->refresh();
    expect($provider->last_test_failure_message)
        ->not->toContain('AADSTS7000215')
        ->not->toContain('super-sensitive-internal-detail');
});

test('Microsoft reporting an error during a connection test is logged with its actual reason, not just a generic one', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);
    $provider = TeamIdentityProvider::factory()->enabled()->create(['team_id' => $team->id]);

    $state = startMicrosoftConnectionTest($provider, $owner);

    Log::spy();

    $this->actingAs($owner);
    $response = $this->get(route('auth.microsoft.callback', [
        'state' => $state,
        'error' => 'access_denied',
        'error_description' => 'AADSTS65004: User declined to consent to access the app.',
    ]));

    assertMicrosoftBridgeTo($response, route('ideas.settings', ['current_team' => $team->slug, 'tab' => 'authentication']));

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context) => $message === 'microsoft_connection_test_failed'
            && $context['reason'] === 'provider_error'
            && $context['provider_error'] === 'access_denied'
            && str_contains($context['provider_error_description'], 'AADSTS65004'));
});

test('a connection test verifying one organization has no effect on another\'s status', function () {
    ['team' => $teamA, 'user' => $ownerA] = teamWithMember(TeamRole::Owner);
    ['team' => $teamB] = teamWithMember(TeamRole::Owner);

    $providerA = TeamIdentityProvider::factory()->enabled()->create(['team_id' => $teamA->id]);
    $providerB = TeamIdentityProvider::factory()->enabled()->create(['team_id' => $teamB->id]);

    $state = startMicrosoftConnectionTest($providerA, $ownerA);
    $stored = Cache::get("oidc_test_state:{$state}");

    $fixture = new MicrosoftOidcFixture;
    $idToken = $fixture->idToken(MicrosoftOidcFixture::baseClaims([
        'tid' => $providerA->tenant_id,
        'aud' => $providerA->client_id,
        'nonce' => $stored['nonce'],
    ]));

    Http::fake(["login.microsoftonline.com/{$providerA->tenant_id}/discovery/v2.0/keys" => Http::response($fixture->jwks())]);
    bindMicrosoftConnectionTestTokenExchange($fixture, $idToken);

    $this->actingAs($ownerA);
    $this->get(route('auth.microsoft.callback', ['code' => 'test-code', 'state' => $state]));

    expect($providerA->fresh()->verified_at)->not->toBeNull()
        ->and($providerB->fresh()->verified_at)->toBeNull()
        ->and($providerB->fresh()->configurationStatus())->not->toBe(IdentityProviderConfigurationStatus::Verified);
});
