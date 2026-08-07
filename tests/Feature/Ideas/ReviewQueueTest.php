<?php

use App\Enums\TeamRole;
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
        ->assertSet('stats', [
            'awaiting' => 1,
            'newThisWeek' => 1,
            'totalVotes' => 3,
        ]);
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
