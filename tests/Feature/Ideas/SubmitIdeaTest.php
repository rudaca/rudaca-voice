<?php

use App\Enums\TeamRole;
use App\Models\Idea;
use Illuminate\Support\Str;
use Livewire\Livewire;

test('an employee can submit an idea scoped to their team', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    ['group' => $group, 'board' => $board, 'category' => $category] = boardStack($team);

    $title = 'Improve the coffee machine SOP';
    $slug = Str::slug($title);

    Livewire::actingAs($user)
        ->test('pages::ideas.create')
        ->set('board_group_id', (string) $group->id)
        ->set('board_id', (string) $board->id)
        ->set('category_id', (string) $category->id)
        ->set('title', $title)
        ->set('description', 'Document how to clean and restock it each morning.')
        ->set('is_anonymous', true)
        ->set('is_private', false)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('ideas.show', ['current_team' => $team->slug, 'idea' => $slug]));

    $idea = Idea::where('team_id', $team->id)->where('slug', $slug)->first();

    expect($idea)->not->toBeNull()
        ->and($idea->team_id)->toBe($team->id)
        ->and($idea->board_group_id)->toBe($group->id)
        ->and($idea->board_id)->toBe($board->id)
        ->and($idea->category_id)->toBe($category->id)
        ->and($idea->submitted_by_user_id)->toBe($user->id)
        ->and($idea->title)->toBe($title)
        ->and($idea->description)->toBe('Document how to clean and restock it each morning.')
        ->and($idea->status)->toBe('new')
        ->and($idea->is_anonymous)->toBeTrue()
        ->and($idea->is_private)->toBeFalse();
});

test('idea slugs are unique within a team', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    ['group' => $group, 'board' => $board, 'category' => $category] = boardStack($team);

    $submit = fn () => Livewire::actingAs($user)
        ->test('pages::ideas.create')
        ->set('board_group_id', (string) $group->id)
        ->set('board_id', (string) $board->id)
        ->set('category_id', (string) $category->id)
        ->set('title', 'Reduce onboarding time')
        ->set('description', 'A repeated title used twice.')
        ->call('save')
        ->assertHasNoErrors();

    $submit();
    $submit();

    $slugs = Idea::where('team_id', $team->id)->pluck('slug');

    expect($slugs)->toHaveCount(2)
        ->and($slugs->unique())->toHaveCount(2)
        ->and($slugs->all())->toContain('reduce-onboarding-time');
});

test('submitting requires title, description, board group, board and category', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);

    Livewire::actingAs($user)
        ->test('pages::ideas.create')
        ->call('save')
        ->assertHasErrors(['title', 'description', 'board_group_id', 'board_id', 'category_id']);

    expect(Idea::where('team_id', $team->id)->count())->toBe(0);
});

test('cannot submit with a board group, board or category from another team', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    ['team' => $otherTeam] = teamWithMember(TeamRole::Owner);
    ['group' => $foreignGroup, 'board' => $foreignBoard, 'category' => $foreignCategory] = boardStack($otherTeam);

    Livewire::actingAs($user)
        ->test('pages::ideas.create')
        ->set('board_group_id', (string) $foreignGroup->id)
        ->set('board_id', (string) $foreignBoard->id)
        ->set('category_id', (string) $foreignCategory->id)
        ->set('title', 'Sneaky cross-team idea')
        ->set('description', 'Should not be allowed.')
        ->call('save')
        ->assertHasErrors(['board_group_id', 'board_id', 'category_id']);

    expect(Idea::where('team_id', $team->id)->count())->toBe(0)
        ->and(Idea::where('team_id', $otherTeam->id)->count())->toBe(0);
});

test('the board must belong to the selected board group', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    $stackA = boardStack($team);
    $stackB = boardStack($team);

    // Group from stack A, but a board that belongs to stack B's group.
    Livewire::actingAs($user)
        ->test('pages::ideas.create')
        ->set('board_group_id', (string) $stackA['group']->id)
        ->set('board_id', (string) $stackB['board']->id)
        ->set('category_id', (string) $stackB['category']->id)
        ->set('title', 'Mismatched group and board')
        ->set('description', 'Board does not belong to the chosen group.')
        ->call('save')
        ->assertHasErrors(['board_id']);
});

test('the category must belong to the selected board', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    $stackA = boardStack($team);
    $stackB = boardStack($team);

    // Valid group + board from stack A, but a category from stack B's board.
    Livewire::actingAs($user)
        ->test('pages::ideas.create')
        ->set('board_group_id', (string) $stackA['group']->id)
        ->set('board_id', (string) $stackA['board']->id)
        ->set('category_id', (string) $stackB['category']->id)
        ->set('title', 'Mismatched board and category')
        ->set('description', 'Category does not belong to the chosen board.')
        ->call('save')
        ->assertHasErrors(['category_id']);
});
