<?php

use App\Enums\IdentityProviderAuditAction;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamIdentityProvider;
use App\Models\TeamIdentityProviderAudit;
use App\Models\User;
use App\Services\Microsoft\MicrosoftOAuthClientFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Support\MicrosoftOidcFixture;

/**
 * Start a Microsoft sign-in for the given organization and return the
 * server-side state stashed by InitiateMicrosoftLogin, reading it straight
 * out of the cache the same way the callback will.
 *
 * @return array{state: string, nonce: string, code_verifier: string}
 */
function startMicrosoftLogin(Team $team): array
{
    $response = test()->get(route('org.login.microsoft', $team));
    $location = $response->headers->get('Location');

    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    $state = $query['state'];
    // Cache::get is non-destructive — only the callback's Cache::pull()
    // consumes the entry, so it's still there when the test drives the
    // callback itself.
    $stored = Cache::get("oidc_state:{$state}");

    return ['state' => $state, 'nonce' => $stored['nonce'], 'code_verifier' => $stored['code_verifier']];
}

function bindMicrosoftTokenExchange(MicrosoftOidcFixture $fixture, string $idToken): void
{
    app()->instance(
        MicrosoftOAuthClientFactory::class,
        new MicrosoftOAuthClientFactory($fixture->tokenExchangeClient($idToken)),
    );
}

test('the redirect route builds an authorization url scoped to the correct organization', function () {
    $team = Team::factory()->create();
    $provider = TeamIdentityProvider::factory()->enabled()->create(['team_id' => $team->id]);

    $response = $this->get(route('org.login.microsoft', $team));

    $location = $response->headers->get('Location');

    expect($location)
        ->toContain("login.microsoftonline.com/{$provider->tenant_id}/oauth2/v2.0/authorize")
        ->toContain("client_id={$provider->client_id}")
        ->and($location)->not->toContain($provider->client_secret_encrypted);
});

test('the redirect route rejects a disabled or unconfigured provider server-side', function () {
    $team = Team::factory()->create();

    $response = $this->get(route('org.login.microsoft', $team));

    $response->assertRedirect(route('org.login', $team));
    $this->assertGuest();
});

test('a valid callback authenticates an existing organization member into the correct team', function () {
    ['team' => $team, 'user' => $member] = teamWithMember(TeamRole::Employee);
    $provider = TeamIdentityProvider::factory()->enabled()->create(['team_id' => $team->id]);

    $flow = startMicrosoftLogin($team);
    $fixture = new MicrosoftOidcFixture;

    $idToken = $fixture->idToken(MicrosoftOidcFixture::baseClaims([
        'tid' => $provider->tenant_id,
        'aud' => $provider->client_id,
        'nonce' => $flow['nonce'],
        'email' => $member->email,
        'name' => $member->name,
    ]));

    Http::fake(["login.microsoftonline.com/{$provider->tenant_id}/discovery/v2.0/keys" => Http::response($fixture->jwks())]);
    bindMicrosoftTokenExchange($fixture, $idToken);

    $this->get(route('org.login', $team));
    $sessionIdBeforeLogin = session()->getId();

    $response = $this->get(route('auth.microsoft.callback', ['code' => 'test-code', 'state' => $flow['state']]));

    $response->assertRedirect(route('dashboard', ['current_team' => $team->slug]));
    $this->assertAuthenticatedAs($member);
    expect(session()->getId())->not->toBe($sessionIdBeforeLogin);
    expect($member->fresh()->currentTeam->id)->toBe($team->id);

    expect(TeamIdentityProviderAudit::where('team_id', $team->id)->pluck('action')->map(fn ($action) => $action->value)->all())
        ->toBe([IdentityProviderAuditAction::LoginSucceeded->value]);
});

test('a valid callback provisions a new user when auto-provisioning is enabled', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);
    $provider = TeamIdentityProvider::factory()->enabled()->create([
        'team_id' => $team->id,
        'auto_provision_users' => true,
        'default_role' => TeamRole::Employee,
        'allowed_domains' => [],
    ]);

    $flow = startMicrosoftLogin($team);
    $fixture = new MicrosoftOidcFixture;
    $newEmail = 'new.member@'.fake()->domainName();

    $idToken = $fixture->idToken(MicrosoftOidcFixture::baseClaims([
        'tid' => $provider->tenant_id,
        'aud' => $provider->client_id,
        'nonce' => $flow['nonce'],
        'email' => $newEmail,
        'name' => 'New Member',
    ]));

    Http::fake(["login.microsoftonline.com/{$provider->tenant_id}/discovery/v2.0/keys" => Http::response($fixture->jwks())]);
    bindMicrosoftTokenExchange($fixture, $idToken);

    $response = $this->get(route('auth.microsoft.callback', ['code' => 'test-code', 'state' => $flow['state']]));

    $response->assertRedirect(route('dashboard', ['current_team' => $team->slug]));

    $newUser = User::where('email', $newEmail)->firstOrFail();
    $this->assertAuthenticatedAs($newUser);
    expect($newUser->teamRole($team))->toBe(TeamRole::Employee);

    expect(TeamIdentityProviderAudit::where('team_id', $team->id)->pluck('action')->map(fn ($action) => $action->value)->all())
        ->toBe([IdentityProviderAuditAction::UserProvisioned->value, IdentityProviderAuditAction::LoginSucceeded->value]);
});

test('provisioning-disabled organizations reject an unrecognized microsoft account', function () {
    $team = Team::factory()->create();
    $provider = TeamIdentityProvider::factory()->enabled()->create([
        'team_id' => $team->id,
        'auto_provision_users' => false,
    ]);

    $flow = startMicrosoftLogin($team);
    $fixture = new MicrosoftOidcFixture;

    $idToken = $fixture->idToken(MicrosoftOidcFixture::baseClaims([
        'tid' => $provider->tenant_id,
        'aud' => $provider->client_id,
        'nonce' => $flow['nonce'],
        'email' => 'nobody@example.com',
    ]));

    Http::fake(["login.microsoftonline.com/{$provider->tenant_id}/discovery/v2.0/keys" => Http::response($fixture->jwks())]);
    bindMicrosoftTokenExchange($fixture, $idToken);

    $response = $this->get(route('auth.microsoft.callback', ['code' => 'test-code', 'state' => $flow['state']]));

    $response->assertRedirect(route('org.login', $team));
    $response->assertSessionHas('error');
    $this->assertGuest();
    expect(User::where('email', 'nobody@example.com')->exists())->toBeFalse();

    $audit = TeamIdentityProviderAudit::where('team_id', $team->id)->latest('id')->first();
    expect($audit->action)->toBe(IdentityProviderAuditAction::LoginFailed)
        ->and($audit->changed_fields)->toBe(['unauthorized_account']);
});

test('a missing or unknown state is rejected without ever resolving an organization', function () {
    Log::spy();

    $response = $this->get(route('auth.microsoft.callback', ['code' => 'test-code', 'state' => 'not-a-real-state']));

    $response->assertRedirect(route('login'));
    $this->assertGuest();

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(function (string $message, array $context) {
            $encoded = json_encode($context);

            return $message === 'microsoft_sso_login_failed'
                && $context['reason'] === 'invalid_state'
                && ! str_contains($encoded, 'test-code');
        });
});

test('a state can only be used once', function () {
    ['team' => $team, 'user' => $member] = teamWithMember(TeamRole::Employee);
    $provider = TeamIdentityProvider::factory()->enabled()->create(['team_id' => $team->id]);

    $flow = startMicrosoftLogin($team);
    $fixture = new MicrosoftOidcFixture;

    $idToken = $fixture->idToken(MicrosoftOidcFixture::baseClaims([
        'tid' => $provider->tenant_id,
        'aud' => $provider->client_id,
        'nonce' => $flow['nonce'],
        'email' => $member->email,
    ]));

    Http::fake(["login.microsoftonline.com/{$provider->tenant_id}/discovery/v2.0/keys" => Http::response($fixture->jwks())]);
    bindMicrosoftTokenExchange($fixture, $idToken);

    $this->get(route('auth.microsoft.callback', ['code' => 'test-code', 'state' => $flow['state']]))
        ->assertRedirect(route('dashboard', ['current_team' => $team->slug]));

    auth()->logout();
    $this->app['session']->flush();

    $replay = $this->get(route('auth.microsoft.callback', ['code' => 'test-code', 'state' => $flow['state']]));

    $replay->assertRedirect(route('login'));
    $this->assertGuest();
});

test('a nonce that does not match the one issued for this flow is rejected', function () {
    ['team' => $team, 'user' => $member] = teamWithMember(TeamRole::Employee);
    $provider = TeamIdentityProvider::factory()->enabled()->create(['team_id' => $team->id]);

    $flow = startMicrosoftLogin($team);
    $fixture = new MicrosoftOidcFixture;

    $idToken = $fixture->idToken(MicrosoftOidcFixture::baseClaims([
        'tid' => $provider->tenant_id,
        'aud' => $provider->client_id,
        'nonce' => 'a-completely-different-nonce',
        'email' => $member->email,
    ]));

    Http::fake(["login.microsoftonline.com/{$provider->tenant_id}/discovery/v2.0/keys" => Http::response($fixture->jwks())]);
    bindMicrosoftTokenExchange($fixture, $idToken);

    $response = $this->get(route('auth.microsoft.callback', ['code' => 'test-code', 'state' => $flow['state']]));

    $response->assertRedirect(route('org.login', $team));
    $this->assertGuest();

    expect(TeamIdentityProviderAudit::where('team_id', $team->id)->latest('id')->first()->changed_fields)
        ->toBe(['nonce_mismatch']);
});

test('a token signed by a key not in the tenant jwks is rejected', function () {
    ['team' => $team, 'user' => $member] = teamWithMember(TeamRole::Employee);
    $provider = TeamIdentityProvider::factory()->enabled()->create(['team_id' => $team->id]);

    $flow = startMicrosoftLogin($team);

    $signingFixture = new MicrosoftOidcFixture; // signs the token
    $publishedFixture = MicrosoftOidcFixture::alternate(); // its jwks is served instead — key mismatch

    $idToken = $signingFixture->idToken(MicrosoftOidcFixture::baseClaims([
        'tid' => $provider->tenant_id,
        'aud' => $provider->client_id,
        'nonce' => $flow['nonce'],
        'email' => $member->email,
    ]));

    Http::fake(["login.microsoftonline.com/{$provider->tenant_id}/discovery/v2.0/keys" => Http::response($publishedFixture->jwks())]);
    bindMicrosoftTokenExchange($signingFixture, $idToken);

    $response = $this->get(route('auth.microsoft.callback', ['code' => 'test-code', 'state' => $flow['state']]));

    $response->assertRedirect(route('org.login', $team));
    $this->assertGuest();

    expect(TeamIdentityProviderAudit::where('team_id', $team->id)->latest('id')->first()->changed_fields)
        ->toBe(['signature_invalid']);
});

test('a token from a different tenant than the one this organization is pinned to is rejected', function () {
    ['team' => $team, 'user' => $member] = teamWithMember(TeamRole::Employee);
    $provider = TeamIdentityProvider::factory()->enabled()->create(['team_id' => $team->id]);

    $flow = startMicrosoftLogin($team);
    $fixture = new MicrosoftOidcFixture;
    $foreignTenant = (string) Str::uuid();

    $idToken = $fixture->idToken(MicrosoftOidcFixture::baseClaims([
        'tid' => $foreignTenant,
        'aud' => $provider->client_id,
        'nonce' => $flow['nonce'],
        'email' => $member->email,
    ]));

    // The org's own tenant discovery endpoint is what gets queried (the
    // callback always uses the organization's *configured* tenant, never
    // one taken from the token), so the fake is keyed on that tenant even
    // though the token itself claims to be from a different one.
    Http::fake(["login.microsoftonline.com/{$provider->tenant_id}/discovery/v2.0/keys" => Http::response($fixture->jwks())]);
    bindMicrosoftTokenExchange($fixture, $idToken);

    $response = $this->get(route('auth.microsoft.callback', ['code' => 'test-code', 'state' => $flow['state']]));

    $response->assertRedirect(route('org.login', $team));
    $this->assertGuest();

    expect(TeamIdentityProviderAudit::where('team_id', $team->id)->latest('id')->first()->changed_fields)
        ->toBe(['tenant_mismatch']);
});

test('an org login initiated by one organization cannot be completed into another', function () {
    ['team' => $teamA] = teamWithMember(TeamRole::Employee);
    ['team' => $teamB, 'user' => $memberB] = teamWithMember(TeamRole::Employee);

    $providerA = TeamIdentityProvider::factory()->enabled()->create(['team_id' => $teamA->id]);
    TeamIdentityProvider::factory()->enabled()->create(['team_id' => $teamB->id]);

    $flow = startMicrosoftLogin($teamA);
    $fixture = new MicrosoftOidcFixture;

    // A token that is perfectly valid for team B is presented against the
    // state that team A's flow generated.
    $idToken = $fixture->idToken(MicrosoftOidcFixture::baseClaims([
        'tid' => $providerA->tenant_id,
        'aud' => $providerA->client_id,
        'nonce' => $flow['nonce'],
        'email' => $memberB->email,
    ]));

    Http::fake(["login.microsoftonline.com/{$providerA->tenant_id}/discovery/v2.0/keys" => Http::response($fixture->jwks())]);
    bindMicrosoftTokenExchange($fixture, $idToken);

    // Tampering with an unrelated client-supplied parameter has no effect —
    // the organization is resolved solely from the signed server-side state.
    $response = $this->get(route('auth.microsoft.callback', [
        'code' => 'test-code',
        'state' => $flow['state'],
        'team' => $teamB->slug,
        'redirect' => 'https://evil.example.com',
    ]));

    // memberB has no account in team A, and auto-provisioning is off by
    // default, so the attempt is rejected rather than silently landing in
    // team A or leaking into team B.
    $response->assertRedirect(route('org.login', $teamA));
    $this->assertGuest();
    expect($response->headers->get('Location'))->not->toContain('evil.example.com');
});

test('a client-supplied redirect parameter can never override the post-login destination', function () {
    ['team' => $team, 'user' => $member] = teamWithMember(TeamRole::Employee);
    $provider = TeamIdentityProvider::factory()->enabled()->create(['team_id' => $team->id]);

    $flow = startMicrosoftLogin($team);
    $fixture = new MicrosoftOidcFixture;

    $idToken = $fixture->idToken(MicrosoftOidcFixture::baseClaims([
        'tid' => $provider->tenant_id,
        'aud' => $provider->client_id,
        'nonce' => $flow['nonce'],
        'email' => $member->email,
    ]));

    Http::fake(["login.microsoftonline.com/{$provider->tenant_id}/discovery/v2.0/keys" => Http::response($fixture->jwks())]);
    bindMicrosoftTokenExchange($fixture, $idToken);

    $response = $this->get(route('auth.microsoft.callback', [
        'code' => 'test-code',
        'state' => $flow['state'],
        'redirect' => 'https://evil.example.com',
        'next' => '//evil.example.com',
    ]));

    $response->assertRedirect(route('dashboard', ['current_team' => $team->slug]));
});
