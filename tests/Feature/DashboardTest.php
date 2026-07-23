<?php

use App\Enums\TeamRole;
use App\Models\IdeaVote;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard'));

    $response->assertOk();
});

test('voting on a trending idea from the dashboard updates the count without redirecting', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    $idea = makeIdea($team);

    Livewire::actingAs($user)
        ->test('pages::dashboard')
        ->call('toggleVote', $idea->id)
        ->assertHasNoErrors()
        ->assertNoRedirect();

    expect(IdeaVote::where('idea_id', $idea->id)->where('user_id', $user->id)->count())->toBe(1);

    Livewire::actingAs($user)
        ->test('pages::dashboard')
        ->call('toggleVote', $idea->id)
        ->assertHasNoErrors()
        ->assertNoRedirect();

    expect(IdeaVote::where('idea_id', $idea->id)->where('user_id', $user->id)->count())->toBe(0);
});

test('a viewer cannot vote from the dashboard', function () {
    ['team' => $team, 'user' => $viewer] = teamWithMember(TeamRole::Viewer);
    $idea = makeIdea($team);

    Livewire::actingAs($viewer)
        ->test('pages::dashboard')
        ->call('toggleVote', $idea->id)
        ->assertStatus(403);

    expect(IdeaVote::where('idea_id', $idea->id)->where('user_id', $viewer->id)->count())->toBe(0);
});

test('the dashboard heading is tailored to the current user\'s team role', function (TeamRole $role, string $heading) {
    ['user' => $user] = teamWithMember($role);

    Livewire::actingAs($user)
        ->test('pages::dashboard')
        ->assertSee($heading);
})->with([
    'viewer' => [TeamRole::Viewer, 'Your Ideas Hub'],
    'employee' => [TeamRole::Employee, 'Your Ideas Hub'],
    'manager' => [TeamRole::Manager, 'Organization Ideas Overview'],
    'admin' => [TeamRole::Admin, 'Organization Overview'],
    'owner' => [TeamRole::Owner, 'Organization Overview'],
]);
