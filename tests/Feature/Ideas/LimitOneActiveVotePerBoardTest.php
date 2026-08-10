<?php

use App\Enums\TeamRole;
use App\Models\IdeaVote;
use App\Models\Team;
use Livewire\Livewire;

test('the setting defaults to disabled', function () {
    $team = Team::factory()->create();

    expect($team->limitsOneActiveVotePerBoard())->toBeFalse();
});

test('an admin can enable the setting from organization settings', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);

    Livewire::actingAs($admin)
        ->test('pages::ideas.settings')
        ->set('orgLimitOneActiveVotePerBoard', true)
        ->call('saveTeamSettings')
        ->assertHasNoErrors();

    expect($team->fresh()->limitsOneActiveVotePerBoard())->toBeTrue();
});

test('with the setting disabled, voting for a second idea on the same board still creates a second vote', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    $stack = boardStack($team);
    $ideaA = makeIdea($team, ['board_id' => $stack['board']->id, 'status' => 'new']);
    $ideaB = makeIdea($team, ['board_id' => $stack['board']->id, 'status' => 'new']);

    Livewire::actingAs($user)->test('pages::ideas.show', ['idea' => $ideaA->slug])->call('toggleVote');
    Livewire::actingAs($user)->test('pages::ideas.show', ['idea' => $ideaB->slug])->call('toggleVote');

    expect(IdeaVote::where('user_id', $user->id)->count())->toBe(2);
});

test('with the setting enabled, voting when the user has no active vote on the board creates the vote directly', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    $team->update(['limit_one_active_vote_per_board' => true]);
    $idea = makeIdea($team, ['status' => 'new']);

    Livewire::actingAs($user)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->call('toggleVote')
        ->assertHasNoErrors();

    expect(IdeaVote::where('idea_id', $idea->id)->where('user_id', $user->id)->count())->toBe(1);
});

test('with the setting enabled, voting for a new idea while an active vote exists elsewhere opens the move-vote confirmation instead of creating a vote', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    $team->update(['limit_one_active_vote_per_board' => true]);
    $stack = boardStack($team);
    $ideaA = makeIdea($team, ['board_id' => $stack['board']->id, 'status' => 'new']);
    $ideaB = makeIdea($team, ['board_id' => $stack['board']->id, 'status' => 'new']);

    IdeaVote::create(['idea_id' => $ideaA->id, 'user_id' => $user->id]);

    $component = Livewire::actingAs($user)
        ->test('pages::ideas.show', ['idea' => $ideaB->slug])
        ->call('toggleVote');

    $component->assertDispatched('modal-show', name: 'confirm-move-vote');

    expect(IdeaVote::where('idea_id', $ideaB->id)->count())->toBe(0)
        ->and($component->instance()->pendingMoveFromIdeaId)->toBe($ideaA->id);
});

test('confirming a move-vote atomically removes the old vote and creates the new one', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    $team->update(['limit_one_active_vote_per_board' => true]);
    $stack = boardStack($team);
    $ideaA = makeIdea($team, ['board_id' => $stack['board']->id, 'status' => 'new']);
    $ideaB = makeIdea($team, ['board_id' => $stack['board']->id, 'status' => 'new']);

    IdeaVote::create(['idea_id' => $ideaA->id, 'user_id' => $user->id]);

    Livewire::actingAs($user)
        ->test('pages::ideas.show', ['idea' => $ideaB->slug])
        ->call('toggleVote')
        ->call('confirmMoveVote')
        ->assertHasNoErrors();

    expect(IdeaVote::where('idea_id', $ideaA->id)->where('user_id', $user->id)->count())->toBe(0)
        ->and(IdeaVote::where('idea_id', $ideaB->id)->where('user_id', $user->id)->count())->toBe(1)
        ->and(IdeaVote::where('user_id', $user->id)->count())->toBe(1);
});

test('moving a vote via the ideas index list works the same way', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    $team->update(['limit_one_active_vote_per_board' => true]);
    $stack = boardStack($team);
    $ideaA = makeIdea($team, ['board_id' => $stack['board']->id, 'status' => 'new']);
    $ideaB = makeIdea($team, ['board_id' => $stack['board']->id, 'status' => 'new']);

    IdeaVote::create(['idea_id' => $ideaA->id, 'user_id' => $user->id]);

    Livewire::actingAs($user)
        ->test('pages::ideas.index')
        ->call('toggleVote', $ideaB->id)
        ->assertDispatched('modal-show', name: "confirm-move-vote-{$ideaB->id}")
        ->call('confirmMoveVote')
        ->assertHasNoErrors();

    expect(IdeaVote::where('idea_id', $ideaA->id)->where('user_id', $user->id)->count())->toBe(0)
        ->and(IdeaVote::where('idea_id', $ideaB->id)->where('user_id', $user->id)->count())->toBe(1);
});

test('a status transition into each terminal status releases the vote for use elsewhere on the board', function (string $terminalStatus) {
    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);
    $team->update(['limit_one_active_vote_per_board' => true]);
    $stack = boardStack($team);
    $ideaA = makeIdea($team, ['board_id' => $stack['board']->id, 'status' => 'new']);
    $ideaB = makeIdea($team, ['board_id' => $stack['board']->id, 'status' => 'new']);
    $ideaC = makeIdea($team, ['board_id' => $stack['board']->id, 'status' => 'new']);

    IdeaVote::create(['idea_id' => $ideaA->id, 'user_id' => $manager->id]);

    expect(IdeaVote::activeVoteCountForUserOnBoard($manager->id, $stack['board']->id))->toBe(1);

    if ($terminalStatus === 'duplicate') {
        Livewire::actingAs($manager)
            ->test('pages::ideas.show', ['idea' => $ideaA->slug])
            ->set('duplicateOfId', (string) $ideaB->id)
            ->call('markDuplicate')
            ->assertHasNoErrors();
    } else {
        Livewire::actingAs($manager)
            ->test('pages::ideas.show', ['idea' => $ideaA->slug])
            ->set('status', $terminalStatus)
            ->call('updateManagement')
            ->assertHasNoErrors();
    }

    expect($ideaA->refresh()->status)->toBe($terminalStatus)
        ->and(IdeaVote::activeVoteCountForUserOnBoard($manager->id, $stack['board']->id))->toBe(0)
        ->and(IdeaVote::where('idea_id', $ideaA->id)->where('user_id', $manager->id)->count())->toBe(1);

    // The slot is free again: voting for another idea on the same board now
    // creates directly, with no move-vote confirmation.
    Livewire::actingAs($manager)
        ->test('pages::ideas.show', ['idea' => $ideaC->slug])
        ->call('toggleVote')
        ->assertNotDispatched('modal-show')
        ->assertHasNoErrors();

    expect(IdeaVote::where('idea_id', $ideaC->id)->where('user_id', $manager->id)->count())->toBe(1);
})->with(['released', 'not_doing', 'duplicate', 'archived']);

test('a user with multiple pre-existing active votes on a board is blocked from adding more but keeps the existing ones', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    $stack = boardStack($team);
    $ideaA = makeIdea($team, ['board_id' => $stack['board']->id, 'status' => 'new']);
    $ideaB = makeIdea($team, ['board_id' => $stack['board']->id, 'status' => 'new']);
    $ideaC = makeIdea($team, ['board_id' => $stack['board']->id, 'status' => 'new']);

    // Simulate two active votes acquired before the setting was ever enabled.
    IdeaVote::create(['idea_id' => $ideaA->id, 'user_id' => $user->id]);
    IdeaVote::create(['idea_id' => $ideaB->id, 'user_id' => $user->id]);

    $team->update(['limit_one_active_vote_per_board' => true]);

    Livewire::actingAs($user)
        ->test('pages::ideas.show', ['idea' => $ideaC->slug])
        ->call('toggleVote')
        ->assertDispatched('modal-show', name: 'blocked-multiple-votes')
        ->assertHasNoErrors();

    expect(IdeaVote::where('idea_id', $ideaC->id)->count())->toBe(0)
        ->and(IdeaVote::where('idea_id', $ideaA->id)->where('user_id', $user->id)->count())->toBe(1)
        ->and(IdeaVote::where('idea_id', $ideaB->id)->where('user_id', $user->id)->count())->toBe(1);
});

test('votes on different boards are independent of one another', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    $team->update(['limit_one_active_vote_per_board' => true]);
    $boardA = boardStack($team);
    $boardB = boardStack($team);
    $ideaOnA = makeIdea($team, ['board_id' => $boardA['board']->id, 'status' => 'new']);
    $ideaOnB = makeIdea($team, ['board_id' => $boardB['board']->id, 'status' => 'new']);

    IdeaVote::create(['idea_id' => $ideaOnA->id, 'user_id' => $user->id]);

    Livewire::actingAs($user)
        ->test('pages::ideas.show', ['idea' => $ideaOnB->slug])
        ->call('toggleVote')
        ->assertNotDispatched('modal-show')
        ->assertHasNoErrors();

    expect(IdeaVote::where('idea_id', $ideaOnB->id)->where('user_id', $user->id)->count())->toBe(1);
});

test('guests are redirected to the login page instead of voting', function () {
    ['team' => $team] = teamWithMember(TeamRole::Employee);
    $idea = makeIdea($team, ['status' => 'new']);

    $this->get(route('ideas.show', ['idea' => $idea->slug]))
        ->assertRedirect(route('login'));

    expect(IdeaVote::where('idea_id', $idea->id)->count())->toBe(0);
});

test('the board-vote-status header shows availability, assignment, and is hidden when the setting is disabled', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    $stack = boardStack($team);
    $ideaA = makeIdea($team, ['board_id' => $stack['board']->id, 'status' => 'new']);
    $ideaB = makeIdea($team, ['board_id' => $stack['board']->id, 'status' => 'new']);

    Livewire::actingAs($user)
        ->test('pages::ideas.show', ['idea' => $ideaA->slug])
        ->assertDontSeeHtml('data-test="board-vote-status"');

    $team->update(['limit_one_active_vote_per_board' => true]);

    Livewire::actingAs($user)
        ->test('pages::ideas.show', ['idea' => $ideaA->slug])
        ->assertSee('You have 1 vote available on this board.');

    IdeaVote::create(['idea_id' => $ideaA->id, 'user_id' => $user->id]);

    Livewire::actingAs($user)
        ->test('pages::ideas.show', ['idea' => $ideaB->slug])
        ->assertSee('Your vote is currently assigned to')
        ->assertSee($ideaA->title);
});

test('the board-vote-status header updates immediately after a vote is created, moved, and released', function () {
    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);
    $team->update(['limit_one_active_vote_per_board' => true]);
    $stack = boardStack($team);
    $ideaA = makeIdea($team, ['board_id' => $stack['board']->id, 'status' => 'new']);
    $ideaB = makeIdea($team, ['board_id' => $stack['board']->id, 'status' => 'new']);

    // Available -> assigned after casting a vote.
    $component = Livewire::actingAs($manager)
        ->test('pages::ideas.show', ['idea' => $ideaA->slug])
        ->assertSee('You have 1 vote available on this board.')
        ->call('toggleVote');

    $component->assertSee('Your vote is currently assigned to')
        ->assertSee($ideaA->title);

    // Assigned to A -> assigned to B after a confirmed move.
    $component = Livewire::actingAs($manager)
        ->test('pages::ideas.show', ['idea' => $ideaB->slug])
        ->call('toggleVote')
        ->call('confirmMoveVote');

    $component->assertSee('Your vote is currently assigned to')
        ->assertSee($ideaB->title);

    // Releasing the vote via a terminal status transition frees the slot again.
    Livewire::actingAs($manager)
        ->test('pages::ideas.show', ['idea' => $ideaB->slug])
        ->set('status', 'released')
        ->call('updateManagement');

    Livewire::actingAs($manager)
        ->test('pages::ideas.show', ['idea' => $ideaA->slug])
        ->assertSee('You have 1 vote available on this board.');
});
