<?php

use App\Enums\TeamRole;
use App\Models\IdeaStatusHistory;
use App\Models\IdeaVote;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

test('the queue stats only count new and under-review ideas', function () {
    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);

    $new = makeIdea($team, ['status' => 'new', 'created_at' => now()]);
    $underReview = makeIdea($team, ['status' => 'under_review', 'created_at' => now()]);
    makeIdea($team, ['status' => 'planned']);
    makeIdea($team, ['status' => 'not_doing']);

    IdeaVote::factory()->count(3)->for($new)->create();
    IdeaVote::factory()->count(2)->for($underReview)->create();

    Livewire::actingAs($manager)
        ->test('pages::ideas.review')
        ->assertSet('stats', [
            'awaiting' => 2,
            'newThisWeek' => 2,
            'totalVotes' => 5,
        ]);
});

test('the queue lists ideas highest-voted first', function () {
    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);

    $lowVotes = makeIdea($team, ['status' => 'new', 'title' => 'Low votes idea']);
    $highVotes = makeIdea($team, ['status' => 'under_review', 'title' => 'High votes idea']);

    IdeaVote::factory()->count(1)->for($lowVotes)->create();
    IdeaVote::factory()->count(5)->for($highVotes)->create();

    Livewire::actingAs($manager)
        ->test('pages::ideas.review')
        ->assertSeeInOrder(['High votes idea', 'Low votes idea']);
});

test('a manager can approve a queued idea and a history record is created', function () {
    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);
    $idea = makeIdea($team, ['status' => 'under_review']);

    Livewire::actingAs($manager)
        ->test('pages::ideas.review')
        ->call('approve', $idea->id);

    expect($idea->refresh()->status)->toBe('planned');

    $history = IdeaStatusHistory::where('idea_id', $idea->id)->latest('id')->first();

    expect($history)->not->toBeNull()
        ->and($history->changed_by_user_id)->toBe($manager->id)
        ->and($history->old_status)->toBe('under_review')
        ->and($history->new_status)->toBe('planned');
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

test('the sidebar shows a Management section with the awaiting-review count for a manager', function () {
    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);
    makeIdea($team, ['status' => 'new']);
    makeIdea($team, ['status' => 'under_review']);
    makeIdea($team, ['status' => 'under_review']);
    makeIdea($team, ['status' => 'planned']);

    $content = $this->actingAs($manager)
        ->get(route('ideas.review', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertSee('Management')
        ->assertSee('Review Queue')
        ->getContent();

    // The badge next to "Review Queue" should reflect only the new/under_review ideas (3), not the planned one.
    preg_match('/Review Queue(.{0,400})/s', $content, $matches);
    expect($matches[1] ?? '')->toContain('3');
});

test('the sidebar has no Management section for an employee', function () {
    ['team' => $team, 'user' => $employee] = teamWithMember(TeamRole::Employee);

    $this->actingAs($employee)
        ->get(route('ideas.index', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertDontSee('Management')
        ->assertDontSee('Review Queue');
});
