<?php

use App\Actions\ViewAs\StartViewAsSession;
use App\Enums\TeamRole;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

test('view as switcher lists roles with eligible member counts and excludes super admins', function () {
    ['team' => $team, 'superAdmin' => $superAdmin] = teamWithSuperAdmin();

    $employeeOne = User::factory()->create();
    $employeeTwo = User::factory()->create();
    $manager = User::factory()->create();
    $otherSuperAdmin = User::factory()->create(['is_super_admin' => true]);

    $team->members()->attach($employeeOne, ['role' => TeamRole::Employee->value]);
    $team->members()->attach($employeeTwo, ['role' => TeamRole::Employee->value]);
    $team->members()->attach($manager, ['role' => TeamRole::Manager->value]);
    $team->members()->attach($otherSuperAdmin, ['role' => TeamRole::Admin->value]);

    $this->actingAs($superAdmin);

    $options = Livewire::test('view-as-switcher')->instance()->roleOptions();

    expect($options->first(fn ($o) => $o['role'] === TeamRole::Employee)['users'])->toHaveCount(2)
        ->and($options->first(fn ($o) => $o['role'] === TeamRole::Manager)['users'])->toHaveCount(1)
        ->and($options->first(fn ($o) => $o['role'] === TeamRole::Admin))->toBeNull();
});

test('selecting a role with a single eligible user starts the session immediately', function () {
    ['team' => $team, 'superAdmin' => $superAdmin] = teamWithSuperAdmin();
    $manager = User::factory()->create();
    $team->members()->attach($manager, ['role' => TeamRole::Manager->value]);

    $this->actingAs($superAdmin);

    Livewire::test('view-as-switcher')->call('selectRole', TeamRole::Manager->value);

    expect(Auth::id())->toBe($manager->id);
});

test('selecting a role with multiple eligible users shows the user picker step', function () {
    ['team' => $team, 'superAdmin' => $superAdmin] = teamWithSuperAdmin();
    $employeeOne = User::factory()->create();
    $employeeTwo = User::factory()->create();
    $team->members()->attach($employeeOne, ['role' => TeamRole::Employee->value]);
    $team->members()->attach($employeeTwo, ['role' => TeamRole::Employee->value]);

    $this->actingAs($superAdmin);

    Livewire::test('view-as-switcher')
        ->call('selectRole', TeamRole::Employee->value)
        ->assertSet('selectedRole', TeamRole::Employee->value);

    expect(Auth::id())->toBe($superAdmin->id);
});

test('view as switcher cannot start a session for another super admin', function () {
    ['team' => $team, 'superAdmin' => $superAdmin] = teamWithSuperAdmin();
    $otherSuperAdmin = User::factory()->create(['is_super_admin' => true]);
    $team->members()->attach($otherSuperAdmin, ['role' => TeamRole::Admin->value]);

    $this->actingAs($superAdmin);

    Livewire::test('view-as-switcher')
        ->call('startViewAs', $otherSuperAdmin->id, TeamRole::Admin->value)
        ->assertForbidden();
});

test('view as banner only renders during an active session', function () {
    ['team' => $team, 'superAdmin' => $superAdmin] = teamWithSuperAdmin();
    $employee = User::factory()->create();
    $team->members()->attach($employee, ['role' => TeamRole::Employee->value]);

    $this->actingAs($superAdmin);

    Livewire::test('view-as-banner')->assertDontSee('Viewing as');

    app(StartViewAsSession::class)->handle($superAdmin, $employee, $team, TeamRole::Employee);

    Livewire::test('view-as-banner')
        ->assertSee('Viewing as')
        ->assertSee($employee->name);
});

test('exiting the banner ends the session and reverts auth', function () {
    ['team' => $team, 'superAdmin' => $superAdmin] = teamWithSuperAdmin();
    $employee = User::factory()->create();
    $team->members()->attach($employee, ['role' => TeamRole::Employee->value]);

    $this->actingAs($superAdmin);
    app(StartViewAsSession::class)->handle($superAdmin, $employee, $team, TeamRole::Employee);

    Livewire::test('view-as-banner')->call('exit');

    expect(Auth::id())->toBe($superAdmin->id);
});
