<?php

use App\Enums\TeamRole;
use Livewire\Livewire;

dataset('all roles', [
    'owner' => TeamRole::Owner,
    'admin' => TeamRole::Admin,
    'manager' => TeamRole::Manager,
    'employee' => TeamRole::Employee,
    'viewer' => TeamRole::Viewer,
]);

// 1. All Ideas — accessible to every team member.
test('any team member can access All Ideas', function (TeamRole $role) {
    ['team' => $team, 'user' => $user] = teamWithMember($role);

    $this->actingAs($user)
        ->get(route('ideas.index', ['current_team' => $team->slug]))
        ->assertOk();
})->with('all roles');

// 2. Submit Idea — owner/admin/manager/employee.
test('owner, admin, manager and employee can access Submit Idea', function (TeamRole $role) {
    ['team' => $team, 'user' => $user] = teamWithMember($role);

    $this->actingAs($user)
        ->get(route('ideas.create', ['current_team' => $team->slug]))
        ->assertOk();
})->with([
    'owner' => TeamRole::Owner,
    'admin' => TeamRole::Admin,
    'manager' => TeamRole::Manager,
    'employee' => TeamRole::Employee,
]);

// 2b. Viewer is read-only — cannot access Submit Idea (requires at least Employee).
test('a viewer cannot access Submit Idea', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Viewer);

    $this->actingAs($user)
        ->get(route('ideas.create', ['current_team' => $team->slug]))
        ->assertForbidden();
});

// 2c. The "New idea" button on All Ideas is hidden for viewers (read-only)
// and shown for anyone who can actually reach Submit Idea.
test('the New idea button is hidden for viewers on All Ideas', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Viewer);

    Livewire::actingAs($user)
        ->test('pages::ideas.index')
        ->assertDontSeeHtml('data-test="new-idea-button"');
});

test('the New idea button is shown for owner, admin, manager and employee on All Ideas', function (TeamRole $role) {
    ['team' => $team, 'user' => $user] = teamWithMember($role);

    Livewire::actingAs($user)
        ->test('pages::ideas.index')
        ->assertSeeHtml('data-test="new-idea-button"');
})->with([
    'owner' => TeamRole::Owner,
    'admin' => TeamRole::Admin,
    'manager' => TeamRole::Manager,
    'employee' => TeamRole::Employee,
]);

// 3. Idea detail — any member of the owning team.
test('any team member can open an idea in their own team', function (TeamRole $role) {
    ['team' => $team, 'user' => $user] = teamWithMember($role);
    $idea = makeIdea($team);

    $this->actingAs($user)
        ->get(route('ideas.show', ['current_team' => $team->slug, 'idea' => $idea->slug]))
        ->assertOk();
})->with('all roles');

// 3b. Idea detail — a member of another team is blocked by the membership middleware.
test('a member of another team cannot open the idea detail route', function () {
    ['team' => $teamA] = teamWithMember(TeamRole::Owner);
    $idea = makeIdea($teamA);

    ['user' => $userB] = teamWithMember(TeamRole::Employee);

    $this->actingAs($userB)
        ->get(route('ideas.show', ['current_team' => $teamA->slug, 'idea' => $idea->slug]))
        ->assertForbidden();
});

// 4. Review Ideas — manager and above.
test('owner, admin and manager can access Review Ideas', function (TeamRole $role) {
    ['team' => $team, 'user' => $user] = teamWithMember($role);

    $this->actingAs($user)
        ->get(route('ideas.review', ['current_team' => $team->slug]))
        ->assertOk();
})->with([
    'owner' => TeamRole::Owner,
    'admin' => TeamRole::Admin,
    'manager' => TeamRole::Manager,
]);

test('employee and viewer cannot access Review Ideas', function (TeamRole $role) {
    ['team' => $team, 'user' => $user] = teamWithMember($role);

    $this->actingAs($user)
        ->get(route('ideas.review', ['current_team' => $team->slug]))
        ->assertForbidden();
})->with([
    'employee' => TeamRole::Employee,
    'viewer' => TeamRole::Viewer,
]);

// 5. Idea Settings — owner and admin only.
test('owner and admin can access Idea Settings', function (TeamRole $role) {
    ['team' => $team, 'user' => $user] = teamWithMember($role);

    $this->actingAs($user)
        ->get(route('ideas.settings', ['current_team' => $team->slug]))
        ->assertOk();
})->with([
    'owner' => TeamRole::Owner,
    'admin' => TeamRole::Admin,
]);

test('manager, employee and viewer cannot access Idea Settings', function (TeamRole $role) {
    ['team' => $team, 'user' => $user] = teamWithMember($role);

    $this->actingAs($user)
        ->get(route('ideas.settings', ['current_team' => $team->slug]))
        ->assertForbidden();
})->with([
    'manager' => TeamRole::Manager,
    'employee' => TeamRole::Employee,
    'viewer' => TeamRole::Viewer,
]);

// 6. Moderate Comments — owner and admin only.
test('owner and admin can access Moderate Comments', function (TeamRole $role) {
    ['team' => $team, 'user' => $user] = teamWithMember($role);

    $this->actingAs($user)
        ->get(route('ideas.moderate-comments', ['current_team' => $team->slug]))
        ->assertOk();
})->with([
    'owner' => TeamRole::Owner,
    'admin' => TeamRole::Admin,
]);

test('manager, employee and viewer cannot access Moderate Comments', function (TeamRole $role) {
    ['team' => $team, 'user' => $user] = teamWithMember($role);

    $this->actingAs($user)
        ->get(route('ideas.moderate-comments', ['current_team' => $team->slug]))
        ->assertForbidden();
})->with([
    'manager' => TeamRole::Manager,
    'employee' => TeamRole::Employee,
    'viewer' => TeamRole::Viewer,
]);
