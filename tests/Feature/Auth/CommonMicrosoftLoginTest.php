<?php

use App\Models\Team;
use App\Models\TeamIdentityProvider;

test('the common login page offers a continue with microsoft option alongside the password form', function () {
    $response = $this->get(route('login'));

    $response->assertOk();
    $response->assertSee(route('login.microsoft.resolve'), false);
    $response->assertSee(route('login.store'), false);
});

test('an email matching exactly one organization redirects straight into that organization\'s microsoft sign-in', function () {
    $team = Team::factory()->create();
    TeamIdentityProvider::factory()->enabled()->create([
        'team_id' => $team->id,
        'allowed_domains' => ['ellisontravel.com'],
    ]);

    $response = $this->post(route('login.microsoft.resolve'), ['email' => 'someone@ellisontravel.com']);

    $response->assertRedirect(route('org.login.microsoft', ['team' => $team, 'email' => 'someone@ellisontravel.com']));
});

test('an email matching multiple organizations shows a selection screen instead of redirecting', function () {
    $teamA = Team::factory()->create();
    $teamB = Team::factory()->create();

    TeamIdentityProvider::factory()->enabled()->create([
        'team_id' => $teamA->id,
        'allowed_domains' => ['shared-domain.example'],
    ]);
    TeamIdentityProvider::factory()->enabled()->create([
        'team_id' => $teamB->id,
        'allowed_domains' => ['shared-domain.example'],
    ]);

    $response = $this->post(route('login.microsoft.resolve'), ['email' => 'someone@shared-domain.example']);

    $response->assertOk();
    $response->assertSee($teamA->name);
    $response->assertSee($teamB->name);
    $response->assertSee(route('org.login.microsoft', ['team' => $teamA, 'email' => 'someone@shared-domain.example']), false);
    $response->assertSee(route('org.login.microsoft', ['team' => $teamB, 'email' => 'someone@shared-domain.example']), false);
});

test('an email with no matching organization is rejected with a clear error', function () {
    $response = $this->post(route('login.microsoft.resolve'), ['email' => 'nobody@unknown.example']);

    $response->assertRedirect(route('login'));
    $response->assertSessionHas('error');
    $this->assertGuest();
});

test('a disabled provider does not participate in domain resolution', function () {
    $team = Team::factory()->create();
    TeamIdentityProvider::factory()->create([
        'team_id' => $team->id,
        'enabled' => false,
        'allowed_domains' => ['ellisontravel.com'],
    ]);

    $response = $this->post(route('login.microsoft.resolve'), ['email' => 'someone@ellisontravel.com']);

    $response->assertRedirect(route('login'));
    $response->assertSessionHas('error');
});

test('an unconfigured provider does not participate in domain resolution', function () {
    $team = Team::factory()->create();
    TeamIdentityProvider::factory()->create([
        'team_id' => $team->id,
        'enabled' => true,
        'client_id' => null,
        'client_secret_encrypted' => null,
        'allowed_domains' => ['ellisontravel.com'],
    ]);

    $response = $this->post(route('login.microsoft.resolve'), ['email' => 'someone@ellisontravel.com']);

    $response->assertRedirect(route('login'));
    $response->assertSessionHas('error');
});

test('a team with no configured allowed domains is never matched by domain resolution', function () {
    $team = Team::factory()->create();
    TeamIdentityProvider::factory()->enabled()->create([
        'team_id' => $team->id,
        'allowed_domains' => [],
    ]);

    $response = $this->post(route('login.microsoft.resolve'), ['email' => 'someone@anything.example']);

    $response->assertRedirect(route('login'));
    $response->assertSessionHas('error');
});

test('email is required to resolve an organization', function () {
    $response = $this->post(route('login.microsoft.resolve'), []);

    $response->assertSessionHasErrors('email');
});
