<?php

use App\Enums\TeamRole;
use App\Models\User;
use Livewire\Livewire;

test('search filters ideas by title', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);

    $match = makeIdea($team, ['title' => 'Add dark mode toggle']);
    $other = makeIdea($team, ['title' => 'Improve refund processing time']);

    $component = Livewire::actingAs($user)
        ->test('pages::ideas.index')
        ->set('search', 'dark mode');

    $ids = $component->instance()->ideas->pluck('id')->all();

    expect($ids)->toContain($match->id)
        ->and($ids)->not->toContain($other->id);
});

test('author filter restricts the list to ideas submitted by that user', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    $author = $team->members()->first();

    $team->members()->attach($otherUser = User::factory()->create(), ['role' => TeamRole::Employee->value]);

    $ownIdea = makeIdea($team, ['submitted_by_user_id' => $author->id]);
    $othersIdea = makeIdea($team, ['submitted_by_user_id' => $otherUser->id]);

    $component = Livewire::actingAs($user)
        ->test('pages::ideas.index')
        ->set('author', (string) $author->id);

    $ids = $component->instance()->ideas->pluck('id')->all();

    expect($ids)->toContain($ownIdea->id)
        ->and($ids)->not->toContain($othersIdea->id);
});

test('date range filters ideas by created_at', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);

    $inRange = makeIdea($team, ['created_at' => '2026-06-15']);
    $tooEarly = makeIdea($team, ['created_at' => '2026-05-01']);
    $tooLate = makeIdea($team, ['created_at' => '2026-07-01']);

    $component = Livewire::actingAs($user)
        ->test('pages::ideas.index')
        ->set('dateFrom', '2026-06-01')
        ->set('dateTo', '2026-06-30');

    $ids = $component->instance()->ideas->pluck('id')->all();

    expect($ids)->toContain($inRange->id)
        ->and($ids)->not->toContain($tooEarly->id)
        ->and($ids)->not->toContain($tooLate->id);
});

test('the second row filters indicator is off by default and turns on when a tucked-away filter is used', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);

    $component = Livewire::actingAs($user)->test('pages::ideas.index');

    expect($component->instance()->hasSecondRowFilters)->toBeFalse();

    $component->set('author', '1');

    expect($component->instance()->hasSecondRowFilters)->toBeTrue();
});

test('hasActiveFilters is off by default and turns on when any filter is used', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);

    $component = Livewire::actingAs($user)->test('pages::ideas.index');

    expect($component->instance()->hasActiveFilters)->toBeFalse();

    $component->set('search', 'refund');

    expect($component->instance()->hasActiveFilters)->toBeTrue();
});

test('clearFilters resets every filter control back to its default', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);

    $component = Livewire::actingAs($user)
        ->test('pages::ideas.index')
        ->set('status', 'new')
        ->set('board', '1')
        ->set('category', '1')
        ->set('author', '1')
        ->set('search', 'refund')
        ->set('dateFrom', '2026-06-01')
        ->set('dateTo', '2026-06-30')
        ->set('hideDuplicates', true)
        ->call('clearFilters');

    $component
        ->assertSet('status', '')
        ->assertSet('board', '')
        ->assertSet('category', '')
        ->assertSet('author', '')
        ->assertSet('search', '')
        ->assertSet('dateFrom', '')
        ->assertSet('dateTo', '')
        ->assertSet('hideDuplicates', false);

    expect($component->instance()->hasActiveFilters)->toBeFalse();
});

test('clearFilters resets pagination', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);

    makeIdea($team, ['status' => 'new']);
    makeIdea($team, ['status' => 'new']);

    $component = Livewire::actingAs($user)
        ->test('pages::ideas.index')
        ->set('search', 'idea')
        ->call('gotoPage', 2);

    expect($component->instance()->ideas->currentPage())->toBe(2);

    $component->call('clearFilters');

    expect($component->instance()->ideas->currentPage())->toBe(1);
});

test('search and date range filters reset pagination like other filters', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);

    makeIdea($team, ['status' => 'new']);
    makeIdea($team, ['status' => 'new']);

    $component = Livewire::actingAs($user)
        ->test('pages::ideas.index')
        ->call('gotoPage', 2);

    expect($component->instance()->ideas->currentPage())->toBe(2);

    $component->set('search', 'idea');

    expect($component->instance()->ideas->currentPage())->toBe(1);
});
