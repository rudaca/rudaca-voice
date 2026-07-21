<?php

use App\Enums\TeamRole;
use Livewire\Livewire;

test('a user does not see another team\'s idea in the All Ideas list', function () {
    ['team' => $teamA] = teamWithMember(TeamRole::Owner);
    $ideaA = makeIdea($teamA, ['title' => 'Team A only idea', 'status' => 'new']);

    ['user' => $userB] = teamWithMember(TeamRole::Employee);

    $component = Livewire::actingAs($userB)
        ->test('pages::ideas.index')
        ->assertOk()
        ->assertDontSee('Team A only idea');

    expect($component->instance()->ideas->pluck('id')->all())->not->toContain($ideaA->id);
});

test('a user cannot open another team\'s idea detail route', function () {
    ['team' => $teamA] = teamWithMember(TeamRole::Owner);
    $ideaA = makeIdea($teamA, ['title' => 'Team A only idea']);

    ['user' => $userB] = teamWithMember(TeamRole::Employee);

    // Hitting Team A's URL as a non-member is blocked by the membership middleware.
    $this->actingAs($userB)
        ->get(route('ideas.show', ['current_team' => $teamA->slug, 'idea' => $ideaA->slug]))
        ->assertForbidden();
});

test('another team\'s idea slug is not resolvable within your own team', function () {
    ['team' => $teamA] = teamWithMember(TeamRole::Owner);
    $ideaA = makeIdea($teamA, ['title' => 'Team A only idea']);

    ['team' => $teamB, 'user' => $userB] = teamWithMember(TeamRole::Employee);

    // Team B member using their own team URL cannot resolve Team A's idea slug.
    $this->actingAs($userB)
        ->get(route('ideas.show', ['current_team' => $teamB->slug, 'idea' => $ideaA->slug]))
        ->assertNotFound();
});

test('an owner sees and can open their own team\'s idea', function () {
    ['team' => $teamA, 'user' => $userA] = teamWithMember(TeamRole::Owner);
    $ideaA = makeIdea($teamA, ['title' => 'Team A visible idea', 'status' => 'new']);

    Livewire::actingAs($userA)
        ->test('pages::ideas.index')
        ->assertOk()
        ->assertSee('Team A visible idea');

    $this->actingAs($userA)
        ->get(route('ideas.show', ['current_team' => $teamA->slug, 'idea' => $ideaA->slug]))
        ->assertOk()
        ->assertSee('Team A visible idea');
});
