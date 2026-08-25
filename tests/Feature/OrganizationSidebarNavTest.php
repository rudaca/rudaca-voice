<?php

use App\Enums\TeamRole;
use App\Models\IdeaCategory;
use App\Models\Team;
use App\Models\User;

test('the organization nav group is collapsed by default on an ordinary page', function () {
    ['user' => $admin] = teamWithMember(TeamRole::Admin);

    $response = $this->actingAs($admin)->get(route('dashboard'));

    $response->assertOk();
    expect($response->getContent())
        ->toContain('data-test="organization-nav-group-toggle"')
        ->toContain('x-data="{ open: false }"');
});

test('the organization nav group auto-expands and highlights the active tab on the organization settings page', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);

    $response = $this->actingAs($admin)->get(route('ideas.settings', ['tab' => 'groups']));

    $response->assertOk()
        ->assertSeeText('Boards')
        ->assertSeeText('Groups')
        ->assertSeeText('Categories')
        ->assertSeeText('Contributors')
        ->assertSeeText('Settings');

    expect($response->getContent())
        ->toContain('x-data="{ open: true }"')
        ->toContain(route('ideas.settings', ['current_team' => $team->slug, 'tab' => 'groups']))
        ->toContain(route('ideas.settings', ['current_team' => $team->slug, 'tab' => 'boards']));
});

test('the organization nav group is absent for a user below admin', function () {
    ['user' => $employee] = teamWithMember(TeamRole::Employee);

    $response = $this->actingAs($employee)->get(route('dashboard'));

    $response->assertOk()->assertDontSee('data-test="organization-nav-group-toggle"', false);
});

test('the organization nav group has an icon-rail dropdown fallback for when the sidebar is collapsed', function () {
    ['user' => $admin] = teamWithMember(TeamRole::Admin);

    $response = $this->actingAs($admin)->get(route('dashboard'));

    $response->assertOk()
        ->assertSeeHtml('data-test="sidebar-nav-group-collapsed-trigger"')
        ->assertSeeHtml('data-test="sidebar-nav-group-collapsed-dropdown"');
});

test('each organization tab shows a total count badge except Authentication', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);
    $team->update(['allow_anonymous_ideas' => true, 'limit_one_active_vote_per_board' => true]);

    $stackOne = boardStack($team);
    boardStack($team);
    IdeaCategory::factory()->create(['board_id' => $stackOne['board']->id, 'team_id' => $team->id]);
    IdeaCategory::factory()->create(['board_id' => $stackOne['board']->id, 'team_id' => $team->id]);
    $team->members()->attach(User::factory()->create(), ['role' => TeamRole::Employee->value]);

    $response = $this->actingAs($owner)->get(route('dashboard', ['current_team' => $team->slug]));

    $response->assertOk();
    $html = $response->getContent();

    // 2 boards, 2 groups (one per boardStack), 2 categories, 2 members
    // (owner + attached employee), and 2 checked settings checkboxes.
    expect(tabAnchorHtml($html, $team, 'boards'))->toContain('data-test="rolling-number"')->toContain('>2<');
    expect(tabAnchorHtml($html, $team, 'groups'))->toContain('>2<');
    expect(tabAnchorHtml($html, $team, 'categories'))->toContain('>2<');
    expect(tabAnchorHtml($html, $team, 'members'))->toContain('>2<');
    expect(tabAnchorHtml($html, $team, 'settings'))->toContain('>2<');
    expect(tabAnchorHtml($html, $team, 'authentication'))->not->toContain('data-test="rolling-number"');

    // The expanded accordion and the collapsed dropdown both render every
    // tab link, so their rolling-number badges need distinct wire:keys.
    expect($html)
        ->toContain('rolling-org-tab-sidebar-boards-label')
        ->toContain('rolling-org-tab-dropdown-boards-label');
});

/**
 * Slice out one organization sidebar tab link's own HTML (from its <a href="...">
 * to the following </a>), so a count badge assertion can't accidentally match a
 * different tab's badge. Returns the first (expanded-accordion) copy.
 */
function tabAnchorHtml(string $html, Team $team, string $tab): string
{
    $needle = route('ideas.settings', ['current_team' => $team->slug, 'tab' => $tab]);
    $start = strpos($html, $needle);
    expect($start)->not->toBeFalse();

    $end = strpos($html, '</a>', $start);

    return substr($html, $start, $end - $start);
}
