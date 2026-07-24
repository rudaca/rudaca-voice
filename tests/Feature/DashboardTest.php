<?php

use App\Enums\TeamRole;
use App\Models\IdeaComment;
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
        ->assertSeeText('Contributors')
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

test('the boards panel defaults to Top Boards and can switch to Top Contributors', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    makeIdea($team);

    $component = Livewire::actingAs($user)
        ->test('pages::dashboard')
        ->assertSeeText('Top Boards')
        ->assertSeeText('Top Contributors')
        ->assertSet('boardsTab', 'boards');

    $component->set('boardsTab', 'contributors')
        ->assertSet('boardsTab', 'contributors');
});

test('Top Boards are ranked by idea count, ties broken alphabetically', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);

    $zebra = boardStack($team);
    $zebra['board']->update(['name' => 'Zebra Board']);
    $alpha = boardStack($team);
    $alpha['board']->update(['name' => 'Alpha Board']);
    $bravo = boardStack($team);
    $bravo['board']->update(['name' => 'Bravo Board']);

    makeIdea($team, ['board_id' => $zebra['board']->id, 'board_group_id' => $zebra['board']->board_group_id, 'category_id' => $zebra['category']->id]);
    makeIdea($team, ['board_id' => $alpha['board']->id, 'board_group_id' => $alpha['board']->board_group_id, 'category_id' => $alpha['category']->id]);
    makeIdea($team, ['board_id' => $alpha['board']->id, 'board_group_id' => $alpha['board']->board_group_id, 'category_id' => $alpha['category']->id]);
    makeIdea($team, ['board_id' => $bravo['board']->id, 'board_group_id' => $bravo['board']->board_group_id, 'category_id' => $bravo['category']->id]);
    makeIdea($team, ['board_id' => $bravo['board']->id, 'board_group_id' => $bravo['board']->board_group_id, 'category_id' => $bravo['category']->id]);

    Livewire::actingAs($user)
        ->test('pages::dashboard')
        ->assertSeeInOrder(['Alpha Board', 'Bravo Board', 'Zebra Board']);
});

test('Top Boards computes ideas and comments counts, without an internal comments badge', function () {
    ['team' => $team, 'user' => $employee] = teamWithMember(TeamRole::Employee);
    $stack = boardStack($team);
    $idea = makeIdea($team, ['board_id' => $stack['board']->id, 'board_group_id' => $stack['board']->board_group_id, 'category_id' => $stack['category']->id]);

    IdeaComment::factory()->count(2)->create(['idea_id' => $idea->id]);
    IdeaComment::factory()->internal()->create(['idea_id' => $idea->id]);

    $component = Livewire::actingAs($employee)->test('pages::dashboard');
    $board = $component->instance()->topBoards->firstWhere('id', $stack['board']->id);

    expect($board->ideas_count)->toBe(1)
        ->and($board->comments_count)->toBe(3);

    $component->assertDontSeeText('Internal Comments');
});

test('Top Boards counts unique contributors across idea submitters and commenters', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);
    $stack = boardStack($team);

    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $team->members()->attach([$alice->id, $bob->id], ['role' => TeamRole::Employee->value]);

    $aliceIdea = makeIdea($team, ['submitted_by_user_id' => $alice->id, 'board_id' => $stack['board']->id, 'board_group_id' => $stack['board']->board_group_id, 'category_id' => $stack['category']->id]);
    makeIdea($team, ['submitted_by_user_id' => $bob->id, 'board_id' => $stack['board']->id, 'board_group_id' => $stack['board']->board_group_id, 'category_id' => $stack['category']->id]);

    // Bob also comments on Alice's idea — he should only count once as a contributor.
    IdeaComment::factory()->create(['idea_id' => $aliceIdea->id, 'user_id' => $bob->id]);
    IdeaComment::factory()->create(['idea_id' => $aliceIdea->id, 'user_id' => $owner->id]);

    $component = Livewire::actingAs($owner)->test('pages::dashboard');
    $board = $component->instance()->topBoards->firstWhere('id', $stack['board']->id);

    expect($board->contributors_count)->toBe(3);
});

test('Top Contributors ranks members by ideas then comments, tie-broken alphabetically', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);

    $alice = User::factory()->create(['name' => 'Alice Zephyr']);
    $bob = User::factory()->create(['name' => 'Bob Young']);
    $carol = User::factory()->create(['name' => 'Carol Xu']);
    $team->members()->attach([$alice->id, $bob->id, $carol->id], ['role' => TeamRole::Employee->value]);

    $boardOne = boardStack($team);
    $boardTwo = boardStack($team);

    // Alice: 2 ideas across 2 boards, 1 comment.
    $aliceIdea = makeIdea($team, ['submitted_by_user_id' => $alice->id, 'board_id' => $boardOne['board']->id, 'board_group_id' => $boardOne['board']->board_group_id, 'category_id' => $boardOne['category']->id]);
    makeIdea($team, ['submitted_by_user_id' => $alice->id, 'board_id' => $boardTwo['board']->id, 'board_group_id' => $boardTwo['board']->board_group_id, 'category_id' => $boardTwo['category']->id]);
    IdeaComment::factory()->create(['idea_id' => $aliceIdea->id, 'user_id' => $alice->id]);

    // Bob: 1 idea, 3 comments.
    $bobIdea = makeIdea($team, ['submitted_by_user_id' => $bob->id, 'board_id' => $boardOne['board']->id, 'board_group_id' => $boardOne['board']->board_group_id, 'category_id' => $boardOne['category']->id]);
    IdeaComment::factory()->count(3)->create(['idea_id' => $bobIdea->id, 'user_id' => $bob->id]);

    // Carol: 1 idea, 1 comment — ties Bob on ideas but loses on comments.
    $carolIdea = makeIdea($team, ['submitted_by_user_id' => $carol->id, 'board_id' => $boardOne['board']->id, 'board_group_id' => $boardOne['board']->board_group_id, 'category_id' => $boardOne['category']->id]);
    IdeaComment::factory()->create(['idea_id' => $carolIdea->id, 'user_id' => $carol->id]);

    Livewire::actingAs($owner)
        ->test('pages::dashboard')
        ->set('boardsTab', 'contributors')
        ->assertSeeInOrder(['Alice Zephyr', 'Bob Young', 'Carol Xu']);
});

test('Top Contributors shows each contributor role with its badge color', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);

    $manager = User::factory()->create(['name' => 'Mona Manager']);
    $team->members()->attach($manager->id, ['role' => TeamRole::Manager->value]);

    $stack = boardStack($team);
    makeIdea($team, ['submitted_by_user_id' => $manager->id, 'board_id' => $stack['board']->id, 'board_group_id' => $stack['board']->board_group_id, 'category_id' => $stack['category']->id]);

    $component = Livewire::actingAs($owner)
        ->test('pages::dashboard')
        ->set('boardsTab', 'contributors')
        ->assertSeeText('Mona Manager')
        ->assertSeeText(TeamRole::Manager->label());

    $contributor = collect($component->instance()->topContributors)
        ->firstWhere(fn ($contributor) => $contributor['user']->id === $manager->id);

    expect($contributor['role'])->toBe(TeamRole::Manager);
});

test('Top Boards shows at most 10 boards', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);

    foreach (range(1, 12) as $i) {
        $stack = boardStack($team);
        $stack['board']->update(['name' => "Board {$i}"]);
    }

    $component = Livewire::actingAs($user)->test('pages::dashboard');

    expect($component->instance()->topBoards)->toHaveCount(10);
});

test('Top Contributors shows at most 10 members', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);
    $members = User::factory()->count(12)->create();
    $team->members()->attach($members, ['role' => TeamRole::Employee->value]);

    $stack = boardStack($team);
    foreach ($members as $member) {
        makeIdea($team, ['submitted_by_user_id' => $member->id, 'board_id' => $stack['board']->id, 'board_group_id' => $stack['board']->board_group_id, 'category_id' => $stack['category']->id]);
    }

    $component = Livewire::actingAs($owner)
        ->test('pages::dashboard')
        ->set('boardsTab', 'contributors');

    expect($component->instance()->topContributors)->toHaveCount(10);
});

test('a contributor with no ideas, comments, or boards is excluded from Top Contributors', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);
    $inactive = User::factory()->create(['name' => 'Inactive Ida']);
    $team->members()->attach($inactive->id, ['role' => TeamRole::Employee->value]);

    $active = User::factory()->create(['name' => 'Active Amy']);
    $team->members()->attach($active->id, ['role' => TeamRole::Employee->value]);
    $stack = boardStack($team);
    makeIdea($team, ['submitted_by_user_id' => $active->id, 'board_id' => $stack['board']->id, 'board_group_id' => $stack['board']->board_group_id, 'category_id' => $stack['category']->id]);

    Livewire::actingAs($owner)
        ->test('pages::dashboard')
        ->set('boardsTab', 'contributors')
        ->assertSeeText('Active Amy')
        ->assertDontSeeText('Inactive Ida')
        ->assertDontSeeText('No activities yet');
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
