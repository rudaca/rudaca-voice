<?php

use App\Enums\TeamRole;
use Livewire\Livewire;

test('duplicates are hidden by default', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);

    $duplicate = makeIdea($team, ['status' => 'duplicate']);
    $active = makeIdea($team, ['status' => 'new']);

    $component = Livewire::actingAs($user)->test('pages::ideas.index');

    $ids = $component->instance()->ideas->pluck('id')->all();

    expect($ids)->toContain($active->id)
        ->and($ids)->not->toContain($duplicate->id);
});

test('unchecking hide duplicates includes duplicate ideas in the results', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);

    $duplicate = makeIdea($team, ['status' => 'duplicate']);
    $active = makeIdea($team, ['status' => 'new']);

    $component = Livewire::actingAs($user)
        ->test('pages::ideas.index')
        ->set('hideDuplicates', false);

    $ids = $component->instance()->ideas->pluck('id')->all();

    expect($ids)->toContain($duplicate->id)
        ->toContain($active->id);
});

test('selecting the Duplicate status automatically unchecks hide duplicates', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);

    $duplicate = makeIdea($team, ['status' => 'duplicate']);

    $component = Livewire::actingAs($user)
        ->test('pages::ideas.index')
        ->assertSet('hideDuplicates', true)
        ->set('status', 'duplicate')
        ->assertSet('hideDuplicates', false);

    expect($component->instance()->ideas->pluck('id')->all())->toContain($duplicate->id);
});

test('hide duplicates resets pagination like other filters', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);

    makeIdea($team, ['status' => 'new']);
    makeIdea($team, ['status' => 'new']);

    $component = Livewire::actingAs($user)
        ->test('pages::ideas.index')
        ->call('gotoPage', 2);

    expect($component->instance()->ideas->currentPage())->toBe(2);

    $component->set('hideDuplicates', false);

    expect($component->instance()->ideas->currentPage())->toBe(1);
});
