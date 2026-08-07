<?php

use App\Enums\TeamRole;
use Livewire\Livewire;

test('the ideas list card is not clickable and only the title shows the hand cursor with an underline', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    makeIdea($team, ['title' => 'Post-trip feedback survey automation']);

    $html = Livewire::actingAs($user)
        ->test('pages::ideas.index')
        ->assertSeeHtml('class="min-w-0 cursor-pointer"')
        ->assertSeeHtml('hover:underline')
        ->html();

    expect($html)->not->toContain('flex cursor-pointer gap-4 rounded-xl');
});

test('the dashboard idea card is not clickable and only the title shows the hand cursor with an underline', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    makeIdea($team, ['title' => 'Post-trip feedback survey automation']);

    $html = Livewire::actingAs($user)
        ->test('pages::dashboard')
        ->assertSeeHtml('class="min-w-0 cursor-pointer"')
        ->assertSeeHtml('hover:underline')
        ->html();

    expect($html)->not->toContain('flex cursor-pointer items-start gap-4 rounded-xl');
});

test('the review queue card is not clickable and only the title shows the hand cursor with an underline', function () {
    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);
    makeIdea($team, ['status' => 'new', 'title' => 'Post-trip feedback survey automation']);

    $html = Livewire::actingAs($manager)
        ->test('pages::ideas.review')
        ->assertSeeHtml('class="cursor-pointer hover:underline"')
        ->html();

    expect($html)->not->toContain('cursor-pointer gap-4 rounded-xl');
});
