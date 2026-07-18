<?php

use App\Enums\TeamRole;
use App\Models\IdeaVote;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Livewire\Livewire;

test('an employee can vote for an idea in their team', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    $idea = makeIdea($team);

    Livewire::actingAs($user)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->call('toggleVote')
        ->assertHasNoErrors();

    $votes = IdeaVote::where('idea_id', $idea->id)->where('user_id', $user->id)->get();

    expect($votes)->toHaveCount(1)
        ->and($votes->first()->idea_id)->toBe($idea->id)
        ->and($votes->first()->user_id)->toBe($user->id);
});

test('a user can unvote and the count updates both ways', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    $idea = makeIdea($team);

    $component = Livewire::actingAs($user)->test('pages::ideas.show', ['idea' => $idea->slug]);

    $component->call('toggleVote');
    expect($component->instance()->voteCount)->toBe(1)
        ->and($component->instance()->hasVoted)->toBeTrue();

    $component->call('toggleVote');
    expect($component->instance()->voteCount)->toBe(0)
        ->and($component->instance()->hasVoted)->toBeFalse()
        ->and(IdeaVote::where('idea_id', $idea->id)->where('user_id', $user->id)->count())->toBe(0);
});

test('the vote count reflects other voters', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    $idea = makeIdea($team);

    IdeaVote::factory()->create(['idea_id' => $idea->id, 'user_id' => User::factory()->create()->id]);
    IdeaVote::factory()->create(['idea_id' => $idea->id, 'user_id' => User::factory()->create()->id]);

    $component = Livewire::actingAs($user)->test('pages::ideas.show', ['idea' => $idea->slug]);
    expect($component->instance()->voteCount)->toBe(2);

    $component->call('toggleVote');
    expect($component->instance()->voteCount)->toBe(3);

    $component->call('toggleVote');
    expect($component->instance()->voteCount)->toBe(2);
});

test('toggling repeatedly never creates a duplicate vote', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    $idea = makeIdea($team);

    $component = Livewire::actingAs($user)->test('pages::ideas.show', ['idea' => $idea->slug]);
    $component->call('toggleVote')->call('toggleVote')->call('toggleVote'); // vote, unvote, vote

    expect(IdeaVote::where('idea_id', $idea->id)->where('user_id', $user->id)->count())->toBe(1);
});

test('the database prevents duplicate votes for the same user and idea', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    $idea = makeIdea($team);

    IdeaVote::create(['idea_id' => $idea->id, 'user_id' => $user->id]);

    expect(fn () => IdeaVote::create(['idea_id' => $idea->id, 'user_id' => $user->id]))
        ->toThrow(QueryException::class);
});

test('a user cannot vote on another team\'s idea', function () {
    ['team' => $teamA] = teamWithMember(TeamRole::Owner);
    $ideaA = makeIdea($teamA);

    ['user' => $userB] = teamWithMember(TeamRole::Employee);

    // toggleVote scopes to the current team via findOrFail; a foreign idea id is
    // not found, which surfaces as a 404 in production (Livewire request goes
    // through the HTTP kernel). No vote is ever recorded.
    expect(fn () => Livewire::actingAs($userB)
        ->test('pages::ideas.index')
        ->call('toggleVote', $ideaA->id))
        ->toThrow(ModelNotFoundException::class);

    expect(IdeaVote::where('idea_id', $ideaA->id)->where('user_id', $userB->id)->count())->toBe(0);
});

// Current behavior: voting is NOT role-gated, so a viewer can vote. Reported for
// a follow-up decision; not changed here.
test('a viewer can currently vote (voting is not yet restricted for viewers)', function () {
    ['team' => $team, 'user' => $viewer] = teamWithMember(TeamRole::Viewer);
    $idea = makeIdea($team);

    Livewire::actingAs($viewer)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->call('toggleVote')
        ->assertHasNoErrors();

    expect(IdeaVote::where('idea_id', $idea->id)->where('user_id', $viewer->id)->count())->toBe(1);
});
