<?php

use App\Enums\TeamRole;
use App\Models\IdeaCategory;
use App\Models\IdeaComment;
use App\Models\User;
use Livewire\Livewire;

test('status filter accepts multiple statuses', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);

    $new = makeIdea($team, ['status' => 'new']);
    $planned = makeIdea($team, ['status' => 'planned']);
    $released = makeIdea($team, ['status' => 'released']);

    $component = Livewire::actingAs($user)
        ->test('pages::ideas.index')
        ->set('status', ['new', 'planned']);

    $ids = $component->instance()->ideas->pluck('id')->all();

    expect($ids)->toContain($new->id)
        ->and($ids)->toContain($planned->id)
        ->and($ids)->not->toContain($released->id);
});

test('board filter accepts multiple boards', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);

    $stackOne = boardStack($team);
    $stackTwo = boardStack($team);
    $stackThree = boardStack($team);

    $ideaOne = makeIdea($team, ['board_id' => $stackOne['board']->id, 'board_group_id' => $stackOne['board']->board_group_id]);
    $ideaTwo = makeIdea($team, ['board_id' => $stackTwo['board']->id, 'board_group_id' => $stackTwo['board']->board_group_id]);
    $ideaThree = makeIdea($team, ['board_id' => $stackThree['board']->id, 'board_group_id' => $stackThree['board']->board_group_id]);

    $component = Livewire::actingAs($user)
        ->test('pages::ideas.index')
        ->set('board', [(string) $stackOne['board']->id, (string) $stackTwo['board']->id]);

    $ids = $component->instance()->ideas->pluck('id')->all();

    expect($ids)->toContain($ideaOne->id)
        ->and($ids)->toContain($ideaTwo->id)
        ->and($ids)->not->toContain($ideaThree->id);
});

test('category filter dedupes same-named categories across boards and matches ideas tagged under any of them', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);

    $stackOne = boardStack($team);
    $stackTwo = boardStack($team);

    $bugOne = IdeaCategory::factory()->create(['team_id' => $team->id, 'board_id' => $stackOne['board']->id, 'name' => 'Bug', 'is_active' => true]);
    $bugTwo = IdeaCategory::factory()->create(['team_id' => $team->id, 'board_id' => $stackTwo['board']->id, 'name' => 'Bug', 'is_active' => true]);

    $ideaOne = makeIdea($team, ['board_id' => $stackOne['board']->id, 'board_group_id' => $stackOne['board']->board_group_id, 'category_id' => $bugOne->id]);
    $ideaTwo = makeIdea($team, ['board_id' => $stackTwo['board']->id, 'board_group_id' => $stackTwo['board']->board_group_id, 'category_id' => $bugTwo->id]);
    $unrelated = makeIdea($team, ['category_id' => $stackOne['category']->id]);

    $component = Livewire::actingAs($user)->test('pages::ideas.index');

    expect($component->instance()->categories->filter(fn ($name) => $name === 'Bug')->count())->toBe(1);

    $component->set('category', ['Bug']);

    $ids = $component->instance()->ideas->pluck('id')->all();

    expect($ids)->toContain($ideaOne->id)
        ->and($ids)->toContain($ideaTwo->id)
        ->and($ids)->not->toContain($unrelated->id);
});

test('only-internal-comments filter restricts the list to ideas with internal comments, for managers and above', function () {
    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);

    $withInternal = makeIdea($team);
    IdeaComment::factory()->internal()->create(['idea_id' => $withInternal->id]);

    $withoutInternal = makeIdea($team);
    IdeaComment::factory()->create(['idea_id' => $withoutInternal->id]);

    $component = Livewire::actingAs($manager)
        ->test('pages::ideas.index')
        ->set('onlyInternalComments', true);

    $ids = $component->instance()->ideas->pluck('id')->all();

    expect($ids)->toContain($withInternal->id)
        ->and($ids)->not->toContain($withoutInternal->id);
});

test('only-internal-comments filter is ignored for roles below manager', function () {
    ['team' => $team, 'user' => $employee] = teamWithMember(TeamRole::Employee);

    $withInternal = makeIdea($team);
    IdeaComment::factory()->internal()->create(['idea_id' => $withInternal->id]);

    $withoutInternal = makeIdea($team);

    $component = Livewire::actingAs($employee)
        ->test('pages::ideas.index')
        ->set('onlyInternalComments', true);

    $ids = $component->instance()->ideas->pluck('id')->all();

    expect($ids)->toContain($withInternal->id)
        ->and($ids)->toContain($withoutInternal->id);
});

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
        ->set('author', [(string) $author->id]);

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

    $component->set('author', ['1']);

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
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Manager);

    $component = Livewire::actingAs($user)
        ->test('pages::ideas.index')
        ->set('status', ['new'])
        ->set('board', ['1'])
        ->set('category', ['Bug'])
        ->set('author', ['1'])
        ->set('search', 'refund')
        ->set('dateFrom', '2026-06-01')
        ->set('dateTo', '2026-06-30')
        ->set('hideDuplicates', true)
        ->set('onlyInternalComments', true)
        ->call('clearFilters');

    $component
        ->assertSet('status', [])
        ->assertSet('board', [])
        ->assertSet('category', [])
        ->assertSet('author', [])
        ->assertSet('search', '')
        ->assertSet('dateFrom', '')
        ->assertSet('dateTo', '')
        ->assertSet('hideDuplicates', false)
        ->assertSet('onlyInternalComments', false);

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
