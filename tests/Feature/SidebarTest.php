<?php

use App\Enums\TeamRole;
use App\Models\IdeaBoard;

test('the sidebar lists board groups and boards with their idea counts', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    $stack = boardStack($team);
    makeIdea($team, ['board_id' => $stack['board']->id, 'board_group_id' => $stack['group']->id]);

    $response = $this->actingAs($user)->get(route('dashboard', ['current_team' => $team->slug]));

    $response->assertOk()
        ->assertSee($stack['group']->name)
        ->assertSee($stack['board']->name);
});

test('employees and above see the submit idea link', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);

    $response = $this->actingAs($user)->get(route('dashboard', ['current_team' => $team->slug]));

    $response->assertOk()->assertSee('Submit Idea');
});

test('viewers do not see the submit idea link', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Viewer);

    $response = $this->actingAs($user)->get(route('dashboard', ['current_team' => $team->slug]));

    $response->assertOk()->assertDontSee('Submit Idea');
});

test('the main navigation is not hidden when the sidebar is collapsed', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);

    $response = $this->actingAs($user)->get(route('dashboard', ['current_team' => $team->slug]));

    // Flux's plain <flux:sidebar.group> (no heading/expandable) hides its
    // entire wrapper - icons included - when the sidebar is collapsed, so the
    // main nav items must not be wrapped in one. The attribute is always the
    // last one rendered on that wrapper, so checking for it immediately
    // followed by `>` avoids false positives from the always-present
    // `in-data-flux-sidebar-group-dropdown:*` utility classes on nav items.
    $response->assertOk()->assertDontSee('data-flux-sidebar-group>', false);
});

test('all ideas is only marked current in the sidebar when no board or group filter is active', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    $stack = boardStack($team);

    $unfiltered = $this->actingAs($user)->get(route('ideas.index'));
    expect(substr_count($unfiltered->getContent(), 'data-current="data-current"'))->toBe(1);

    $withBoard = $this->actingAs($user)->get(route('ideas.index', ['board' => $stack['board']->id]));
    expect(substr_count($withBoard->getContent(), 'data-current="data-current"'))->toBe(0);

    $withGroup = $this->actingAs($user)->get(route('ideas.index', ['group' => $stack['group']->id]));
    expect(substr_count($withGroup->getContent(), 'data-current="data-current"'))->toBe(0);
});

test('the active board is highlighted in the sidebar boards tree', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    $stack = boardStack($team);
    IdeaBoard::factory()->create([
        'team_id' => $team->id,
        'board_group_id' => $stack['group']->id,
        'created_by_user_id' => $user->id,
        'is_active' => true,
    ]);

    $response = $this->actingAs($user)->get(route('ideas.index', ['board' => $stack['board']->id]));
    $content = $response->getContent();

    $activeClasses = 'bg-zinc-800/5 font-semibold text-zinc-900 dark:bg-white/[7%] dark:text-white';
    $inactiveClasses = 'text-zinc-600 hover:bg-zinc-800/5 hover:text-zinc-900 dark:text-zinc-300 dark:hover:bg-white/[7%] dark:hover:text-white';

    // The boards tree is rendered twice (expanded desktop sidebar + collapsed-sidebar dropdown copy).
    expect(substr_count($content, $activeClasses))->toBe(2)
        ->and(substr_count($content, $inactiveClasses))->toBe(2);
});

test('only managers and above see the review queue link', function () {
    ['team' => $employeeTeam, 'user' => $employee] = teamWithMember(TeamRole::Employee);
    ['team' => $managerTeam, 'user' => $manager] = teamWithMember(TeamRole::Manager);

    $this->actingAs($employee)
        ->get(route('dashboard', ['current_team' => $employeeTeam->slug]))
        ->assertDontSee('Review Queue');

    $this->actingAs($manager)
        ->get(route('dashboard', ['current_team' => $managerTeam->slug]))
        ->assertSee('Review Queue');
});
