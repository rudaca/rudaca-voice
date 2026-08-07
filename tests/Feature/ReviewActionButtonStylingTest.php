<?php

use App\Enums\TeamRole;
use Livewire\Livewire;

test('the review queue approve button is a solid dark button in light mode and inverts in dark mode', function () {
    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);
    makeIdea($team, ['status' => 'new', 'title' => 'Post-trip feedback survey automation']);

    $html = Livewire::actingAs($manager)
        ->test('pages::ideas.review')
        ->html();

    expect($html)
        ->toContain('bg-gray-950!')
        ->toContain('text-white!')
        ->toContain('dark:bg-white!')
        ->toContain('dark:text-gray-950!')
        ->not->toContain('text-teal-700!');
});

test('the review queue action buttons carry the circle-check-big and circle-x icons', function () {
    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);
    makeIdea($team, ['status' => 'new', 'title' => 'Post-trip feedback survey automation']);

    Livewire::actingAs($manager)
        ->test('pages::ideas.review')
        ->assertSeeHtml('M21.801 10A10 10 0 1 1 17 3.335')
        ->assertSeeHtml('m9 11 3 3L22 4')
        ->assertSeeHtml('m15 9-6 6');
});
