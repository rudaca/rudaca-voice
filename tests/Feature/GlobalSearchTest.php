<?php

use App\Enums\TeamRole;
use App\Models\IdeaComment;
use Livewire\Livewire;

test('search finds ideas within the current team by title', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    $idea = makeIdea($team, ['title' => 'Improve onboarding flow']);

    Livewire::actingAs($user)
        ->test('global-search')
        ->set('query', 'onboarding')
        ->assertSee($idea->title);
});

test('search does not return another team\'s ideas', function () {
    ['team' => $teamA, 'user' => $userA] = teamWithMember(TeamRole::Employee);
    ['team' => $teamB] = teamWithMember(TeamRole::Employee);
    $ideaB = makeIdea($teamB, ['title' => 'Unique Cross Team Idea']);

    Livewire::actingAs($userA)
        ->test('global-search')
        ->set('query', 'Cross Team')
        ->assertDontSee($ideaB->title);
});

test('search matches board names', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    $stack = boardStack($team);

    Livewire::actingAs($user)
        ->test('global-search')
        ->set('query', mb_substr($stack['board']->name, 0, 4))
        ->assertSee($stack['board']->name);
});

test('search results show a person\'s board, idea and comment counts for the current team', function () {
    ['team' => $team, 'user' => $member] = teamWithMember(TeamRole::Employee);
    $member->update(['name' => 'Ada Lovelace']);

    $ideaOne = makeIdea($team, ['submitted_by_user_id' => $member->id]);
    makeIdea($team, ['submitted_by_user_id' => $member->id, 'board_id' => $ideaOne->board_id]);
    IdeaComment::factory()->create(['idea_id' => $ideaOne->id, 'user_id' => $member->id]);

    Livewire::actingAs($member)
        ->test('global-search')
        ->set('query', 'Ada')
        ->assertSee('Ada Lovelace')
        ->assertSee('1 board')
        ->assertSee('2 ideas')
        ->assertSee('1 comment');
});

test('queries shorter than two characters return no results', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    makeIdea($team, ['title' => 'Something searchable']);

    $component = Livewire::actingAs($user)->test('global-search')->set('query', 'a');

    expect($component->instance()->ideas)->toBeEmpty();
});
