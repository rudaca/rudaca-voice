<?php

use App\Enums\IdentityProvider;
use App\Enums\SsoEnforcementScope;
use App\Enums\TeamRole;
use App\Models\TeamIdentityProvider;
use App\Models\User;
use App\Models\UserIdentityAccount;
use Livewire\Livewire;

test('the Authentication tab is visible to an owner but not to an employee', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);
    $employee = User::factory()->create();
    $team->members()->attach($employee, ['role' => TeamRole::Employee->value]);
    $employee->switchTeam($team);

    Livewire::actingAs($owner)
        ->test('pages::ideas.settings')
        ->assertSeeHtml('data-test="tab-authentication"');

    Livewire::actingAs($employee)
        ->test('pages::ideas.settings')
        ->assertDontSeeHtml('data-test="tab-authentication"');
});

test('a fresh organization has Microsoft sign-in disabled and no stored secret', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);

    Livewire::actingAs($owner)
        ->test('pages::ideas.authentication-settings', ['team' => $team])
        ->assertSet('enabled', false)
        ->assertSet('hasExistingSecret', false)
        ->assertSeeHtml('data-test="microsoft-status-not_configured"');
});

test('an owner can configure and enable Microsoft sign-in', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);

    Livewire::actingAs($owner)
        ->test('pages::ideas.authentication-settings', ['team' => $team])
        ->set('enabled', true)
        ->set('tenantId', (string) fake()->uuid())
        ->set('clientId', (string) fake()->uuid())
        ->set('newSecretInput', 'a-real-secret')
        ->call('save')
        ->assertHasNoErrors();

    $identityProvider = $team->identityProviderFor(IdentityProvider::Microsoft);

    expect($identityProvider)->not->toBeNull()
        ->and($identityProvider->enabled)->toBeTrue()
        ->and($identityProvider->configured_by)->toBe($owner->id);
});

test('an admin with manage-authentication permission can update settings', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    $owner = User::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    TeamIdentityProvider::factory()->enabled()->verified()->create(['team_id' => $team->id, 'provider' => IdentityProvider::Microsoft]);
    UserIdentityAccount::factory()->create(['team_id' => $team->id, 'user_id' => $owner->id, 'provider' => IdentityProvider::Microsoft]);

    Livewire::actingAs($admin)
        ->test('pages::ideas.authentication-settings', ['team' => $team])
        ->set('enforceSso', true)
        ->call('save')
        ->assertHasNoErrors()
        ->call('confirmEnableEnforcement');

    expect($team->identityProviderFor(IdentityProvider::Microsoft)->enforce_sso)->toBeTrue();
});

test('requiring Microsoft sign-in for the first time stages a confirmation instead of saving immediately', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);
    TeamIdentityProvider::factory()->enabled()->verified()->create(['team_id' => $team->id, 'provider' => IdentityProvider::Microsoft]);
    UserIdentityAccount::factory()->create(['team_id' => $team->id, 'user_id' => $owner->id, 'provider' => IdentityProvider::Microsoft]);

    Livewire::actingAs($owner)
        ->test('pages::ideas.authentication-settings', ['team' => $team])
        ->set('enforceSso', true)
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('modal-show', name: 'confirm-enforce-sso');

    expect($team->identityProviderFor(IdentityProvider::Microsoft)->enforce_sso)->toBeFalse();
});

test('requiring Microsoft sign-in is rejected until an owner has linked their own Microsoft identity', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);
    TeamIdentityProvider::factory()->enabled()->verified()->create(['team_id' => $team->id, 'provider' => IdentityProvider::Microsoft]);

    Livewire::actingAs($owner)
        ->test('pages::ideas.authentication-settings', ['team' => $team])
        ->set('enforceSso', true)
        ->call('save')
        ->assertHasErrors(['enforceSso']);

    expect($team->identityProviderFor(IdentityProvider::Microsoft)->enforce_sso)->toBeFalse();
});

test('requiring Microsoft sign-in without enabling it is rejected', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);
    TeamIdentityProvider::factory()->create(['team_id' => $team->id, 'provider' => IdentityProvider::Microsoft]);

    Livewire::actingAs($owner)
        ->test('pages::ideas.authentication-settings', ['team' => $team])
        ->set('enabled', false)
        ->set('enforceSso', true)
        ->call('save')
        ->assertHasErrors(['enforceSso']);

    expect($team->identityProviderFor(IdentityProvider::Microsoft)->enforce_sso)->toBeFalse();
});

test('a fresh organization defaults its enforcement scope to global', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);

    Livewire::actingAs($owner)
        ->test('pages::ideas.authentication-settings', ['team' => $team])
        ->assertSet('enforceSsoScope', SsoEnforcementScope::Global->value);
});

test('an owner can scope the Microsoft sign-in requirement to this organization only', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);
    TeamIdentityProvider::factory()->enabled()->verified()->create(['team_id' => $team->id, 'provider' => IdentityProvider::Microsoft]);
    UserIdentityAccount::factory()->create(['team_id' => $team->id, 'user_id' => $owner->id, 'provider' => IdentityProvider::Microsoft]);

    Livewire::actingAs($owner)
        ->test('pages::ideas.authentication-settings', ['team' => $team])
        ->set('enforceSso', true)
        ->set('enforceSsoScope', SsoEnforcementScope::Organization->value)
        ->call('save')
        ->assertHasNoErrors()
        ->call('confirmEnableEnforcement');

    expect($team->identityProviderFor(IdentityProvider::Microsoft)->enforce_sso_scope)->toBe(SsoEnforcementScope::Organization);
});

test('a manager without manage-authentication permission is denied access to the panel', function () {
    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);

    Livewire::actingAs($manager)
        ->test('pages::ideas.authentication-settings', ['team' => $team])
        ->assertForbidden();
});

test('a user outside the organization cannot view or modify its configuration by passing the team directly', function () {
    ['team' => $team] = teamWithMember(TeamRole::Owner);
    $outsider = User::factory()->create();

    Livewire::actingAs($outsider)
        ->test('pages::ideas.authentication-settings', ['team' => $team])
        ->assertForbidden();
});

test('a super admin can view and configure Microsoft sign-in for an organization they do not belong to', function () {
    ['team' => $team] = teamWithMember(TeamRole::Owner);
    $superAdmin = User::factory()->create(['is_super_admin' => true]);

    Livewire::actingAs($superAdmin)
        ->test('pages::ideas.authentication-settings', ['team' => $team])
        ->set('enabled', true)
        ->set('tenantId', (string) fake()->uuid())
        ->set('clientId', (string) fake()->uuid())
        ->set('newSecretInput', 'a-real-secret')
        ->call('save')
        ->assertHasNoErrors();

    $identityProvider = $team->identityProviderFor(IdentityProvider::Microsoft);

    expect($identityProvider)->not->toBeNull()
        ->and($identityProvider->enabled)->toBeTrue();
});

test('the client secret never appears in the component payload after saving', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);

    $component = Livewire::actingAs($owner)
        ->test('pages::ideas.authentication-settings', ['team' => $team])
        ->set('tenantId', (string) fake()->uuid())
        ->set('clientId', (string) fake()->uuid())
        ->set('newSecretInput', 'totally-secret-value')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('newSecretInput', '')
        ->assertSet('hasExistingSecret', true);

    expect($component->html())->not->toContain('totally-secret-value');
});

test('updating settings without submitting a new secret preserves the previously saved secret', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);

    Livewire::actingAs($owner)
        ->test('pages::ideas.authentication-settings', ['team' => $team])
        ->set('tenantId', (string) fake()->uuid())
        ->set('clientId', (string) fake()->uuid())
        ->set('newSecretInput', 'original-secret')
        ->call('save')
        ->assertHasNoErrors();

    $identityProvider = $team->identityProviderFor(IdentityProvider::Microsoft);
    $original = $identityProvider->client_secret_encrypted;

    // Simulates a connection test having already succeeded for this exact
    // configuration — SaveTeamIdentityProvider requires that before it will
    // accept enforce_sso.
    $identityProvider->forceFill(['verified_at' => now()])->save();
    UserIdentityAccount::factory()->create(['team_id' => $team->id, 'user_id' => $owner->id, 'provider' => IdentityProvider::Microsoft]);

    Livewire::actingAs($owner)
        ->test('pages::ideas.authentication-settings', ['team' => $team])
        ->set('enabled', true)
        ->set('enforceSso', true)
        ->call('save')
        ->assertHasNoErrors()
        ->call('confirmEnableEnforcement');

    expect($team->identityProviderFor(IdentityProvider::Microsoft)->client_secret_encrypted)->toBe($original);
});

test('an invalid tenant id is rejected', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);

    Livewire::actingAs($owner)
        ->test('pages::ideas.authentication-settings', ['team' => $team])
        ->set('enabled', true)
        ->set('tenantId', 'not-a-guid')
        ->set('clientId', (string) fake()->uuid())
        ->set('newSecretInput', 'a-secret')
        ->call('save')
        ->assertHasErrors(['tenantId']);
});

test('the multi-tenant literals are accepted as a tenant id', function (string $tenantId) {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);

    Livewire::actingAs($owner)
        ->test('pages::ideas.authentication-settings', ['team' => $team])
        ->set('enabled', true)
        ->set('tenantId', $tenantId)
        ->set('clientId', (string) fake()->uuid())
        ->set('newSecretInput', 'a-secret')
        ->call('save')
        ->assertHasNoErrors();
})->with(['common', 'organizations', 'consumers']);

test('an invalid client id is rejected', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);

    Livewire::actingAs($owner)
        ->test('pages::ideas.authentication-settings', ['team' => $team])
        ->set('enabled', true)
        ->set('tenantId', (string) fake()->uuid())
        ->set('clientId', 'not-a-guid')
        ->set('newSecretInput', 'a-secret')
        ->call('save')
        ->assertHasErrors(['clientId']);
});

test('enabling without a client secret is rejected when none has ever been saved', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);

    Livewire::actingAs($owner)
        ->test('pages::ideas.authentication-settings', ['team' => $team])
        ->set('enabled', true)
        ->set('tenantId', (string) fake()->uuid())
        ->set('clientId', (string) fake()->uuid())
        ->call('save')
        ->assertHasErrors(['newSecretInput']);
});

test('an incomplete app registration is rejected even when sign-in is not being enabled', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);

    Livewire::actingAs($owner)
        ->test('pages::ideas.authentication-settings', ['team' => $team])
        ->call('save')
        ->assertHasErrors(['tenantId', 'clientId', 'newSecretInput']);

    expect($team->identityProviderFor(IdentityProvider::Microsoft))->toBeNull();
});

test('duplicate allowed domains are rejected', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);

    Livewire::actingAs($owner)
        ->test('pages::ideas.authentication-settings', ['team' => $team])
        ->set('allowedDomainsInput', 'example.com, EXAMPLE.COM')
        ->call('save')
        ->assertHasErrors(['allowedDomainsInput']);
});

test('an invalid domain format is rejected', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);

    Livewire::actingAs($owner)
        ->test('pages::ideas.authentication-settings', ['team' => $team])
        ->set('allowedDomainsInput', 'not a domain')
        ->call('save')
        ->assertHasErrors(['allowedDomains.0']);
});

test('valid allowed domains are normalized to lowercase and saved', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);

    Livewire::actingAs($owner)
        ->test('pages::ideas.authentication-settings', ['team' => $team])
        ->set('tenantId', (string) fake()->uuid())
        ->set('clientId', (string) fake()->uuid())
        ->set('newSecretInput', 'a-secret')
        ->set('allowedDomainsInput', 'Example.com, other.org')
        ->call('save')
        ->assertHasNoErrors();

    expect($team->identityProviderFor(IdentityProvider::Microsoft)->allowed_domains)->toBe(['example.com', 'other.org']);
});

test('enabling auto-provisioning without a default role is rejected', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);

    Livewire::actingAs($owner)
        ->test('pages::ideas.authentication-settings', ['team' => $team])
        ->set('autoProvisionUsers', true)
        ->set('defaultRole', '')
        ->call('save')
        ->assertHasErrors(['defaultRole']);
});

test('an admin cannot assign a privileged default role for auto-provisioned users without member-update authorization', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);

    Livewire::actingAs($admin)
        ->test('pages::ideas.authentication-settings', ['team' => $team])
        ->set('tenantId', (string) fake()->uuid())
        ->set('clientId', (string) fake()->uuid())
        ->set('newSecretInput', 'a-secret')
        ->set('autoProvisionUsers', true)
        ->set('defaultRole', TeamRole::Admin->value)
        ->call('save')
        ->assertHasErrors(['defaultRole']);
});

test('an owner can assign a privileged default role for auto-provisioned users', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);

    Livewire::actingAs($owner)
        ->test('pages::ideas.authentication-settings', ['team' => $team])
        ->set('tenantId', (string) fake()->uuid())
        ->set('clientId', (string) fake()->uuid())
        ->set('newSecretInput', 'a-secret')
        ->set('autoProvisionUsers', true)
        ->set('defaultRole', TeamRole::Admin->value)
        ->call('save')
        ->assertHasNoErrors();

    expect($team->identityProviderFor(IdentityProvider::Microsoft)->default_role)->toBe(TeamRole::Admin);
});

test('an existing secret is shown masked, with a Replace secret affordance instead of the real value', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);
    TeamIdentityProvider::factory()->create([
        'team_id' => $team->id,
        'provider' => IdentityProvider::Microsoft,
        'client_secret_encrypted' => 'existing-secret-value',
    ]);

    $component = Livewire::actingAs($owner)
        ->test('pages::ideas.authentication-settings', ['team' => $team])
        ->assertSet('hasExistingSecret', true)
        ->assertSeeHtml('data-test="client-secret-masked"')
        ->assertSeeHtml('data-test="replace-secret-button"')
        ->assertDontSeeHtml('data-test="client-secret-input"');

    expect($component->html())->not->toContain('existing-secret-value');

    $component->call('$set', 'replacingSecret', true)
        ->assertSeeHtml('data-test="client-secret-input"');
});

test('the redirect URL is shown read-only and is derived from the app URL', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);

    Livewire::actingAs($owner)
        ->test('pages::ideas.authentication-settings', ['team' => $team])
        ->assertSee(IdentityProvider::Microsoft->redirectUrl())
        ->assertSeeHtml('data-test="redirect-url-input"');
});

test('an owner can disable a fully configured provider without losing its configuration', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);
    $identityProvider = TeamIdentityProvider::factory()->enabled()->create([
        'team_id' => $team->id,
        'provider' => IdentityProvider::Microsoft,
    ]);

    Livewire::actingAs($owner)
        ->test('pages::ideas.authentication-settings', ['team' => $team])
        ->call('disable')
        ->assertSet('enabled', false);

    expect($identityProvider->fresh())
        ->enabled->toBeFalse()
        ->tenant_id->not->toBeNull();
});

test('disabling a provider that required Microsoft sign-in also turns off the requirement', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);
    $identityProvider = TeamIdentityProvider::factory()->enabled()->create([
        'team_id' => $team->id,
        'provider' => IdentityProvider::Microsoft,
        'enforce_sso' => true,
    ]);

    Livewire::actingAs($owner)
        ->test('pages::ideas.authentication-settings', ['team' => $team])
        ->call('disable')
        ->assertSet('enabled', false)
        ->assertSet('enforceSso', false);

    expect($identityProvider->fresh())
        ->enabled->toBeFalse()
        ->enforce_sso->toBeFalse();
});

test('an owner can disconnect a provider, deleting its configuration', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);
    $identityProvider = TeamIdentityProvider::factory()->create(['team_id' => $team->id, 'provider' => IdentityProvider::Microsoft]);

    Livewire::actingAs($owner)
        ->test('pages::ideas.authentication-settings', ['team' => $team])
        ->call('disconnect')
        ->assertSet('hasExistingSecret', false);

    expect(TeamIdentityProvider::find($identityProvider->id))->toBeNull();
});
