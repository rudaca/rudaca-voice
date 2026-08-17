<?php

use App\Enums\TeamRole;
use App\Models\Team;

test('a guest hitting a team-scoped route is redirected to that team\'s login page', function () {
    ['team' => $team] = teamWithMember(TeamRole::Employee);

    $response = $this->get(route('ideas.index', ['current_team' => $team->slug]));

    $response->assertRedirect(route('org.login', $team));
});

test('a guest hitting a team-scoped route for an unknown slug falls back to the generic login page', function () {
    $response = $this->get('/does-not-exist/dashboard');

    $response->assertRedirect(route('login'));
});

test('a guest hitting a non-team route is redirected to the generic login page', function () {
    $response = $this->get(route('teams.index'));

    $response->assertRedirect(route('login'));
});

test('signing in from a team login redirect returns the guest to the originally requested page', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);

    $this->get(route('ideas.index', ['current_team' => $team->slug]))
        ->assertRedirect(route('org.login', $team));

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertSessionHasNoErrors();
    $response->assertRedirect(route('ideas.index', ['current_team' => $team->slug]));
});

test('a guest hitting a route bound to a specific soft-deleted team falls back to the generic login page', function () {
    $team = Team::factory()->create();
    $team->delete();

    $response = $this->get(route('dashboard', ['current_team' => $team->slug]));

    $response->assertRedirect(route('login'));
});
