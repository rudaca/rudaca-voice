<?php

use App\Enums\TeamRole;
use App\Models\IdeaBoard;
use App\Models\IdeaStatusHistory;
use App\Models\IdeaVote;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

test('the queue stats only count new ideas', function () {
    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);

    $new = makeIdea($team, ['status' => 'new', 'created_at' => now()]);
    makeIdea($team, ['status' => 'approved', 'created_at' => now()]);
    makeIdea($team, ['status' => 'planned']);
    makeIdea($team, ['status' => 'not_doing']);

    IdeaVote::factory()->count(3)->for($new)->create();

    Livewire::actingAs($manager)
        ->test('pages::ideas.review')
        ->assertSet('stats.awaiting', 1)
        ->assertSet('stats.newThisWeek', 1)
        ->assertSet('stats.totalVotes', 3);
});

test('the boards stat card counts the active boards and the groups they sit in', function () {
    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);

    $groupA = boardStack($team);
    $groupB = boardStack($team);

    // A second board inside group A, so two boards span only two groups.
    IdeaBoard::factory()->create([
        'team_id' => $team->id,
        'board_group_id' => $groupA['group']->id,
        'created_by_user_id' => $manager->id,
        'is_active' => true,
    ]);

    // Inactive boards and boards on other teams are ignored.
    IdeaBoard::factory()->create([
        'team_id' => $team->id,
        'board_group_id' => $groupB['group']->id,
        'created_by_user_id' => $manager->id,
        'is_active' => false,
    ]);
    boardStack(teamWithMember(TeamRole::Owner)['team']);

    Livewire::actingAs($manager)
        ->test('pages::ideas.review')
        ->assertSet('stats.boards', 3)
        ->assertSet('stats.boardGroups', 2)
        ->assertSee('Total Board in')
        ->assertSee('2 groups')
        // The label sits above the figure, matching the other stat cards.
        ->assertSeeInOrder(['Total Board in', 'rolling-boards-label']);
});

test('narrowing the group filter narrows every stat card', function () {
    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);

    $groupA = boardStack($team);
    $groupB = boardStack($team);

    $inA = makeIdea($team, [
        'status' => 'new',
        'created_at' => now(),
        'board_id' => $groupA['board']->id,
        'board_group_id' => $groupA['group']->id,
    ]);

    makeIdea($team, [
        'status' => 'new',
        'created_at' => now(),
        'board_id' => $groupB['board']->id,
        'board_group_id' => $groupB['group']->id,
    ]);

    IdeaVote::factory()->count(4)->for($inA)->create();

    $component = Livewire::actingAs($manager)->test('pages::ideas.review');

    $component
        ->assertSet('stats.boards', 2)
        ->assertSet('stats.boardGroups', 2)
        ->assertSet('stats.awaiting', 2)
        ->assertSet('stats.totalVotes', 4);

    $component->set('group', (string) $groupA['group']->id)
        ->assertSet('stats.boards', 1)
        ->assertSet('stats.boardGroups', 1)
        ->assertSet('stats.awaiting', 1)
        ->assertSet('stats.newThisWeek', 1)
        ->assertSet('stats.totalVotes', 4)
        ->assertSee('1 group');
});

test('narrowing the board filter narrows the board stat card', function () {
    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);

    $groupA = boardStack($team);
    boardStack($team);

    Livewire::actingAs($manager)
        ->test('pages::ideas.review')
        ->set('board', [(string) $groupA['board']->id])
        ->assertSet('stats.boards', 1)
        ->assertSet('stats.boardGroups', 1);
});

test('the stat card numbers render as rolling odometer digits', function () {
    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);
    makeIdea($team, ['status' => 'new']);

    $html = Livewire::actingAs($manager)
        ->test('pages::ideas.review')
        ->html();

    // The awaiting count is 1, so its single digit column parks its strip on face 1.
    expect($html)
        ->toContain('rolling-number-strip')
        ->toContain('wire:key="rolling-awaiting-0-1"')
        ->toContain('--rolling-digit: 1');
});

test('the queue lists ideas highest-voted first', function () {
    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);

    $lowVotes = makeIdea($team, ['status' => 'new', 'title' => 'Low votes idea']);
    $highVotes = makeIdea($team, ['status' => 'new', 'title' => 'High votes idea']);

    IdeaVote::factory()->count(1)->for($lowVotes)->create();
    IdeaVote::factory()->count(5)->for($highVotes)->create();

    Livewire::actingAs($manager)
        ->test('pages::ideas.review')
        ->assertSeeInOrder(['High votes idea', 'Low votes idea']);
});

test('a manager can approve a new idea and a history record is created', function () {
    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);
    $idea = makeIdea($team, ['status' => 'new']);

    Livewire::actingAs($manager)
        ->test('pages::ideas.review')
        ->call('approve', $idea->id);

    expect($idea->refresh()->status)->toBe('approved');

    $history = IdeaStatusHistory::where('idea_id', $idea->id)->latest('id')->first();

    expect($history)->not->toBeNull()
        ->and($history->changed_by_user_id)->toBe($manager->id)
        ->and($history->old_status)->toBe('new')
        ->and($history->new_status)->toBe('approved');
});

test('a manager can decline a queued idea and a history record is created', function () {
    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);
    $idea = makeIdea($team, ['status' => 'new']);

    Livewire::actingAs($manager)
        ->test('pages::ideas.review')
        ->call('decline', $idea->id);

    expect($idea->refresh()->status)->toBe('not_doing');

    $history = IdeaStatusHistory::where('idea_id', $idea->id)->latest('id')->first();

    expect($history)->not->toBeNull()
        ->and($history->old_status)->toBe('new')
        ->and($history->new_status)->toBe('not_doing');
});

test('a decided idea drops out of the queue', function () {
    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);
    $idea = makeIdea($team, ['status' => 'new', 'title' => 'About to be approved']);

    $component = Livewire::actingAs($manager)->test('pages::ideas.review');

    $component->assertSee('About to be approved');

    $component->call('approve', $idea->id)
        ->assertDontSee('About to be approved');
});

test('approving an idea that is not New is rejected instead of silently no-oping', function () {
    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);
    $idea = makeIdea($team, ['status' => 'approved']);

    expect(fn () => Livewire::actingAs($manager)
        ->test('pages::ideas.review')
        ->call('approve', $idea->id))
        ->toThrow(ModelNotFoundException::class);

    expect($idea->refresh()->status)->toBe('approved');
});

test('declining an idea that is not New is rejected instead of silently no-oping', function () {
    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);
    $idea = makeIdea($team, ['status' => 'planned']);

    expect(fn () => Livewire::actingAs($manager)
        ->test('pages::ideas.review')
        ->call('decline', $idea->id))
        ->toThrow(ModelNotFoundException::class);

    expect($idea->refresh()->status)->toBe('planned');
});

test('employee and viewer cannot approve or decline queued ideas', function (TeamRole $role) {
    ['team' => $team, 'user' => $user] = teamWithMember($role);
    $idea = makeIdea($team, ['status' => 'new']);

    Livewire::actingAs($user)
        ->test('pages::ideas.review')
        ->call('approve', $idea->id)
        ->assertStatus(403);

    expect($idea->refresh()->status)->toBe('new')
        ->and(IdeaStatusHistory::where('idea_id', $idea->id)->count())->toBe(0);
})->with([
    'employee' => TeamRole::Employee,
    'viewer' => TeamRole::Viewer,
]);

test('a manager cannot decide on an idea from another team', function () {
    ['team' => $teamA] = teamWithMember(TeamRole::Owner);
    $ideaA = makeIdea($teamA, ['status' => 'new']);

    ['user' => $managerB] = teamWithMember(TeamRole::Manager);

    expect(fn () => Livewire::actingAs($managerB)
        ->test('pages::ideas.review')
        ->call('approve', $ideaA->id))
        ->toThrow(ModelNotFoundException::class);

    expect($ideaA->refresh()->status)->toBe('new');
});

test('the sidebar shows an Administration section with the awaiting-review count for a manager', function () {
    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);
    makeIdea($team, ['status' => 'new']);
    makeIdea($team, ['status' => 'new']);
    makeIdea($team, ['status' => 'new']);
    makeIdea($team, ['status' => 'planned']);

    $content = $this->actingAs($manager)
        ->get(route('ideas.review', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertSee('Administration')
        ->assertSee('Review Queue')
        ->getContent();

    // The badge next to "Review Queue" should reflect only the new ideas (3), not the planned one.
    // Anchored on "Administration" rather than "Review Queue" itself, since the page's own
    // <title> tag also contains "Review Queue" and would otherwise be matched first.
    preg_match('/Administration(.{0,3000})/s', $content, $matches);
    expect($matches[1] ?? '')
        ->toContain('Review Queue')
        ->toContain('3');
});

test('the approve confirmation shows the Approved target status', function () {
    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);
    makeIdea($team, ['status' => 'new']);

    Livewire::actingAs($manager)
        ->test('pages::ideas.review')
        ->assertSee('You are about to move this idea to Approved status.');
});

test('the decline confirmation shows a generic message', function () {
    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);
    makeIdea($team, ['status' => 'new']);

    Livewire::actingAs($manager)
        ->test('pages::ideas.review')
        ->assertSee('You are about to move this idea to Declined status.');
});

test('the sidebar has no Administration section for an employee', function () {
    ['team' => $team, 'user' => $employee] = teamWithMember(TeamRole::Employee);

    $this->actingAs($employee)
        ->get(route('ideas.index', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertDontSee('Administration')
        ->assertDontSee('Review Queue');
});

test('Under Review does not appear anywhere in the review queue UI', function () {
    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);
    makeIdea($team, ['status' => 'new']);
    makeIdea($team, ['status' => 'approved']);
    makeIdea($team, ['status' => 'planned']);

    Livewire::actingAs($manager)
        ->test('pages::ideas.review')
        ->assertDontSee('Under Review');
});

test('the vote count box is highlighted only for ideas the current user voted for', function () {
    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);

    $votedIdea = makeIdea($team, ['status' => 'new', 'title' => 'Idea the manager backed']);
    $otherIdea = makeIdea($team, ['status' => 'new', 'title' => 'Idea nobody backed']);

    IdeaVote::factory()->for($votedIdea)->create(['user_id' => $manager->id]);
    // Someone else's vote must not light up the box for this manager.
    IdeaVote::factory()->for($otherIdea)->create();

    $component = Livewire::actingAs($manager)->test('pages::ideas.review');

    expect($component->instance()->ideas->firstWhere('id', $votedIdea->id)->voted)->toBeTrue()
        ->and($component->instance()->ideas->firstWhere('id', $otherIdea->id)->voted)->toBeFalse();

    $component
        ->assertSee('You voted for this idea.')
        ->assertSeeHtml('data-voted="true"')
        ->assertSeeHtml('border-indigo-200 bg-indigo-50');

    // Exactly one of the two rows is highlighted.
    expect(substr_count($component->html(), 'data-voted="true"'))->toBe(1);
});
