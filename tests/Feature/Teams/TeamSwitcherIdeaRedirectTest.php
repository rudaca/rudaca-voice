<?php

use App\Enums\TeamRole;
use App\Models\Team;
use Livewire\Livewire;

// The team switcher rewrites the team segment of the current URL when switching
// organizations, which carries an idea's slug over into the new team even though
// idea slugs are only unique per team. `avoidDeadIdeaLink()` is what prevents that
// from landing on a dead / 404'd idea link. Livewire's testing harness doesn't
// preserve the Referer header across `->call()`, so we exercise the protected
// method directly rather than fighting the harness for an unrelated concern.
function callAvoidDeadIdeaLink(string $redirectTo, Team $team): string
{
    $component = Livewire::test('team-switcher')->instance();

    $method = new ReflectionMethod($component, 'avoidDeadIdeaLink');

    return $method->invoke($component, $redirectTo, $team);
}

test('switching to a team where the idea does not exist redirects to the dashboard', function () {
    ['team' => $teamA, 'user' => $user] = teamWithMember(TeamRole::Employee);
    $idea = makeIdea($teamA);

    $teamB = Team::factory()->create();
    $teamB->members()->attach($user, ['role' => TeamRole::Employee->value]);

    $this->actingAs($user);

    $redirectTo = callAvoidDeadIdeaLink(
        route('ideas.show', ['current_team' => $teamB->slug, 'idea' => $idea->slug]),
        $teamB,
    );

    expect($redirectTo)->toBe(route('dashboard', ['current_team' => $teamB->slug]));
});

test('switching to a team where the idea exists keeps the idea link', function () {
    ['user' => $user] = teamWithMember(TeamRole::Employee);

    $teamB = Team::factory()->create();
    $teamB->members()->attach($user, ['role' => TeamRole::Employee->value]);
    $ideaB = makeIdea($teamB);

    $this->actingAs($user);

    $target = route('ideas.show', ['current_team' => $teamB->slug, 'idea' => $ideaB->slug]);

    expect(callAvoidDeadIdeaLink($target, $teamB))->toBe($target);
});

test('non-idea routes are left untouched', function () {
    ['user' => $user] = teamWithMember(TeamRole::Employee);

    $teamB = Team::factory()->create();
    $teamB->members()->attach($user, ['role' => TeamRole::Employee->value]);

    $this->actingAs($user);

    $target = route('dashboard', ['current_team' => $teamB->slug]);

    expect(callAvoidDeadIdeaLink($target, $teamB))->toBe($target);
});
