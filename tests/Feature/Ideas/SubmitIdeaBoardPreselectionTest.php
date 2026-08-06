<?php

use App\Enums\TeamRole;
use App\Models\IdeaCategory;
use Livewire\Livewire;

test('opening submit idea from a specific board preselects its board group and board', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    ['group' => $group, 'board' => $board] = boardStack($team);

    Livewire::actingAs($user)
        ->withQueryParams(['board' => (string) $board->id])
        ->test('pages::ideas.create')
        ->assertSet('board_group_id', (string) $group->id)
        ->assertSet('board_id', (string) $board->id)
        ->assertSet('category_id', '');
});

test('opening submit idea from the general sidebar leaves board group and board unset', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    boardStack($team);

    Livewire::actingAs($user)
        ->test('pages::ideas.create')
        ->assertSet('board_group_id', '')
        ->assertSet('board_id', '');
});

test('the preselected board persists after a validation error', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    ['group' => $group, 'board' => $board] = boardStack($team);

    Livewire::actingAs($user)
        ->withQueryParams(['board' => (string) $board->id])
        ->test('pages::ideas.create')
        ->call('save')
        ->assertHasErrors(['title', 'description', 'category_id'])
        ->assertSet('board_group_id', (string) $group->id)
        ->assertSet('board_id', (string) $board->id);
});

test('a board belonging to another team is not preselected', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    ['team' => $otherTeam] = teamWithMember(TeamRole::Owner);
    ['board' => $foreignBoard] = boardStack($otherTeam);

    Livewire::actingAs($user)
        ->withQueryParams(['board' => (string) $foreignBoard->id])
        ->test('pages::ideas.create')
        ->assertSet('board_group_id', '')
        ->assertSet('board_id', '');
});

test('an inactive board is not preselected', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    ['board' => $board] = boardStack($team);
    $board->update(['is_active' => false]);

    Livewire::actingAs($user)
        ->withQueryParams(['board' => (string) $board->id])
        ->test('pages::ideas.create')
        ->assertSet('board_group_id', '')
        ->assertSet('board_id', '');
});

test('a non-numeric or missing board query parameter is ignored', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    boardStack($team);

    Livewire::actingAs($user)
        ->withQueryParams(['board' => 'not-a-number'])
        ->test('pages::ideas.create')
        ->assertSet('board_group_id', '')
        ->assertSet('board_id', '');
});

test('opening submit idea from a board group scoped view preselects only the board group', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    ['group' => $group] = boardStack($team);

    Livewire::actingAs($user)
        ->withQueryParams(['group' => (string) $group->id])
        ->test('pages::ideas.create')
        ->assertSet('board_group_id', (string) $group->id)
        ->assertSet('board_id', '');
});

test('a board takes precedence over a board group when both are present in the query string', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    ['group' => $group, 'board' => $board] = boardStack($team);
    ['group' => $otherGroup] = boardStack($team);

    Livewire::actingAs($user)
        ->withQueryParams(['board' => (string) $board->id, 'group' => (string) $otherGroup->id])
        ->test('pages::ideas.create')
        ->assertSet('board_group_id', (string) $group->id)
        ->assertSet('board_id', (string) $board->id);
});

test('a board group belonging to another team is not preselected', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    ['team' => $otherTeam] = teamWithMember(TeamRole::Owner);
    ['group' => $foreignGroup] = boardStack($otherTeam);

    Livewire::actingAs($user)
        ->withQueryParams(['group' => (string) $foreignGroup->id])
        ->test('pages::ideas.create')
        ->assertSet('board_group_id', '')
        ->assertSet('board_id', '');
});

test('opening submit idea from a board scoped to a single category preselects the category too', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    ['group' => $group, 'board' => $board] = boardStack($team);
    $category = IdeaCategory::factory()->create([
        'team_id' => $team->id,
        'board_id' => $board->id,
        'name' => 'Bug',
        'is_active' => true,
    ]);

    Livewire::actingAs($user)
        ->withQueryParams(['board' => (string) $board->id, 'category' => 'Bug'])
        ->test('pages::ideas.create')
        ->assertSet('board_group_id', (string) $group->id)
        ->assertSet('board_id', (string) $board->id)
        ->assertSet('category_id', (string) $category->id);
});

test('a category name that does not belong to the preselected board is not preselected', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    ['board' => $board] = boardStack($team);
    $otherBoardStack = boardStack($team);
    IdeaCategory::factory()->create([
        'team_id' => $team->id,
        'board_id' => $otherBoardStack['board']->id,
        'name' => 'Bug',
        'is_active' => true,
    ]);

    Livewire::actingAs($user)
        ->withQueryParams(['board' => (string) $board->id, 'category' => 'Bug'])
        ->test('pages::ideas.create')
        ->assertSet('board_id', (string) $board->id)
        ->assertSet('category_id', '');
});

test('an inactive category is not preselected', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    ['board' => $board] = boardStack($team);
    IdeaCategory::factory()->create([
        'team_id' => $team->id,
        'board_id' => $board->id,
        'name' => 'Bug',
        'is_active' => false,
    ]);

    Livewire::actingAs($user)
        ->withQueryParams(['board' => (string) $board->id, 'category' => 'Bug'])
        ->test('pages::ideas.create')
        ->assertSet('category_id', '');
});

test('a category is not preselected when only the board group is known', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    ['group' => $group, 'board' => $board] = boardStack($team);
    IdeaCategory::factory()->create([
        'team_id' => $team->id,
        'board_id' => $board->id,
        'name' => 'Bug',
        'is_active' => true,
    ]);

    Livewire::actingAs($user)
        ->withQueryParams(['group' => (string) $group->id, 'category' => 'Bug'])
        ->test('pages::ideas.create')
        ->assertSet('board_group_id', (string) $group->id)
        ->assertSet('board_id', '')
        ->assertSet('category_id', '');
});

test('the new idea button links to the board- and category-scoped create form when both are singly selected', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    ['board' => $board] = boardStack($team);
    IdeaCategory::factory()->create([
        'team_id' => $team->id,
        'board_id' => $board->id,
        'name' => 'Bug',
        'is_active' => true,
    ]);

    Livewire::actingAs($user)
        ->withQueryParams(['board' => [(string) $board->id], 'category' => ['Bug']])
        ->test('pages::ideas.index')
        ->assertSeeHtml(e(route('ideas.create', ['board' => $board->id, 'category' => 'Bug'])));
});

test('the new idea button omits category when more than one category is selected', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    ['board' => $board] = boardStack($team);

    Livewire::actingAs($user)
        ->withQueryParams(['board' => [(string) $board->id], 'category' => ['Bug', 'Feature']])
        ->test('pages::ideas.index')
        ->assertSeeHtml(route('ideas.create', ['board' => $board->id]));
});

test('the new idea button links to the board-scoped create form when viewing a single board', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    ['board' => $board] = boardStack($team);

    Livewire::actingAs($user)
        ->withQueryParams(['board' => [(string) $board->id]])
        ->test('pages::ideas.index')
        ->assertSeeHtml(route('ideas.create', ['board' => $board->id]));
});

test('the new idea button links to the unscoped create form when no single board is selected', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    boardStack($team);

    Livewire::actingAs($user)
        ->test('pages::ideas.index')
        ->assertSeeHtml(route('ideas.create'));
});

test('the new idea button links to the group-scoped create form when filtered by group with no single board', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    ['group' => $group] = boardStack($team);

    Livewire::actingAs($user)
        ->withQueryParams(['group' => (string) $group->id])
        ->test('pages::ideas.index')
        ->assertSeeHtml(route('ideas.create', ['group' => $group->id]));
});
