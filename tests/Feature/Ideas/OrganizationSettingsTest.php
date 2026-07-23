<?php

use App\Enums\TeamRole;
use App\Models\IdeaBoard;
use App\Models\IdeaBoardGroup;
use App\Models\IdeaCategory;
use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;

test('the organization settings page renders all four tabs and defaults to Boards', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    boardStack($team);

    $this->actingAs($admin)
        ->get(route('ideas.settings', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertSee('Organization settings')
        ->assertSee('Boards')
        ->assertSee('Categories')
        ->assertSee('Members')
        ->assertSee('Settings')
        ->assertDontSee('Integrations');
});

test('the Members tab lists team members with their role', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    $manager = User::factory()->create();
    $team->members()->attach($manager, ['role' => TeamRole::Manager->value]);

    Livewire::actingAs($admin)
        ->test('pages::ideas.settings')
        ->set('tab', 'members')
        ->assertSee($admin->name)
        ->assertSee($manager->name)
        ->assertSee($manager->email)
        ->assertSee('Manager');
});

test('the Settings tab lets an admin update the team name and anonymous ideas preference', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);

    Livewire::actingAs($admin)
        ->test('pages::ideas.settings')
        ->set('tab', 'settings')
        ->assertSet('orgTeamName', $team->name)
        ->assertSet('orgAllowAnonymousIdeas', true)
        ->set('orgTeamName', 'Renamed Team')
        ->set('orgAllowAnonymousIdeas', false)
        ->call('saveTeamSettings')
        ->assertHasNoErrors();

    $team = Team::find($team->id);

    expect($team->name)->toBe('Renamed Team')
        ->and($team->allowsAnonymousIdeas())->toBeFalse();
});

test('the Settings tab requires a team name', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);

    Livewire::actingAs($admin)
        ->test('pages::ideas.settings')
        ->set('tab', 'settings')
        ->set('orgTeamName', '')
        ->call('saveTeamSettings')
        ->assertHasErrors(['orgTeamName']);

    expect($team->fresh()->name)->toBe($team->name);
});

test('the new board modal only asks for a name and group, auto-filling team and slug', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    $group = IdeaBoardGroup::factory()->create(['team_id' => $team->id, 'created_by_user_id' => $admin->id]);

    Livewire::actingAs($admin)
        ->test('pages::ideas.settings')
        ->set('tab', 'boards')
        ->call('newBoard')
        ->assertDontSeeHtml('wire:model="boardSlug"')
        ->assertDontSeeHtml('wire:model="boardDescription"')
        ->assertDontSeeHtml('wire:model="boardVisibility"')
        ->assertDontSeeHtml('wire:model="boardIsActive"')
        ->set('boardName', 'Customer Support')
        ->set('boardGroupId', (string) $group->id)
        ->call('saveBoard')
        ->assertHasNoErrors();

    $board = IdeaBoard::where('name', 'Customer Support')->firstOrFail();

    expect($board->team_id)->toBe($team->id)
        ->and($board->slug)->toBe('customer-support')
        ->and($board->board_group_id)->toBe($group->id)
        ->and($board->visibility)->toBe('internal')
        ->and($board->is_active)->toBeTrue();
});

test('editing an existing board still exposes slug, description, visibility and active fields', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    $stack = boardStack($team, $admin);

    Livewire::actingAs($admin)
        ->test('pages::ideas.settings')
        ->set('tab', 'boards')
        ->call('editBoard', $stack['board']->id)
        ->assertSeeHtml('wire:model="boardSlug"')
        ->assertSeeHtml('wire:model="boardDescription"')
        ->assertSeeHtml('wire:model="boardVisibility"')
        ->assertSeeHtml('wire:model="boardIsActive"');
});

test('quick-adding a category on the Categories tab creates it against the chosen board', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    $stack = boardStack($team);

    Livewire::actingAs($admin)
        ->test('pages::ideas.settings')
        ->set('tab', 'categories')
        ->set('quickCategoryBoardId', (string) $stack['board']->id)
        ->set('quickCategoryName', 'Automation')
        ->call('quickAddCategory')
        ->assertHasNoErrors();

    $category = IdeaCategory::where('team_id', $team->id)->where('name', 'Automation')->first();

    expect($category)->not->toBeNull()
        ->and($category->board_id)->toBe($stack['board']->id)
        ->and($category->is_active)->toBeTrue();
});
