<?php

use App\Actions\IdentityProviders\DisableTeamIdentityProvider;
use App\Actions\IdentityProviders\DisconnectTeamIdentityProvider;
use App\Actions\IdentityProviders\EnableTeamIdentityProvider;
use App\Actions\IdentityProviders\SaveTeamIdentityProvider;
use App\Enums\IdentityProvider;
use App\Enums\IdentityProviderAuditAction;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamIdentityProvider;
use App\Models\TeamIdentityProviderAudit;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

test('a new organization has no identity provider configuration and remains unaffected by this feature', function () {
    $team = Team::factory()->create();

    expect($team->identityProviderFor(IdentityProvider::Microsoft))->toBeNull();
});

test('the client secret is stored encrypted at rest, not merely obscured', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);

    $identityProvider = app(SaveTeamIdentityProvider::class)->handle($team, $owner, IdentityProvider::Microsoft, [
        'tenant_id' => (string) fake()->uuid(),
        'client_id' => (string) fake()->uuid(),
        'client_secret' => 'super-secret-value',
    ]);

    $rawValue = DB::table('team_identity_providers')->where('id', $identityProvider->id)->value('client_secret_encrypted');

    expect($rawValue)->not->toBe('super-secret-value')
        ->and($rawValue)->not->toContain('super-secret-value')
        ->and(Crypt::decryptString($rawValue))->toBe('super-secret-value');
});

test('the client secret never appears in array or JSON serialization', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);

    $identityProvider = app(SaveTeamIdentityProvider::class)->handle($team, $owner, IdentityProvider::Microsoft, [
        'tenant_id' => (string) fake()->uuid(),
        'client_id' => (string) fake()->uuid(),
        'client_secret' => 'super-secret-value',
    ]);

    expect($identityProvider->toArray())->not->toHaveKey('client_secret_encrypted')
        ->and($identityProvider->toJson())->not->toContain('super-secret-value')
        ->and($identityProvider->toJson())->not->toContain('client_secret_encrypted');
});

test('a second configuration for the same team and provider violates the database unique constraint', function () {
    $team = Team::factory()->create();

    TeamIdentityProvider::factory()->create(['team_id' => $team->id, 'provider' => IdentityProvider::Microsoft]);

    expect(fn () => DB::table('team_identity_providers')->insert([
        'team_id' => $team->id,
        'provider' => IdentityProvider::Microsoft->value,
        'allowed_domains' => '[]',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('a configuration can be saved without a client secret as long as it is never enabled', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);

    $identityProvider = app(SaveTeamIdentityProvider::class)->handle($team, $owner, IdentityProvider::Microsoft, [
        'tenant_id' => (string) fake()->uuid(),
        'client_id' => (string) fake()->uuid(),
        'client_secret' => null,
    ]);

    expect($identityProvider->enabled)->toBeFalse()
        ->and($identityProvider->hasClientSecret())->toBeFalse();

    expect(fn () => app(EnableTeamIdentityProvider::class)->handle($identityProvider, $owner))
        ->toThrow(ValidationException::class);
});

test('updating a configuration without submitting a new secret preserves the previously saved secret', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);

    $saveAction = app(SaveTeamIdentityProvider::class);

    $identityProvider = $saveAction->handle($team, $owner, IdentityProvider::Microsoft, [
        'tenant_id' => (string) fake()->uuid(),
        'client_id' => (string) fake()->uuid(),
        'client_secret' => 'original-secret',
    ]);

    $updated = $saveAction->handle($team, $owner, IdentityProvider::Microsoft, [
        'tenant_id' => $identityProvider->tenant_id,
        'client_id' => $identityProvider->client_id,
        'client_secret' => null,
        'enforce_sso' => true,
    ]);

    expect($updated->id)->toBe($identityProvider->id)
        ->and($updated->client_secret_encrypted)->toBe('original-secret')
        ->and($updated->enforce_sso)->toBeTrue();
});

test('submitting a new secret on update replaces the previously saved secret', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);

    $saveAction = app(SaveTeamIdentityProvider::class);

    $identityProvider = $saveAction->handle($team, $owner, IdentityProvider::Microsoft, [
        'tenant_id' => (string) fake()->uuid(),
        'client_id' => (string) fake()->uuid(),
        'client_secret' => 'original-secret',
    ]);

    $updated = $saveAction->handle($team, $owner, IdentityProvider::Microsoft, [
        'tenant_id' => $identityProvider->tenant_id,
        'client_id' => $identityProvider->client_id,
        'client_secret' => 'replacement-secret',
    ]);

    expect($updated->client_secret_encrypted)->toBe('replacement-secret');
});

test('enabling requires tenant id, client id, and a stored secret to all be present', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);

    $incomplete = TeamIdentityProvider::factory()->create([
        'team_id' => $team->id,
        'provider' => IdentityProvider::Microsoft,
        'tenant_id' => null,
        'enabled' => false,
    ]);

    expect(fn () => app(EnableTeamIdentityProvider::class)->handle($incomplete, $owner))
        ->toThrow(ValidationException::class);

    expect($incomplete->fresh()->enabled)->toBeFalse();
});

test('enabling a fully configured provider turns it on and records who configured it', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);

    $identityProvider = TeamIdentityProvider::factory()->create([
        'team_id' => $team->id,
        'provider' => IdentityProvider::Microsoft,
        'enabled' => false,
    ]);

    $enabled = app(EnableTeamIdentityProvider::class)->handle($identityProvider, $owner);

    expect($enabled->enabled)->toBeTrue()
        ->and($enabled->configured_by)->toBe($owner->id)
        ->and($enabled->configured_at)->not->toBeNull();
});

test('disabling turns off sign-in without deleting the configuration', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);

    $identityProvider = TeamIdentityProvider::factory()->enabled()->create([
        'team_id' => $team->id,
        'provider' => IdentityProvider::Microsoft,
    ]);

    app(DisableTeamIdentityProvider::class)->handle($identityProvider, $owner);

    expect($identityProvider->fresh())
        ->enabled->toBeFalse()
        ->tenant_id->not->toBeNull();
});

test('disconnecting deletes the configuration entirely', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);

    $identityProvider = TeamIdentityProvider::factory()->create(['team_id' => $team->id, 'provider' => IdentityProvider::Microsoft]);
    $id = $identityProvider->id;

    app(DisconnectTeamIdentityProvider::class)->handle($identityProvider, $owner);

    expect(TeamIdentityProvider::find($id))->toBeNull();
});

test('every lifecycle action is audited, and the audit trail never contains the secret in any form', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);

    $saveAction = app(SaveTeamIdentityProvider::class);

    $identityProvider = $saveAction->handle($team, $owner, IdentityProvider::Microsoft, [
        'tenant_id' => (string) fake()->uuid(),
        'client_id' => (string) fake()->uuid(),
        'client_secret' => 'the-secret-value',
    ]);

    app(EnableTeamIdentityProvider::class)->handle($identityProvider, $owner);

    $saveAction->handle($team, $owner, IdentityProvider::Microsoft, [
        'tenant_id' => $identityProvider->tenant_id,
        'client_id' => $identityProvider->client_id,
        'client_secret' => null,
        'enforce_sso' => true,
    ]);

    app(DisableTeamIdentityProvider::class)->handle($identityProvider, $owner);
    app(DisconnectTeamIdentityProvider::class)->handle($identityProvider->fresh(), $owner);

    $actions = TeamIdentityProviderAudit::where('team_id', $team->id)->orderBy('id')->pluck('action');

    expect($actions->map(fn ($action) => $action->value)->all())->toBe([
        IdentityProviderAuditAction::Created->value,
        IdentityProviderAuditAction::Enabled->value,
        IdentityProviderAuditAction::Updated->value,
        IdentityProviderAuditAction::Disabled->value,
        IdentityProviderAuditAction::Disconnected->value,
    ]);

    $rawAuditRows = DB::table('team_identity_provider_audits')->where('team_id', $team->id)->get();

    foreach ($rawAuditRows as $row) {
        expect($row->changed_fields)->not->toContain('the-secret-value');
    }
});

test('auto-provisioning without a default role is rejected', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);

    expect(fn () => app(SaveTeamIdentityProvider::class)->handle($team, $owner, IdentityProvider::Microsoft, [
        'tenant_id' => (string) fake()->uuid(),
        'client_id' => (string) fake()->uuid(),
        'client_secret' => 'a-secret',
        'auto_provision_users' => true,
        'default_role' => null,
    ]))->toThrow(ValidationException::class);
});

test('assigning a privileged default role for auto-provisioned users requires member-update authorization', function () {
    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);

    expect(fn () => app(SaveTeamIdentityProvider::class)->handle($team, $manager, IdentityProvider::Microsoft, [
        'tenant_id' => (string) fake()->uuid(),
        'client_id' => (string) fake()->uuid(),
        'client_secret' => 'a-secret',
        'auto_provision_users' => true,
        'default_role' => TeamRole::Admin->value,
    ]))->toThrow(ValidationException::class);
});

test('an owner may assign a privileged default role for auto-provisioned users', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);

    $identityProvider = app(SaveTeamIdentityProvider::class)->handle($team, $owner, IdentityProvider::Microsoft, [
        'tenant_id' => (string) fake()->uuid(),
        'client_id' => (string) fake()->uuid(),
        'client_secret' => 'a-secret',
        'auto_provision_users' => true,
        'default_role' => TeamRole::Admin->value,
    ]);

    expect($identityProvider->default_role)->toBe(TeamRole::Admin);
});

test('an owner may assign a non-privileged default role for auto-provisioned users without extra authorization', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);

    $identityProvider = app(SaveTeamIdentityProvider::class)->handle($team, $owner, IdentityProvider::Microsoft, [
        'tenant_id' => (string) fake()->uuid(),
        'client_id' => (string) fake()->uuid(),
        'client_secret' => 'a-secret',
        'auto_provision_users' => true,
        'default_role' => TeamRole::Employee->value,
    ]);

    expect($identityProvider->default_role)->toBe(TeamRole::Employee);
});

test('allowed domains are normalized to lowercase and deduplicated before being persisted', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);

    $identityProvider = app(SaveTeamIdentityProvider::class)->handle($team, $owner, IdentityProvider::Microsoft, [
        'tenant_id' => (string) fake()->uuid(),
        'client_id' => (string) fake()->uuid(),
        'client_secret' => 'a-secret',
        'allowed_domains' => ['Example.com', 'EXAMPLE.COM', 'other.org'],
    ]);

    expect($identityProvider->allowed_domains)->toBe(['example.com', 'other.org']);
});

test('changes to one organization\'s identity provider configuration have no observable effect on another\'s', function () {
    ['team' => $teamA, 'user' => $ownerA] = teamWithMember(TeamRole::Owner);
    ['team' => $teamB, 'user' => $ownerB] = teamWithMember(TeamRole::Owner);

    $saveAction = app(SaveTeamIdentityProvider::class);

    $configA = $saveAction->handle($teamA, $ownerA, IdentityProvider::Microsoft, [
        'tenant_id' => (string) fake()->uuid(),
        'client_id' => (string) fake()->uuid(),
        'client_secret' => 'secret-a',
    ]);

    $configB = $saveAction->handle($teamB, $ownerB, IdentityProvider::Microsoft, [
        'tenant_id' => (string) fake()->uuid(),
        'client_id' => (string) fake()->uuid(),
        'client_secret' => 'secret-b',
    ]);

    app(EnableTeamIdentityProvider::class)->handle($configA, $ownerA);

    expect($configA->fresh()->enabled)->toBeTrue()
        ->and($configB->fresh()->enabled)->toBeFalse()
        ->and($configB->fresh()->client_secret_encrypted)->toBe('secret-b');
});

test('existing email and password login continues to work unaffected by this feature', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);

    TeamIdentityProvider::factory()->create(['team_id' => $team->id, 'provider' => IdentityProvider::Microsoft]);

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertSessionHasNoErrors();
    $this->assertAuthenticated();
});
