<?php

use App\Actions\ViewAs\StartViewAsSession;
use App\Enums\TeamRole;
use App\Enums\ViewAsSessionEndReason;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Create a team with a Super Admin owner, and return both.
 *
 * @return array{team: Team, superAdmin: User}
 */
function teamWithSuperAdmin(): array
{
    config(['view-as.enabled' => true]);

    $team = Team::factory()->create();
    $superAdmin = User::factory()->create(['is_super_admin' => true]);

    $team->members()->attach($superAdmin, ['role' => TeamRole::Owner->value]);
    $superAdmin->switchTeam($team);

    return ['team' => $team, 'superAdmin' => $superAdmin];
}

test('super admin can start a view as session for an eligible team member', function () {
    ['team' => $team, 'superAdmin' => $superAdmin] = teamWithSuperAdmin();
    $employee = User::factory()->create();
    $team->members()->attach($employee, ['role' => TeamRole::Employee->value]);

    $this->actingAs($superAdmin);

    $session = app(StartViewAsSession::class)->handle($superAdmin, $employee, $team, TeamRole::Employee);

    expect(Auth::id())->toBe($employee->id)
        ->and($session->super_admin_id)->toBe($superAdmin->id)
        ->and($session->target_user_id)->toBe($employee->id)
        ->and($session->team_id)->toBe($team->id)
        ->and($session->role_viewed_as)->toBe(TeamRole::Employee)
        ->and($session->ended_at)->toBeNull();
});

test('super admin cannot view as another super admin', function () {
    ['team' => $team, 'superAdmin' => $superAdmin] = teamWithSuperAdmin();
    $otherSuperAdmin = User::factory()->create(['is_super_admin' => true]);
    $team->members()->attach($otherSuperAdmin, ['role' => TeamRole::Admin->value]);

    $this->actingAs($superAdmin);

    expect(fn () => app(StartViewAsSession::class)->handle($superAdmin, $otherSuperAdmin, $team, TeamRole::Admin))
        ->toThrow(HttpException::class);
});

test('non super admin cannot start a view as session', function () {
    ['team' => $team, 'user' => $user] = teamWithMember();
    $employee = User::factory()->create();
    $team->members()->attach($employee, ['role' => TeamRole::Employee->value]);

    $this->actingAs($user);

    expect(fn () => app(StartViewAsSession::class)->handle($user, $employee, $team, TeamRole::Employee))
        ->toThrow(HttpException::class);
});

test('exiting a view as session reverts auth to the super admin', function () {
    ['team' => $team, 'superAdmin' => $superAdmin] = teamWithSuperAdmin();
    $employee = User::factory()->create();
    $team->members()->attach($employee, ['role' => TeamRole::Employee->value]);

    $this->actingAs($superAdmin);
    $session = app(StartViewAsSession::class)->handle($superAdmin, $employee, $team, TeamRole::Employee);

    $session->end(ViewAsSessionEndReason::Manual);

    expect(Auth::id())->toBe($superAdmin->id)
        ->and($session->fresh()->ended_at)->not->toBeNull()
        ->and($session->fresh()->ended_reason)->toBe(ViewAsSessionEndReason::Manual);
});

test('an expired view as session automatically ends and reverts auth on the next request', function () {
    ['team' => $team, 'superAdmin' => $superAdmin] = teamWithSuperAdmin();
    $employee = User::factory()->create();
    $team->members()->attach($employee, ['role' => TeamRole::Employee->value]);

    $this->actingAs($superAdmin);
    $session = app(StartViewAsSession::class)->handle($superAdmin, $employee, $team, TeamRole::Employee);
    $session->update(['last_activity_at' => now()->subMinutes(config('view-as.timeout_minutes') + 1)]);

    $this->get(route('dashboard', ['current_team' => $team->slug]))
        ->assertRedirect(route('home'));

    expect(Auth::id())->toBe($superAdmin->id)
        ->and($session->fresh()->ended_reason)->toBe(ViewAsSessionEndReason::Timeout);
});

test('view as is disabled by default and blocks starting a session', function () {
    config(['view-as.enabled' => false]);

    $team = Team::factory()->create();
    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $employee = User::factory()->create();
    $team->members()->attach($superAdmin, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($employee, ['role' => TeamRole::Employee->value]);
    $superAdmin->switchTeam($team);

    $this->actingAs($superAdmin);

    expect(fn () => app(StartViewAsSession::class)->handle($superAdmin, $employee, $team, TeamRole::Employee))
        ->toThrow(HttpException::class);
});

test('requests during an active session are recorded', function () {
    ['team' => $team, 'superAdmin' => $superAdmin] = teamWithSuperAdmin();
    $employee = User::factory()->create();
    $team->members()->attach($employee, ['role' => TeamRole::Employee->value]);

    $this->actingAs($superAdmin);
    $session = app(StartViewAsSession::class)->handle($superAdmin, $employee, $team, TeamRole::Employee);
    $session->update(['last_activity_at' => now()->subMinute()]);

    $this->get(route('dashboard', ['current_team' => $team->slug]))->assertOk();

    expect($session->actions()->count())->toBeGreaterThan(0)
        ->and($session->fresh()->last_activity_at->greaterThan(now()->subMinute()))->toBeTrue();
});
