<?php

use App\Enums\IdentityProvider;
use App\Enums\SsoEnforcementScope;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamIdentityProvider;

test('password login is rejected when the organization requires Microsoft sign-in globally', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);

    TeamIdentityProvider::factory()->enabled()->create([
        'team_id' => $team->id,
        'provider' => IdentityProvider::Microsoft,
        'enforce_sso' => true,
        'enforce_sso_scope' => SsoEnforcementScope::Global,
    ]);

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertSessionHasErrors('email');
    $this->assertGuest();
});

test('a globally-enforced requirement blocks the member\'s password even for their other, non-enforcing teams', function () {
    ['team' => $enforcingTeam, 'user' => $user] = teamWithMember(TeamRole::Employee);
    $otherTeam = Team::factory()->create();
    $otherTeam->members()->attach($user, ['role' => TeamRole::Employee->value]);

    TeamIdentityProvider::factory()->enabled()->create([
        'team_id' => $enforcingTeam->id,
        'provider' => IdentityProvider::Microsoft,
        'enforce_sso' => true,
        'enforce_sso_scope' => SsoEnforcementScope::Global,
    ]);

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertSessionHasErrors('email');
    $this->assertGuest();
});

test('password login still works when the requirement is scoped to the organization only', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);

    TeamIdentityProvider::factory()->enabled()->create([
        'team_id' => $team->id,
        'provider' => IdentityProvider::Microsoft,
        'enforce_sso' => true,
        'enforce_sso_scope' => SsoEnforcementScope::Organization,
    ]);

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertSessionHasNoErrors();
    $this->assertAuthenticated();
});

test('password login works normally when no organization requires Microsoft sign-in', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);

    TeamIdentityProvider::factory()->enabled()->create([
        'team_id' => $team->id,
        'provider' => IdentityProvider::Microsoft,
    ]);

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertSessionHasNoErrors();
    $this->assertAuthenticated();
});

test('the organization login page hides the password form when Microsoft sign-in is required', function () {
    ['team' => $team] = teamWithMember(TeamRole::Employee);

    TeamIdentityProvider::factory()->enabled()->create([
        'team_id' => $team->id,
        'provider' => IdentityProvider::Microsoft,
        'enforce_sso' => true,
    ]);

    $response = $this->get(route('org.login', $team));

    $response->assertOk()
        ->assertSeeText('Continue with Microsoft')
        ->assertDontSeeText('Forgot your password?');
    expect($response->getContent())
        ->not->toContain('name="password"')
        ->toContain('data-test="microsoft-email-input"')
        ->toContain('x-bind:disabled="true && (!emailIsValid || !domainIsAllowed)"');
});

test('the organization login page shows the password form when Microsoft sign-in is not required', function () {
    ['team' => $team] = teamWithMember(TeamRole::Employee);

    TeamIdentityProvider::factory()->enabled()->create([
        'team_id' => $team->id,
        'provider' => IdentityProvider::Microsoft,
        'enforce_sso' => false,
    ]);

    $response = $this->get(route('org.login', $team));

    $response->assertOk();
    expect($response->getContent())
        ->toContain('name="password"')
        ->toContain('x-bind:disabled="false && (!emailIsValid || !domainIsAllowed)"')
        ->not->toContain('data-test="microsoft-email-input"');
});

test('the organization login page exposes the configured allowed domains for client-side validation', function () {
    ['team' => $team] = teamWithMember(TeamRole::Employee);

    TeamIdentityProvider::factory()->enabled()->create([
        'team_id' => $team->id,
        'provider' => IdentityProvider::Microsoft,
        'enforce_sso' => true,
        'allowed_domains' => ['ellisontravel.com'],
    ]);

    $response = $this->get(route('org.login', $team));

    $response->assertOk();
    expect($response->getContent())
        ->toContain('allowedDomains:')
        ->toContain('ellisontravel.com')
        ->toContain('Email address domain is not allowed.');
});
