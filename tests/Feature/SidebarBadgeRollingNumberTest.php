<?php

use App\Enums\TeamRole;

test('the board and group count badges render as rolling-number odometers', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    $stack = boardStack($team);
    makeIdea($team, ['board_id' => $stack['board']->id, 'board_group_id' => $stack['group']->id]);

    $content = $this->actingAs($user)
        ->get(route('dashboard', ['current_team' => $team->slug]))
        ->assertOk()
        ->getContent();

    expect($content)->toContain('rolling-number-strip')
        ->toContain('rolling-sidebar-board-'.$stack['board']->id.'-0-1')
        ->toContain('rolling-sidebar-group-'.$stack['group']->id.'-0-1');
});

test('the two copies of the boards tree namespace their odometers separately', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    $stack = boardStack($team);

    // The tree renders twice - expanded in the sidebar and again inside the
    // collapsed sidebar's dropdown - and each digit column's wire:key has to
    // stay unique across the whole page. Anchoring on `wire:key="` keeps the
    // count off Livewire's own derived component keys, which echo the nearest
    // preceding wire:key back out.
    $content = $this->actingAs($user)
        ->get(route('dashboard', ['current_team' => $team->slug]))
        ->assertOk()
        ->getContent();

    $key = fn (string $scope) => 'wire:key="rolling-'.$scope.'-board-'.$stack['board']->id.'-0-0"';

    expect(substr_count($content, $key('sidebar')))->toBe(1)
        ->and(substr_count($content, $key('dropdown')))->toBe(1);
});

test('the review queue badge renders as a rolling-number odometer', function () {
    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);
    makeIdea($team, ['status' => 'new']);

    $content = $this->actingAs($manager)
        ->get(route('dashboard', ['current_team' => $team->slug]))
        ->assertOk()
        ->getContent();

    expect($content)->toContain('rolling-review-queue-0-1')
        ->toContain('data-flux-navlist-badge');
});

test('the review queue badge is omitted entirely when nothing is awaiting review', function () {
    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);

    $content = $this->actingAs($manager)
        ->get(route('dashboard', ['current_team' => $team->slug]))
        ->assertOk()
        ->getContent();

    expect($content)->toContain('Review Queue')
        ->not->toContain('rolling-review-queue');
});

test('the digit strips only animate once the page has finished loading', function () {
    $css = file_get_contents(resource_path('css/app.css'));
    $js = file_get_contents(resource_path('js/app.js'));

    // The parked transform is unconditional so the numbers are readable from
    // the first paint; only the roll waits for the load event.
    expect($css)->toContain('[data-rolling-ready] .rolling-number-strip')
        ->toContain('[data-rolling-ready] [data-active-tab] .rolling-number-strip');

    expect($js)->toContain("setAttribute('data-rolling-ready'")
        ->toContain("window.addEventListener('load', markRollingNumbersReady");
});
