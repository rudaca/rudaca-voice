<?php

use App\Enums\TeamRole;
use App\Models\IdeaStatusHistory;
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

test('a viewer sees status-grouped stat cards instead of the participation cards', function () {
    ['team' => $team, 'user' => $viewer] = teamWithMember(TeamRole::Viewer);

    makeIdea($team, ['status' => 'new']);
    makeIdea($team, ['status' => 'under_review']);
    makeIdea($team, ['status' => 'planned']);
    makeIdea($team, ['status' => 'in_progress']);
    makeIdea($team, ['status' => 'released']);
    makeIdea($team, ['status' => 'not_doing']);
    makeIdea($team, ['status' => 'duplicate']);

    Livewire::actingAs($viewer)
        ->test('pages::dashboard')
        ->assertSeeText('Total ideas')
        ->assertSeeText('In the pipeline')
        ->assertSeeText('Active work')
        ->assertSeeText('Closed out')
        ->assertSeeText("Total for {$team->name}")
        ->assertSeeText('New 1 · Under Review 1 · Planned 1')
        ->assertSeeText('In Progress 1')
        ->assertSeeText('Implemented 1 · Declined 1 · Duplicate 1')
        ->assertDontSeeText('Your ideas')
        ->assertDontSeeText('Votes cast');
});

test('an employee defaults to the For You tab and can switch to By Status', function () {
    ['team' => $team, 'user' => $employee] = teamWithMember(TeamRole::Employee);
    makeIdea($team, ['status' => 'new']);

    $component = Livewire::actingAs($employee)
        ->test('pages::dashboard')
        ->assertSeeText('For You')
        ->assertSeeText('By Status')
        ->assertSeeText('Your ideas')
        ->assertDontSeeText('In the pipeline');

    $component->set('statsTab', 'by_status')
        ->assertSeeText('In the pipeline')
        ->assertDontSeeText('Your ideas');
});

test('a manager sees team-oriented For You cards instead of the personal participation cards', function () {
    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);

    makeIdea($team, ['status' => 'new']);
    makeIdea($team, ['status' => 'under_review']);
    makeIdea($team, ['status' => 'in_progress']);
    $released = makeIdea($team, ['status' => 'released']);

    IdeaStatusHistory::create([
        'idea_id' => $released->id,
        'changed_by_user_id' => $manager->id,
        'old_status' => 'in_progress',
        'new_status' => 'released',
    ]);

    Livewire::actingAs($manager)
        ->test('pages::dashboard')
        ->assertSeeText('Awaiting review')
        ->assertSeeText('need a decision')
        ->assertSeeText('being delivered')
        ->assertSeeText('this quarter')
        ->assertSeeText('Total ideas')
        ->assertSeeText('across all boards')
        ->assertDontSeeText('Your ideas')
        ->assertDontSeeText('Votes cast');
});

test('a manager sees Top of the queue instead of Trending ideas, linking to the review queue', function () {
    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);

    $lowVotes = makeIdea($team, ['status' => 'new', 'title' => 'Low votes idea']);
    $highVotes = makeIdea($team, ['status' => 'under_review', 'title' => 'High votes idea']);
    makeIdea($team, ['status' => 'planned', 'title' => 'Already decided idea']);

    IdeaVote::factory()->count(1)->for($lowVotes)->create();
    IdeaVote::factory()->count(5)->for($highVotes)->create();

    Livewire::actingAs($manager)
        ->test('pages::dashboard')
        ->assertSeeText('Top of the queue')
        ->assertDontSeeText('Trending ideas')
        ->assertSeeInOrder(['High votes idea', 'Low votes idea'])
        ->assertDontSeeText('Already decided idea');
});

test('an employee still sees Trending ideas and personal For You cards', function () {
    ['team' => $team, 'user' => $employee] = teamWithMember(TeamRole::Employee);
    makeIdea($team, ['status' => 'new']);

    Livewire::actingAs($employee)
        ->test('pages::dashboard')
        ->assertSeeText('Trending ideas')
        ->assertDontSeeText('Top of the queue')
        ->assertSeeText('Your ideas')
        ->assertDontSeeText('Awaiting review');
});

test('an admin/owner sees organization-wide For You cards instead of the personal participation cards', function (TeamRole $role) {
    ['team' => $team, 'user' => $admin] = teamWithMember($role);
    $team->members()->attach(User::factory()->count(2)->create(), ['role' => TeamRole::Employee->value]);

    makeIdea($team, ['status' => 'new']);
    makeIdea($team, ['status' => 'under_review']);
    makeIdea($team, ['status' => 'released']);

    Livewire::actingAs($admin)
        ->test('pages::dashboard')
        ->assertSeeText('Total ideas')
        ->assertSeeText('all time')
        ->assertSeeText('Members')
        ->assertSeeText("in {$team->name}")
        ->assertSeeText('Awaiting review')
        ->assertSeeText('need a decision')
        ->assertSeeText('Implemented')
        ->assertSeeText('shipped')
        ->assertDontSeeText('Your ideas')
        ->assertDontSeeText('Votes cast');
})->with([
    'admin' => TeamRole::Admin,
    'owner' => TeamRole::Owner,
]);

test('an admin/owner sees Highest voted instead of Trending ideas, ranked by votes across every status', function (TeamRole $role) {
    ['team' => $team, 'user' => $admin] = teamWithMember($role);

    $lowVotes = makeIdea($team, ['status' => 'in_progress', 'title' => 'Low votes idea']);
    $highVotes = makeIdea($team, ['status' => 'released', 'title' => 'High votes idea']);

    IdeaVote::factory()->count(1)->for($lowVotes)->create();
    IdeaVote::factory()->count(5)->for($highVotes)->create();

    Livewire::actingAs($admin)
        ->test('pages::dashboard')
        ->assertSeeText('Highest voted')
        ->assertDontSeeText('Trending ideas')
        ->assertDontSeeText('Top of the queue')
        ->assertSeeInOrder(['High votes idea', 'Low votes idea']);
})->with([
    'admin' => TeamRole::Admin,
    'owner' => TeamRole::Owner,
]);

test('a viewer does not see the For You / By Status tab toggle', function () {
    ['user' => $viewer] = teamWithMember(TeamRole::Viewer);

    Livewire::actingAs($viewer)
        ->test('pages::dashboard')
        ->assertDontSeeText('For You')
        ->assertDontSeeText('By Status');
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
