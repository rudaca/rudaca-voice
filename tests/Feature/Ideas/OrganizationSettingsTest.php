<?php

use App\Enums\TeamRole;
use App\Models\IdeaBoard;
use App\Models\IdeaBoardGroup;
use App\Models\IdeaCategory;
use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;

test('the organization settings page renders all five tabs and defaults to Boards', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    boardStack($team);

    $this->actingAs($admin)
        ->get(route('ideas.settings', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertSee('Organization settings')
        ->assertSee('Boards')
        ->assertSee('Groups')
        ->assertSee('Categories')
        ->assertSee('Members')
        ->assertSee('Settings')
        ->assertDontSee('Integrations');
});

test('the Groups tab lists board groups with their own New group button, and the old Manage groups modal is gone', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    $stack = boardStack($team, $admin);

    Livewire::actingAs($admin)
        ->test('pages::ideas.settings')
        ->set('tab', 'groups')
        ->assertSee($stack['group']->name)
        ->assertSeeHtml('data-test="new-group"')
        ->assertDontSee('Manage groups')
        ->assertDontSeeHtml('data-test="manage-groups"');
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

test('opening the new board modal dispatches the event Flux actually listens for', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);

    Livewire::actingAs($admin)
        ->test('pages::ideas.settings')
        ->set('tab', 'boards')
        ->call('newBoard')
        // Flux's <flux:modal> only reacts to a "modal-show" browser event (see
        // vendor/livewire/flux/src/Concerns/InteractsWithComponents.php). Dispatching
        // anything else (e.g. the wire-elements/modal-style "open-modal") is a silent
        // no-op: no error, no modal, which is exactly what was reported as "the button
        // doesn't work".
        ->assertDispatched('modal-show', name: 'board')
        ->assertNotDispatched('open-modal');
});

test('the new board modal shows a read-only auto-generated slug alongside name and group', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    $group = IdeaBoardGroup::factory()->create(['team_id' => $team->id, 'created_by_user_id' => $admin->id]);

    Livewire::actingAs($admin)
        ->test('pages::ideas.settings')
        ->set('tab', 'boards')
        ->call('newBoard')
        ->assertSeeHtml('wire:model="boardSlug"')
        ->assertSeeHtml('readonly')
        ->assertSeeHtml('wire:model="boardDescription"')
        ->assertSet('boardVisibility', 'internal')
        ->assertSet('boardIsActive', '1')
        ->set('boardName', 'Customer Support')
        ->assertSet('boardSlug', 'customer-support')
        ->set('boardGroupId', (string) $group->id)
        ->call('saveBoard')
        ->assertHasNoErrors();

    $board = IdeaBoard::where('name', 'Customer Support')->firstOrFail();

    expect($board->team_id)->toBe($team->id)
        ->and($board->slug)->toBe('customer-support')
        ->and($board->board_group_id)->toBe($group->id)
        ->and($board->visibility)->toBe('internal')
        ->and($board->is_active)->toBeTrue()
        ->and($board->created_by_user_id)->toBe($admin->id)
        ->and($board->description)->toBeNull();
});

test('the auto-generated slug is suffixed to stay unique when two boards share a name', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    IdeaBoard::factory()->create(['team_id' => $team->id, 'created_by_user_id' => $admin->id, 'name' => 'Support', 'slug' => 'support']);

    Livewire::actingAs($admin)
        ->test('pages::ideas.settings')
        ->set('tab', 'boards')
        ->call('newBoard')
        ->set('boardName', 'Support')
        ->assertSet('boardSlug', 'support-2')
        ->call('saveBoard')
        ->assertHasNoErrors();

    expect(IdeaBoard::where('team_id', $team->id)->where('name', 'Support')->where('slug', 'support-2')->exists())->toBeTrue();
});

test('the read-only slug cannot be tampered with — the server always recomputes it from the name', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);

    Livewire::actingAs($admin)
        ->test('pages::ideas.settings')
        ->set('tab', 'boards')
        ->call('newBoard')
        ->set('boardName', 'Support')
        ->set('boardSlug', 'not-what-the-server-would-generate')
        ->call('saveBoard')
        ->assertHasNoErrors();

    $board = IdeaBoard::where('name', 'Support')->firstOrFail();

    expect($board->slug)->toBe('support');
});

test('editing an existing board exposes slug, description, visibility and active fields', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    $stack = boardStack($team, $admin);

    Livewire::actingAs($admin)
        ->test('pages::ideas.settings')
        ->set('tab', 'boards')
        ->call('editBoard', $stack['board']->id)
        ->assertSeeHtml('wire:model="boardSlug"')
        ->assertSeeHtml('wire:model="boardDescription"')
        ->assertSet('boardVisibility', $stack['board']->visibility)
        ->assertSet('boardIsActive', $stack['board']->is_active ? '1' : '0');
});

test('the boards list can be filtered by board group, defaulting to All Groups', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    $stack = boardStack($team, $admin);

    $otherGroup = IdeaBoardGroup::factory()->create(['team_id' => $team->id, 'created_by_user_id' => $admin->id]);
    $otherBoard = IdeaBoard::factory()->create([
        'team_id' => $team->id,
        'board_group_id' => $otherGroup->id,
        'created_by_user_id' => $admin->id,
        'name' => 'Filtered Board',
    ]);

    // Assert against the board list rows (wire:key="board-{id}") rather than the board
    // name text, since board names also appear as <option> values inside the always-rendered
    // "New category" modal's board select, which would make assertSee/assertDontSee unreliable.
    Livewire::actingAs($admin)
        ->test('pages::ideas.settings')
        ->set('tab', 'boards')
        ->assertSet('boardGroupFilter', '')
        ->assertSeeHtml("wire:key=\"board-{$stack['board']->id}\"")
        ->assertSeeHtml("wire:key=\"board-{$otherBoard->id}\"")
        ->set('boardGroupFilter', (string) $otherGroup->id)
        ->assertDontSeeHtml("wire:key=\"board-{$stack['board']->id}\"")
        ->assertSeeHtml("wire:key=\"board-{$otherBoard->id}\"")
        ->set('boardGroupFilter', '')
        ->assertSeeHtml("wire:key=\"board-{$stack['board']->id}\"")
        ->assertSeeHtml("wire:key=\"board-{$otherBoard->id}\"");
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
