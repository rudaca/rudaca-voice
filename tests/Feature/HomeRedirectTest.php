<?php

use App\Models\User;

test('guests visiting the root are redirected to login', function () {
    $this->get('/')->assertRedirect(route('login'));
});

test('an authenticated user with a current team is redirected to their team dashboard', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    expect($team)->not->toBeNull();

    $this->actingAs($user)
        ->get('/')
        ->assertRedirect(route('dashboard', ['current_team' => $team->slug]));
});

test('an authenticated user without a current team is redirected to team management', function () {
    $user = User::factory()->create();
    $user->update(['current_team_id' => null]);

    // Act as a freshly-loaded instance (mirrors a real request, where the user is
    // resolved from the session without a cached currentTeam relation).
    $this->actingAs($user->fresh())
        ->get('/')
        ->assertRedirect(route('teams.index'));
});
