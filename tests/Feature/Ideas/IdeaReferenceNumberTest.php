<?php

use App\Enums\TeamRole;
use Livewire\Livewire;

test('the idea reference number renders using the id column', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    $idea = makeIdea($team, ['title' => 'Improve onboarding flow']);

    Livewire::actingAs($user)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->assertSeeHtml('data-test="idea-reference"')
        ->assertSee("Idea #{$idea->id}");
});

test('a viewer sees the idea reference number without any extra permission gating', function () {
    ['team' => $team, 'user' => $viewer] = teamWithMember(TeamRole::Viewer);
    $idea = makeIdea($team);

    Livewire::actingAs($viewer)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->assertSee("Idea #{$idea->id}");
});
