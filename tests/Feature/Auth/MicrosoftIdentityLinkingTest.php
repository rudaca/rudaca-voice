<?php

use App\Enums\TeamRole;
use App\Enums\UserIdentityAccountAuditAction;
use App\Models\Team;
use App\Models\TeamIdentityProvider;
use App\Models\User;
use App\Models\UserIdentityAccount;
use App\Models\UserIdentityAccountAudit;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Support\MicrosoftOidcFixture;

test('a first successful login creates an identity link and audit record', function () {
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
        'oid' => 'subject-1',
    ]));

    Http::fake(["login.microsoftonline.com/{$provider->tenant_id}/discovery/v2.0/keys" => Http::response($fixture->jwks())]);
    bindMicrosoftTokenExchange($fixture, $idToken);

    $this->get(route('auth.microsoft.callback', ['state' => $flow['state'], 'code' => 'auth-code']));

    $link = UserIdentityAccount::sole();

    expect($link->user_id)->toBe($member->id)
        ->and($link->team_id)->toBe($team->id)
        ->and($link->provider_tenant_id)->toBe($provider->tenant_id)
        ->and($link->provider_subject_id)->toBe('subject-1')
        ->and($link->email_at_link_time)->toBe($member->email)
        ->and($link->last_login_at)->not->toBeNull();

    $audit = UserIdentityAccountAudit::sole();

    expect($audit->action)->toBe(UserIdentityAccountAuditAction::Linked)
        ->and($audit->user_identity_account_id)->toBe($link->id)
        ->and($audit->performed_by_user_id)->toBe($member->id);
});

test('a second login with the same tenant and subject authenticates via the link even if the email changed', function () {
    ['team' => $team, 'user' => $member] = teamWithMember(TeamRole::Employee);
    $provider = TeamIdentityProvider::factory()->enabled()->create(['team_id' => $team->id]);

    $link = UserIdentityAccount::factory()->create([
        'user_id' => $member->id,
        'team_id' => $team->id,
        'provider_tenant_id' => $provider->tenant_id,
        'provider_subject_id' => 'subject-1',
        'email_at_link_time' => $member->email,
    ]);

    $flow = startMicrosoftLogin($team);
    $fixture = new MicrosoftOidcFixture;

    $idToken = $fixture->idToken(MicrosoftOidcFixture::baseClaims([
        'tid' => $provider->tenant_id,
        'aud' => $provider->client_id,
        'nonce' => $flow['nonce'],
        'email' => 'a-brand-new-address@example.com',
        'name' => $member->name,
        'oid' => 'subject-1',
    ]));

    Http::fake(["login.microsoftonline.com/{$provider->tenant_id}/discovery/v2.0/keys" => Http::response($fixture->jwks())]);
    bindMicrosoftTokenExchange($fixture, $idToken);

    $response = $this->get(route('auth.microsoft.callback', ['state' => $flow['state'], 'code' => 'auth-code']));

    assertMicrosoftBridgeTo($response, route('dashboard', ['current_team' => $team->slug]));
    $this->assertAuthenticatedAs($member);

    expect(User::count())->toBe(1)
        ->and(UserIdentityAccount::count())->toBe(1)
        ->and($link->fresh()->email_at_link_time)->toBe($member->email);
});

test('an identity linked to one user authenticates that user even if a different user now matches the email', function () {
    ['team' => $team, 'user' => $userA] = teamWithMember(TeamRole::Employee);
    $userB = User::factory()->create();
    $team->members()->attach($userB, ['role' => TeamRole::Employee->value]);

    $provider = TeamIdentityProvider::factory()->enabled()->create(['team_id' => $team->id]);

    UserIdentityAccount::factory()->create([
        'user_id' => $userB->id,
        'team_id' => $team->id,
        'provider_tenant_id' => $provider->tenant_id,
        'provider_subject_id' => 'subject-1',
    ]);

    $flow = startMicrosoftLogin($team);
    $fixture = new MicrosoftOidcFixture;

    $idToken = $fixture->idToken(MicrosoftOidcFixture::baseClaims([
        'tid' => $provider->tenant_id,
        'aud' => $provider->client_id,
        'nonce' => $flow['nonce'],
        'email' => $userA->email,
        'name' => $userA->name,
        'oid' => 'subject-1',
    ]));

    Http::fake(["login.microsoftonline.com/{$provider->tenant_id}/discovery/v2.0/keys" => Http::response($fixture->jwks())]);
    bindMicrosoftTokenExchange($fixture, $idToken);

    $this->get(route('auth.microsoft.callback', ['state' => $flow['state'], 'code' => 'auth-code']));

    $this->assertAuthenticatedAs($userB);
});

test('an identity linked under one organization is rejected when presented to another organization', function () {
    $teamA = Team::factory()->create();
    $userA = User::factory()->create();
    $teamA->members()->attach($userA, ['role' => TeamRole::Employee->value]);

    UserIdentityAccount::factory()->create([
        'user_id' => $userA->id,
        'team_id' => $teamA->id,
        'provider_tenant_id' => 'shared-tenant',
        'provider_subject_id' => 'subject-1',
    ]);

    ['team' => $teamB, 'user' => $userB] = teamWithMember(TeamRole::Employee);
    $providerB = TeamIdentityProvider::factory()->enabled()->create(['team_id' => $teamB->id, 'tenant_id' => 'shared-tenant']);

    $flow = startMicrosoftLogin($teamB);
    $fixture = new MicrosoftOidcFixture;

    $idToken = $fixture->idToken(MicrosoftOidcFixture::baseClaims([
        'tid' => 'shared-tenant',
        'aud' => $providerB->client_id,
        'nonce' => $flow['nonce'],
        'email' => $userB->email,
        'name' => $userB->name,
        'oid' => 'subject-1',
    ]));

    Http::fake(['login.microsoftonline.com/shared-tenant/discovery/v2.0/keys' => Http::response($fixture->jwks())]);
    bindMicrosoftTokenExchange($fixture, $idToken);

    $response = $this->get(route('auth.microsoft.callback', ['state' => $flow['state'], 'code' => 'auth-code']));

    assertMicrosoftBridgeTo($response, route('org.login', $teamB));
    $this->assertGuest();

    $failure = $providerB->audits()->latest('id')->first();
    expect($failure->action->value)->toBe('login failed')
        ->and($failure->changed_fields)->toBe(['identity_linked_to_other_organization']);

    expect(UserIdentityAccount::count())->toBe(1);
});

test('an ambiguous case-variant email match is rejected without creating a link', function () {
    // Only reproducible where the users.email unique index is case-sensitive
    // (SQLite's default). This app's MySQL connection uses utf8mb4_unicode_ci,
    // which rejects the second insert below as a duplicate before the scenario
    // can even be set up — meaning on MySQL the ambiguity this guards against
    // is already impossible at the schema level, and the guard exists purely
    // as defense in depth (e.g. against a different collation, or another
    // database driver).
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('Case-variant duplicate emails cannot exist under this connection\'s collation.');
    }

    ['team' => $team, 'user' => $member] = teamWithMember(TeamRole::Employee);
    $member->forceFill(['email' => 'person@example.com'])->save();

    $duplicate = User::factory()->create(['email' => 'Person@example.com']);
    $team->members()->attach($duplicate, ['role' => TeamRole::Employee->value]);

    $provider = TeamIdentityProvider::factory()->enabled()->create(['team_id' => $team->id]);

    $flow = startMicrosoftLogin($team);
    $fixture = new MicrosoftOidcFixture;

    $idToken = $fixture->idToken(MicrosoftOidcFixture::baseClaims([
        'tid' => $provider->tenant_id,
        'aud' => $provider->client_id,
        'nonce' => $flow['nonce'],
        'email' => 'PERSON@example.com',
        'name' => 'Person',
        'oid' => 'subject-1',
    ]));

    Http::fake(["login.microsoftonline.com/{$provider->tenant_id}/discovery/v2.0/keys" => Http::response($fixture->jwks())]);
    bindMicrosoftTokenExchange($fixture, $idToken);

    $response = $this->get(route('auth.microsoft.callback', ['state' => $flow['state'], 'code' => 'auth-code']));

    assertMicrosoftBridgeTo($response, route('org.login', $team));
    $this->assertGuest();

    $failure = $provider->audits()->latest('id')->first();
    expect($failure->changed_fields)->toBe(['ambiguous_email_match']);
    expect(UserIdentityAccount::count())->toBe(0);
});

test('an inactive user linked to a Microsoft identity is rejected', function () {
    ['team' => $team, 'user' => $member] = teamWithMember(TeamRole::Employee);
    $member->is_active = false;
    $member->save();

    $provider = TeamIdentityProvider::factory()->enabled()->create(['team_id' => $team->id]);

    UserIdentityAccount::factory()->create([
        'user_id' => $member->id,
        'team_id' => $team->id,
        'provider_tenant_id' => $provider->tenant_id,
        'provider_subject_id' => 'subject-1',
    ]);

    $flow = startMicrosoftLogin($team);
    $fixture = new MicrosoftOidcFixture;

    $idToken = $fixture->idToken(MicrosoftOidcFixture::baseClaims([
        'tid' => $provider->tenant_id,
        'aud' => $provider->client_id,
        'nonce' => $flow['nonce'],
        'email' => $member->email,
        'name' => $member->name,
        'oid' => 'subject-1',
    ]));

    Http::fake(["login.microsoftonline.com/{$provider->tenant_id}/discovery/v2.0/keys" => Http::response($fixture->jwks())]);
    bindMicrosoftTokenExchange($fixture, $idToken);

    $response = $this->get(route('auth.microsoft.callback', ['state' => $flow['state'], 'code' => 'auth-code']));

    assertMicrosoftBridgeTo($response, route('org.login', $team));
    $response->assertSessionHas('error');
    $this->assertGuest();
});

test('an inactive user matched by email is rejected before a link is created', function () {
    ['team' => $team, 'user' => $member] = teamWithMember(TeamRole::Employee);
    $member->is_active = false;
    $member->save();

    $provider = TeamIdentityProvider::factory()->enabled()->create(['team_id' => $team->id]);

    $flow = startMicrosoftLogin($team);
    $fixture = new MicrosoftOidcFixture;

    $idToken = $fixture->idToken(MicrosoftOidcFixture::baseClaims([
        'tid' => $provider->tenant_id,
        'aud' => $provider->client_id,
        'nonce' => $flow['nonce'],
        'email' => $member->email,
        'name' => $member->name,
        'oid' => 'subject-1',
    ]));

    Http::fake(["login.microsoftonline.com/{$provider->tenant_id}/discovery/v2.0/keys" => Http::response($fixture->jwks())]);
    bindMicrosoftTokenExchange($fixture, $idToken);

    $response = $this->get(route('auth.microsoft.callback', ['state' => $flow['state'], 'code' => 'auth-code']));

    assertMicrosoftBridgeTo($response, route('org.login', $team));
    $this->assertGuest();
    expect(UserIdentityAccount::count())->toBe(0);
});

test('the same external identity cannot be linked to two users at the database level', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $team = Team::factory()->create();

    UserIdentityAccount::factory()->create([
        'user_id' => $userA->id,
        'team_id' => $team->id,
        'provider_tenant_id' => 'tenant-1',
        'provider_subject_id' => 'subject-1',
    ]);

    expect(fn () => UserIdentityAccount::factory()->create([
        'user_id' => $userB->id,
        'team_id' => $team->id,
        'provider_tenant_id' => 'tenant-1',
        'provider_subject_id' => 'subject-1',
    ]))->toThrow(QueryException::class);
});

test('the identity accounts table never stores tokens or secrets', function () {
    $columns = Schema::getColumnListing('user_identity_accounts');

    expect($columns)->not->toContain('access_token')
        ->and($columns)->not->toContain('refresh_token')
        ->and($columns)->not->toContain('id_token')
        ->and($columns)->not->toContain('client_secret');

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
        'oid' => 'subject-1',
    ]));

    Http::fake(["login.microsoftonline.com/{$provider->tenant_id}/discovery/v2.0/keys" => Http::response($fixture->jwks())]);
    bindMicrosoftTokenExchange($fixture, $idToken);

    $this->get(route('auth.microsoft.callback', ['state' => $flow['state'], 'code' => 'auth-code']));

    $link = UserIdentityAccount::sole();

    expect($link->getAttributes())->not->toHaveKey('access_token')
        ->and($link->toArray())->not->toHaveKey('id_token');
});
