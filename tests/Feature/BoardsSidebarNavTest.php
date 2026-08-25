<?php

use App\Enums\TeamRole;

test('a board group is not hidden inside the collapsed sidebar\'s Boards dropdown', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    boardStack($team);

    $response = $this->actingAs($admin)->get(route('dashboard'));

    $response->assertOk();

    // Each board group renders twice (the always-in-DOM expanded copy, and
    // the icon-rail dropdown copy) — regression check for a bug where the
    // group's own wrapper carried `in-data-flux-sidebar-collapsed-desktop:hidden`,
    // which — being an ancestor-attribute match — hid the group inside the
    // dropdown too, since the dropdown panel is still a DOM descendant of
    // the (collapsed) sidebar.
    expect(substr_count($response->getContent(), 'data-test="board-group-toggle"'))->toBeGreaterThanOrEqual(2);
    expect($response->getContent())->toContain('<div x-data="{ open: true }">');
});
