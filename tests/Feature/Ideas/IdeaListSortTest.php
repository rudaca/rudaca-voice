<?php

use App\Enums\TeamRole;
use App\Models\IdeaComment;
use App\Models\IdeaVote;
use Livewire\Livewire;

test('top voted sort ranks by vote count alone', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);

    $mostVoted = makeIdea($team, ['title' => 'Most voted idea']);
    IdeaVote::factory()->count(5)->create(['idea_id' => $mostVoted->id]);

    $mostDiscussed = makeIdea($team, ['title' => 'Most discussed idea']);
    IdeaVote::factory()->count(1)->create(['idea_id' => $mostDiscussed->id]);
    IdeaComment::factory()->count(3)->create(['idea_id' => $mostDiscussed->id]);

    $component = Livewire::actingAs($user)
        ->test('pages::ideas.index')
        ->call('sortBy', 'top');

    expect($component->instance()->ideas->pluck('id')->all())
        ->toBe([$mostVoted->id, $mostDiscussed->id]);
});

test('trending sort weighs comments alongside votes', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);

    $mostVoted = makeIdea($team, ['title' => 'Most voted idea']);
    IdeaVote::factory()->count(5)->create(['idea_id' => $mostVoted->id]);

    $mostDiscussed = makeIdea($team, ['title' => 'Most discussed idea']);
    IdeaVote::factory()->count(1)->create(['idea_id' => $mostDiscussed->id]);
    IdeaComment::factory()->count(3)->create(['idea_id' => $mostDiscussed->id]);

    $component = Livewire::actingAs($user)
        ->test('pages::ideas.index')
        ->call('sortBy', 'trending');

    expect($component->instance()->ideas->pluck('id')->all())
        ->toBe([$mostDiscussed->id, $mostVoted->id]);
});

test('an invalid sort value falls back to newest', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);

    $component = Livewire::actingAs($user)
        ->test('pages::ideas.index')
        ->call('sortBy', 'not-a-real-sort');

    expect($component->instance()->sort)->toBe('newest');
});
