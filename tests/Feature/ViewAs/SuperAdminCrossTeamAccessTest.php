<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;

test('super admin can switch into a team they do not belong to', function () {
    ['team' => $ownTeam, 'superAdmin' => $superAdmin] = teamWithSuperAdmin();
    $foreignTeam = Team::factory()->create();

    expect($superAdmin->belongsToTeam($foreignTeam))->toBeFalse();

    $switched = $superAdmin->switchTeam($foreignTeam);

    expect($switched)->toBeTrue()
        ->and($superAdmin->fresh()->current_team_id)->toBe($foreignTeam->id);
});

test('non super admin cannot switch into a team they do not belong to', function () {
    ['team' => $ownTeam, 'user' => $user] = teamWithMember();
    $foreignTeam = Team::factory()->create();

    $switched = $user->switchTeam($foreignTeam);

    expect($switched)->toBeFalse()
        ->and($user->fresh()->current_team_id)->toBe($ownTeam->id);
});

test('super admin can browse a team scoped route for a team they do not belong to', function () {
    ['superAdmin' => $superAdmin] = teamWithSuperAdmin();
    $foreignTeam = Team::factory()->create();

    $this->actingAs($superAdmin);

    $this->get(route('dashboard', ['current_team' => $foreignTeam->slug]))->assertOk();
});

test('super admin bypasses minimum role requirements when browsing a foreign team', function () {
    ['superAdmin' => $superAdmin] = teamWithSuperAdmin();
    $foreignTeam = Team::factory()->create();
    $foreignTeam->members()->attach(User::factory()->create(), ['role' => TeamRole::Owner->value]);

    $this->actingAs($superAdmin);

    $this->get(route('ideas.settings', ['current_team' => $foreignTeam->slug]))->assertOk();
});

test('super admin sees their own teams and every other team as separate sections', function () {
    ['superAdmin' => $superAdmin] = teamWithSuperAdmin();
    Team::factory()->count(3)->create();

    $this->actingAs($superAdmin);

    $component = Livewire::test('team-switcher');
    $own = $component->instance()->ownedTeams()->merge($component->instance()->memberTeams());
    $other = $component->instance()->otherTeams();

    expect($own->pluck('id')->sort()->values()->all())
        ->toBe($superAdmin->teams()->pluck('teams.id')->sort()->values()->all())
        ->and($own->count() + $other->count())->toBe(Team::count())
        ->and($own->pluck('id')->intersect($other->pluck('id')))->toBeEmpty();
});

test('non super admin only sees their own teams and no other teams section', function () {
    ['team' => $ownTeam, 'user' => $user] = teamWithMember();
    Team::factory()->count(3)->create();

    $this->actingAs($user);

    $component = Livewire::test('team-switcher');
    $own = $component->instance()->ownedTeams()->merge($component->instance()->memberTeams());

    expect($own->pluck('id')->sort()->values()->all())
        ->toBe($user->teams()->pluck('teams.id')->sort()->values()->all())
        ->and($own->pluck('id'))->toContain($ownTeam->id)
        ->and($component->instance()->otherTeams())->toBeEmpty();
});

test('team switcher separates owned teams from teams the user has access to', function () {
    ['team' => $ownedTeam, 'user' => $owner] = teamWithMember(TeamRole::Owner);
    $memberTeam = Team::factory()->create();
    $memberTeam->members()->attach($owner, ['role' => TeamRole::Manager->value]);

    $this->actingAs($owner);

    $component = Livewire::test('team-switcher');

    expect($component->instance()->ownedTeams()->pluck('id')->all())->toContain($ownedTeam->id)
        ->and($component->instance()->ownedTeams()->pluck('id'))->not->toContain($memberTeam->id)
        ->and($component->instance()->memberTeams()->pluck('id')->all())->toBe([$memberTeam->id]);
});
