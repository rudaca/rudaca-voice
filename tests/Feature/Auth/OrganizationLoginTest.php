<?php

use App\Enums\IdentityProvider;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamIdentityProvider;

test('the microsoft button is shown when the provider is enabled and fully configured', function () {
    ['team' => $team] = teamWithMember(TeamRole::Employee);

    TeamIdentityProvider::factory()->enabled()->create([
        'team_id' => $team->id,
        'provider' => IdentityProvider::Microsoft,
    ]);

    $response = $this->get(route('org.login', $team));

    $response->assertOk()->assertSeeText('Continue with Microsoft');
});

test('the microsoft button is absent when there is no provider configured at all', function () {
    $team = Team::factory()->create();

    $response = $this->get(route('org.login', $team));

    $response->assertOk()->assertDontSeeText('Continue with Microsoft');
});

test('the microsoft button is absent when the provider is only partially configured', function () {
    $team = Team::factory()->create();

    TeamIdentityProvider::factory()->create([
        'team_id' => $team->id,
        'provider' => IdentityProvider::Microsoft,
        'client_id' => null,
        'enabled' => false,
    ]);

    $response = $this->get(route('org.login', $team));

    $response->assertOk()->assertDontSeeText('Continue with Microsoft');
});

test('the microsoft button is absent when the provider is fully configured but disabled', function () {
    $team = Team::factory()->create();

    TeamIdentityProvider::factory()->create([
        'team_id' => $team->id,
        'provider' => IdentityProvider::Microsoft,
        'enabled' => false,
    ]);

    $response = $this->get(route('org.login', $team));

    $response->assertOk()->assertDontSeeText('Continue with Microsoft');
});

test('the organization name is shown in the page title and login card', function () {
    $team = Team::factory()->create(['name' => 'Acme Corp']);

    $response = $this->get(route('org.login', $team));

    $response->assertOk()
        ->assertSeeInOrder(['<title', 'Acme Corp'])
        ->assertSeeText('Acme Corp');
});

test('an unknown organization slug 404s', function () {
    $this->get('/o/does-not-exist/login')->assertNotFound();
});

test('a soft-deleted organization 404s', function () {
    $team = Team::factory()->create();
    $team->delete();

    $this->get(route('org.login', $team))->assertNotFound();
});

test('the client secret never appears in the rendered login page', function () {
    $team = Team::factory()->create();

    TeamIdentityProvider::factory()->enabled()->create([
        'team_id' => $team->id,
        'provider' => IdentityProvider::Microsoft,
        'client_secret_encrypted' => 'super-secret-value',
    ]);

    $response = $this->get(route('org.login', $team));

    $response->assertOk();
    expect($response->getContent())->not->toContain('super-secret-value');
});

test('email and password login still works from the organization login page for an org with no sso configured', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);

    $this->get(route('org.login', $team))->assertOk();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertSessionHasNoErrors();
    $this->assertAuthenticated();
});

test('email and password login still works from the organization login page for an org with sso configured', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);

    TeamIdentityProvider::factory()->enabled()->create([
        'team_id' => $team->id,
        'provider' => IdentityProvider::Microsoft,
    ]);

    $this->get(route('org.login', $team))->assertOk()->assertSeeText('Continue with Microsoft');

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertSessionHasNoErrors();
    $this->assertAuthenticated();
});
