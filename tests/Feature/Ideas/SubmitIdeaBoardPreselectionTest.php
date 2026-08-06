<?php

use App\Enums\TeamRole;
use App\Models\Idea;
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
        ->assertSet('category_id', '')
        ->assertSet('boardLocked', true)
        ->assertSeeHtml('data-test="idea-board-context"')
        ->assertDontSeeHtml('data-test="idea-board-group"')
        ->assertDontSeeHtml('data-test="idea-board"')
        ->assertSee($group->name)
        ->assertSee($board->name);
});

test('opening submit idea from a board group scoped view does not lock the board group', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    ['group' => $group] = boardStack($team);

    Livewire::actingAs($user)
        ->withQueryParams(['group' => (string) $group->id])
        ->test('pages::ideas.create')
        ->assertSet('boardLocked', false)
        ->assertSeeHtml('data-test="idea-board-group"');
});

test('the locked board group cannot be changed by the client once inherited from a board', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    ['board' => $board] = boardStack($team);
    $otherStack = boardStack($team);

    Livewire::actingAs($user)
        ->withQueryParams(['board' => (string) $board->id])
        ->test('pages::ideas.create')
        ->set('board_group_id', (string) $otherStack['group']->id)
        ->assertSet('board_group_id', (string) $board->board_group_id);
});

test('the locked board cannot be changed by the client once inherited from a board', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    ['board' => $board] = boardStack($team);
    $otherStack = boardStack($team);

    Livewire::actingAs($user)
        ->withQueryParams(['board' => (string) $board->id])
        ->test('pages::ideas.create')
        ->set('board_id', (string) $otherStack['board']->id)
        ->assertSet('board_id', (string) $board->id);
});

test('a tampered board or board group is ignored on save and the idea is persisted with the locked board', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    ['group' => $group, 'board' => $board] = boardStack($team);
    $otherStack = boardStack($team);
    $category = IdeaCategory::factory()->create([
        'team_id' => $team->id,
        'board_id' => $board->id,
        'is_active' => true,
    ]);

    Livewire::actingAs($user)
        ->withQueryParams(['board' => (string) $board->id])
        ->test('pages::ideas.create')
        ->set('board_group_id', (string) $otherStack['group']->id)
        ->set('board_id', (string) $otherStack['board']->id)
        ->set('category_id', (string) $category->id)
        ->set('title', 'Tampered submission')
        ->set('description', 'Attempting to switch boards via a direct property update.')
        ->call('save')
        ->assertHasNoErrors();

    $idea = Idea::where('team_id', $team->id)->first();

    expect($idea)->not->toBeNull()
        ->and($idea->board_group_id)->toBe($group->id)
        ->and($idea->board_id)->toBe($board->id);
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
        ->assertSet('board_id', (string) $board->id)
        ->assertSet('boardLocked', true)
        ->assertSeeHtml('data-test="idea-board-context"')
        ->assertSee($group->name)
        ->assertSee($board->name);
});

test('a valid contextual submission persists the idea with the inherited board and board group', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    ['group' => $group, 'board' => $board] = boardStack($team);
    $category = IdeaCategory::factory()->create([
        'team_id' => $team->id,
        'board_id' => $board->id,
        'is_active' => true,
    ]);

    Livewire::actingAs($user)
        ->withQueryParams(['board' => (string) $board->id])
        ->test('pages::ideas.create')
        ->set('category_id', (string) $category->id)
        ->set('title', 'Contextual idea')
        ->set('description', 'Submitted from within a specific board.')
        ->call('save')
        ->assertHasNoErrors();

    $idea = Idea::where('team_id', $team->id)->first();

    expect($idea)->not->toBeNull()
        ->and($idea->board_group_id)->toBe($group->id)
        ->and($idea->board_id)->toBe($board->id)
        ->and($idea->category_id)->toBe($category->id);
});

test('a board_id foreign to the team supplied via the URL is not preselected and cannot be submitted', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    ['team' => $otherTeam] = teamWithMember(TeamRole::Owner);
    ['board' => $foreignBoard] = boardStack($otherTeam);

    Livewire::actingAs($user)
        ->withQueryParams(['board' => (string) $foreignBoard->id])
        ->test('pages::ideas.create')
        ->set('title', 'Should not be submittable')
        ->set('description', 'The board came from another team via the URL.')
        ->call('save')
        ->assertHasErrors(['board_group_id', 'board_id', 'category_id']);

    expect(Idea::where('team_id', $team->id)->count())->toBe(0)
        ->and(Idea::where('team_id', $otherTeam->id)->count())->toBe(0);
});

test('submitting a non-existent board_id is rejected with a validation error, not a server error', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    ['group' => $group] = boardStack($team);

    Livewire::actingAs($user)
        ->test('pages::ideas.create')
        ->set('board_group_id', (string) $group->id)
        ->set('board_id', '999999999')
        ->set('category_id', '999999999')
        ->set('title', 'Nonexistent board')
        ->set('description', 'This board_id does not exist at all.')
        ->call('save')
        ->assertHasErrors(['board_id', 'category_id']);

    expect(Idea::where('team_id', $team->id)->count())->toBe(0);
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
