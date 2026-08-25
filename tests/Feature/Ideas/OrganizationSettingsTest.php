<?php

use App\Enums\TeamRole;
use App\Models\Idea;
use App\Models\IdeaBoard;
use App\Models\IdeaBoardGroup;
use App\Models\IdeaCategory;
use App\Models\IdeaComment;
use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;

test('the organization settings page renders all five tabs and defaults to Boards', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    boardStack($team);

    $this->actingAs($admin)
        ->get(route('ideas.settings', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertSee('Organization Settings')
        ->assertSee('Boards')
        ->assertSee('Groups')
        ->assertSee('Categories')
        ->assertSee('Contributors')
        ->assertSee('Settings')
        ->assertDontSee('Integrations');
});

test('arriving via the header New menu opens the matching creation modal', function (string $new, string $modalName) {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);
    boardStack($team);

    Livewire::actingAs($owner)
        ->test('pages::ideas.settings', ['new' => $new])
        ->assertDispatched('modal-show', name: $modalName);
})->with([
    'board' => ['board', 'board'],
    'group' => ['group', 'board-group'],
    'category' => ['category', 'category'],
    'member' => ['member', 'member'],
]);

test('an admin arriving via the header New menu opens the matching board, group or category modal', function (string $new, string $modalName) {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    boardStack($team);

    Livewire::actingAs($admin)
        ->test('pages::ideas.settings', ['new' => $new])
        ->assertDispatched('modal-show', name: $modalName);
})->with([
    'board' => ['board', 'board'],
    'group' => ['group', 'board-group'],
    'category' => ['category', 'category'],
]);

test('an admin arriving via the header New menu with new=member is still forbidden from adding a member', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    boardStack($team);

    Livewire::actingAs($admin)
        ->test('pages::ideas.settings', ['new' => 'member'])
        ->assertForbidden();
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

test('the Members tab does not show a System Owner or Super Admin badge below the name', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    $systemOwner = User::factory()->systemOwner()->create();
    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $team->members()->attach($systemOwner, ['role' => TeamRole::Manager->value]);
    $team->members()->attach($superAdmin, ['role' => TeamRole::Employee->value]);

    Livewire::actingAs($admin)
        ->test('pages::ideas.settings')
        ->set('tab', 'members')
        ->assertSee($systemOwner->name)
        ->assertSee($superAdmin->name)
        ->assertDontSee('System Owner')
        ->assertDontSee('Super Admin');
});

test('the Members tab shows a plain user\'s org role below the name', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    $manager = User::factory()->create();
    $team->members()->attach($manager, ['role' => TeamRole::Manager->value]);

    Livewire::actingAs($admin)
        ->test('pages::ideas.settings')
        ->set('tab', 'members')
        ->assertSee('Manager')
        ->assertDontSee('System Owner')
        ->assertDontSee('Super Admin');
});

test('a user who is both a system owner and an organization owner keeps both sets of permissions', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);
    $owner->is_system_owner = true;
    $owner->save();

    // Org-level Owner permission: can still add/remove members on their own organization.
    Livewire::actingAs($owner)
        ->test('pages::ideas.settings')
        ->set('tab', 'members')
        ->assertSeeHtml('data-test="new-member"');

    // System-level permission: can still create additional organizations.
    Livewire::actingAs($owner)
        ->test('pages::teams.index')
        ->set('name', 'Another Org')
        ->call('createTeam')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('teams', ['name' => 'Another Org']);
});

test('the owner sees a New member button on the Members tab but an admin does not', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);
    $admin = User::factory()->create();
    $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);
    $admin->switchTeam($team);

    Livewire::actingAs($owner)
        ->test('pages::ideas.settings')
        ->set('tab', 'members')
        ->assertSeeHtml('data-test="new-member"');

    Livewire::actingAs($admin)
        ->test('pages::ideas.settings')
        ->set('tab', 'members')
        ->assertDontSeeHtml('data-test="new-member"');
});

test('the owner can search for an existing user and add them to the organization with a role', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);
    $newUser = User::factory()->create(['name' => 'Priya Patel', 'email' => 'priya.patel@example.com']);

    Livewire::actingAs($owner)
        ->test('pages::ideas.settings')
        ->set('tab', 'members')
        ->call('newMember')
        ->assertDispatched('modal-show', name: 'member')
        ->set('memberSearch', 'priya')
        ->assertSee($newUser->name)
        ->assertSee($newUser->email)
        ->call('selectMember', $newUser->id)
        ->assertSet('memberUserId', $newUser->id)
        ->set('memberRole', TeamRole::Manager->value)
        ->call('saveMember')
        ->assertHasNoErrors()
        ->assertDispatched('modal-close', name: 'member');

    expect($newUser->fresh()->belongsToTeam($team))->toBeTrue()
        ->and($team->members()->where('users.id', $newUser->id)->first()->pivot->role)->toBe(TeamRole::Manager);
});

test('searching for members to add excludes users already on the team', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);
    $existingMember = User::factory()->create(['name' => 'Existing Person']);
    $team->members()->attach($existingMember, ['role' => TeamRole::Employee->value]);

    Livewire::actingAs($owner)
        ->test('pages::ideas.settings')
        ->set('tab', 'members')
        ->call('newMember')
        ->set('memberSearch', 'Existing')
        ->assertDontSeeHtml('data-test="searchable-user-option"');
});

test('adding a member requires selecting a user first', function () {
    ['user' => $owner] = teamWithMember(TeamRole::Owner);

    Livewire::actingAs($owner)
        ->test('pages::ideas.settings')
        ->set('tab', 'members')
        ->call('newMember')
        ->call('saveMember')
        ->assertHasErrors(['memberUserId']);
});

test('a non-owner cannot add a member to the organization', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    $newUser = User::factory()->create();

    Livewire::actingAs($admin)
        ->test('pages::ideas.settings')
        ->set('tab', 'members')
        ->call('newMember')
        ->assertForbidden();

    expect($newUser->fresh()->belongsToTeam($team))->toBeFalse();
});

test('the owner sees a red Revoke Access icon for non-owner members but not for themselves', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);
    $manager = User::factory()->create();
    $team->members()->attach($manager, ['role' => TeamRole::Manager->value]);

    $component = Livewire::actingAs($owner)
        ->test('pages::ideas.settings')
        ->set('tab', 'members')
        ->assertSeeHtml('data-test="revoke-member-access"')
        ->assertSeeHtml('Revoke Access')
        ->assertSeeHtml('text-red-600!');

    // Two members exist (owner + manager) but only the manager's row should
    // carry the revoke action — the owner can never revoke their own access.
    expect(substr_count($component->html(), 'data-test="revoke-member-access"'))->toBe(1);
});

test('the revoke access modal warns that the member\'s ideas and comments will remain', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);
    $manager = User::factory()->create();
    $team->members()->attach($manager, ['role' => TeamRole::Manager->value]);

    Livewire::actingAs($owner)
        ->test('pages::ideas.settings')
        ->set('tab', 'members')
        ->call('confirmRemoveMember', $manager->id)
        ->assertSeeHtml('data-test="revoke-member-warning"')
        ->assertSee('The ideas and comments made by this user will remain.');
});

test('the owner can revoke a member\'s access, and their ideas and comments remain', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);
    $stack = boardStack($team, $owner);
    $manager = User::factory()->create();
    $team->members()->attach($manager, ['role' => TeamRole::Manager->value]);

    $idea = Idea::factory()->create(['board_id' => $stack['board']->id, 'submitted_by_user_id' => $manager->id]);
    $comment = IdeaComment::factory()->create(['idea_id' => $idea->id, 'user_id' => $manager->id]);

    Livewire::actingAs($owner)
        ->test('pages::ideas.settings')
        ->set('tab', 'members')
        ->call('confirmRemoveMember', $manager->id)
        ->assertDispatched('modal-show', name: 'revoke-member-access')
        ->assertSet('removeMemberName', $manager->name)
        ->call('removeMember')
        ->assertHasNoErrors()
        ->assertDispatched('modal-close', name: 'revoke-member-access');

    expect($manager->fresh()->belongsToTeam($team))->toBeFalse()
        ->and(Idea::find($idea->id))->not->toBeNull()
        ->and(IdeaComment::find($comment->id))->not->toBeNull();
});

test('revoking access switches the removed member off their now-inaccessible current team', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);
    $manager = User::factory()->create();
    $personalTeam = $manager->personalTeam();
    $team->members()->attach($manager, ['role' => TeamRole::Manager->value]);
    $manager->switchTeam($team);

    Livewire::actingAs($owner)
        ->test('pages::ideas.settings')
        ->set('tab', 'members')
        ->call('confirmRemoveMember', $manager->id)
        ->call('removeMember')
        ->assertHasNoErrors();

    expect($manager->fresh()->current_team_id)->toBe($personalTeam->id);
});

test('a non-owner cannot revoke a member\'s access', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    $manager = User::factory()->create();
    $team->members()->attach($manager, ['role' => TeamRole::Manager->value]);

    Livewire::actingAs($admin)
        ->test('pages::ideas.settings')
        ->set('tab', 'members')
        ->assertDontSeeHtml('data-test="revoke-member-access"')
        ->call('confirmRemoveMember', $manager->id)
        ->assertForbidden();

    expect($manager->fresh()->belongsToTeam($team))->toBeTrue();
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

test('the new board group modal auto-fills the slug as the admin types the name', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);

    Livewire::actingAs($admin)
        ->test('pages::ideas.settings')
        ->set('tab', 'groups')
        ->call('newBoardGroup')
        ->set('groupName', 'Customer Success')
        ->assertSet('groupSlug', 'customer-success')
        ->call('saveBoardGroup')
        ->assertHasNoErrors();

    $group = IdeaBoardGroup::where('team_id', $team->id)->where('name', 'Customer Success')->firstOrFail();

    expect($group->slug)->toBe('customer-success');
});

test('typing a custom group slug stops it from being overwritten as the name keeps changing', function () {
    ['user' => $admin] = teamWithMember(TeamRole::Admin);

    Livewire::actingAs($admin)
        ->test('pages::ideas.settings')
        ->set('tab', 'groups')
        ->call('newBoardGroup')
        ->set('groupName', 'Customer Success')
        ->assertSet('groupSlug', 'customer-success')
        ->set('groupSlug', 'cx-team')
        ->set('groupName', 'Customer Success Team')
        ->assertSet('groupSlug', 'cx-team');
});

test('editing an existing board group keeps its custom slug in sync only until the name diverges from it', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    $group = IdeaBoardGroup::factory()->create(['team_id' => $team->id, 'created_by_user_id' => $admin->id, 'name' => 'Ops', 'slug' => 'custom-ops-slug']);

    Livewire::actingAs($admin)
        ->test('pages::ideas.settings')
        ->set('tab', 'groups')
        ->call('editBoardGroup', $group->id)
        ->assertSet('groupSlug', 'custom-ops-slug')
        ->set('groupName', 'Operations')
        ->assertSet('groupSlug', 'custom-ops-slug');
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

// The Categories tab has no Livewire-tracked "open"/"collapsed" property — sections
// collapse client-side only (Alpine), so the full category list and every idea count
// are always present in the server-rendered HTML below, regardless of section state.
test('the Categories tab groups categories under their board, disambiguates same-named categories across boards, and preserves idea counts', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);

    $group = IdeaBoardGroup::factory()->create(['team_id' => $team->id, 'created_by_user_id' => $admin->id]);
    $boardA = IdeaBoard::factory()->create(['team_id' => $team->id, 'board_group_id' => $group->id, 'created_by_user_id' => $admin->id, 'name' => 'Accounting', 'sort_order' => 1]);
    $boardB = IdeaBoard::factory()->create(['team_id' => $team->id, 'board_group_id' => $group->id, 'created_by_user_id' => $admin->id, 'name' => 'Operations', 'sort_order' => 2]);

    $automationA = IdeaCategory::factory()->create(['team_id' => $team->id, 'board_id' => $boardA->id, 'name' => 'Automation', 'slug' => 'automation']);
    $reportingA = IdeaCategory::factory()->create(['team_id' => $team->id, 'board_id' => $boardA->id, 'name' => 'Reporting', 'slug' => 'reporting']);
    $automationB = IdeaCategory::factory()->create(['team_id' => $team->id, 'board_id' => $boardB->id, 'name' => 'Automation', 'slug' => 'automation']);

    Idea::factory()->count(2)->create(['team_id' => $team->id, 'board_id' => $boardA->id, 'category_id' => $automationA->id]);
    Idea::factory()->count(5)->create(['team_id' => $team->id, 'board_id' => $boardB->id, 'category_id' => $automationB->id]);

    $component = Livewire::actingAs($admin)
        ->test('pages::ideas.settings')
        ->set('tab', 'categories')
        ->assertSeeInOrder(['Accounting', 'Automation', 'Reporting', 'Operations', 'Automation'])
        ->assertSeeHtml("wire:key=\"category-board-{$boardA->id}\"")
        ->assertSeeHtml("wire:key=\"category-board-{$boardB->id}\"")
        ->assertSeeHtml("wire:key=\"category-{$automationA->id}\"")
        ->assertSeeHtml("wire:key=\"category-{$automationB->id}\"");

    $html = $component->html();

    $boardAPos = strpos($html, "wire:key=\"category-board-{$boardA->id}\"");
    $boardBPos = strpos($html, "wire:key=\"category-board-{$boardB->id}\"");
    $automationAPos = strpos($html, "wire:key=\"category-{$automationA->id}\"");
    $automationBPos = strpos($html, "wire:key=\"category-{$automationB->id}\"");

    // Same category name on two boards — each must sit inside its own board's
    // section rather than being ambiguous about which board it belongs to.
    expect($boardAPos)->not->toBeFalse()
        ->and($boardBPos)->toBeGreaterThan($boardAPos)
        ->and($automationAPos)->toBeGreaterThan($boardAPos)->toBeLessThan($boardBPos)
        ->and($automationBPos)->toBeGreaterThan($boardBPos);

    $snippet = function (int $categoryId) use ($html) {
        $start = strpos($html, "wire:key=\"category-{$categoryId}\"");

        return substr($html, $start, strpos($html, '</button>', $start) - $start);
    };

    expect($snippet($automationA->id))->toContain('2')
        ->and($snippet($reportingA->id))->not->toContain('data-flux-badge')
        ->and($snippet($automationB->id))->toContain('5');
});

test('a board with no categories shows the empty state without affecting other boards', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    $stack = boardStack($team);

    $emptyBoard = IdeaBoard::factory()->create([
        'team_id' => $team->id,
        'board_group_id' => $stack['group']->id,
        'created_by_user_id' => $admin->id,
        'name' => 'Empty Board',
    ]);

    $component = Livewire::actingAs($admin)
        ->test('pages::ideas.settings')
        ->set('tab', 'categories')
        ->assertSeeHtml("wire:key=\"category-board-{$emptyBoard->id}\"")
        ->assertSeeHtml("wire:key=\"category-{$stack['category']->id}\"");

    $html = $component->html();
    $emptyBoardPos = strpos($html, "wire:key=\"category-board-{$emptyBoard->id}\"");
    $nextSectionPos = strpos($html, 'wire:key="category-board-', $emptyBoardPos + 1) ?: strlen($html);

    expect(substr($html, $emptyBoardPos, $nextSectionPos - $emptyBoardPos))
        ->toContain('No categories yet.')
        ->not->toContain("wire:key=\"category-{$stack['category']->id}\"");
});

test('quick-adding a category without selecting a board is rejected server-side', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    boardStack($team);

    Livewire::actingAs($admin)
        ->test('pages::ideas.settings')
        ->set('tab', 'categories')
        ->set('quickCategoryBoardId', '')
        ->set('quickCategoryName', 'Automation')
        ->call('quickAddCategory')
        ->assertHasErrors(['quickCategoryBoardId' => 'required']);

    expect(IdeaCategory::where('team_id', $team->id)->where('name', 'Automation')->exists())->toBeFalse();
});

test('starting a quick add from a board section preselects and displays that board, and the saved category appears under it', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    $stack = boardStack($team);
    $board = $stack['board'];

    Livewire::actingAs($admin)
        ->test('pages::ideas.settings')
        ->set('tab', 'categories')
        ->call('startQuickAdd', $board->id)
        ->assertSet('quickCategoryBoardId', (string) $board->id)
        ->assertSet('quickAddBoardId', $board->id)
        ->assertSeeHtml('data-test="quick-add-category-board"')
        ->assertSee($board->name)
        ->set('quickCategoryName', 'Automation')
        ->call('quickAddCategory')
        ->assertHasNoErrors()
        ->assertSet('quickAddBoardId', null);

    $category = IdeaCategory::where('team_id', $team->id)->where('name', 'Automation')->firstOrFail();

    expect($category->board_id)->toBe($board->id);

    Livewire::actingAs($admin)
        ->test('pages::ideas.settings')
        ->set('tab', 'categories')
        ->assertSeeHtml("wire:key=\"category-{$category->id}\"");
});
