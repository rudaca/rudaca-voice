<?php

use App\Enums\TeamRole;
use Livewire\Livewire;

test('a legacy plain-text description renders unchanged, even containing literal angle brackets', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    $idea = makeIdea($team, [
        'description' => 'Please support <Select> components; revenue is < 10% of budget.',
        'description_format' => 'plain',
    ]);

    Livewire::actingAs($user)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->assertSee('Please support <Select> components; revenue is < 10% of budget.');
});

test('an html-format description actually renders formatting', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    $idea = makeIdea($team, [
        'description' => '<p>Plain paragraph with <strong>bold</strong> text.</p>',
        'description_format' => 'html',
    ]);

    $html = Livewire::actingAs($user)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->html();

    expect($html)->toContain('<strong>bold</strong>');
});
