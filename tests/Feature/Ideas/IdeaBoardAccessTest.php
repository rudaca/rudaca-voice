<?php

use App\Enums\AccessLevel;
use App\Enums\TeamRole;
use App\Models\IdeaBoardRoleAccess;
use App\Models\IdeaBoardUserAccess;
use App\Models\User;

test('owner, admin and manager can manage private notes on every board, with no explicit grant', function (TeamRole $role) {
    ['team' => $team, 'user' => $user] = teamWithMember($role);
    $stack = boardStack($team);

    expect($user->canManagePrivateNotesOn($stack['board']))->toBeTrue();
})->with([
    'owner' => TeamRole::Owner,
    'admin' => TeamRole::Admin,
    'manager' => TeamRole::Manager,
]);

test('employee and viewer cannot manage private notes without a grant', function (TeamRole $role) {
    ['team' => $team, 'user' => $user] = teamWithMember($role);
    $stack = boardStack($team);

    expect($user->canManagePrivateNotesOn($stack['board']))->toBeFalse();
})->with([
    'employee' => TeamRole::Employee,
    'viewer' => TeamRole::Viewer,
]);

test('a Manage user-access grant authorizes only the granted board', function () {
    ['team' => $team, 'user' => $employee] = teamWithMember(TeamRole::Employee);
    $granted = boardStack($team)['board'];
    $ungranted = boardStack($team)['board'];

    IdeaBoardUserAccess::factory()->create([
        'board_id' => $granted->id,
        'user_id' => $employee->id,
        'access_level' => AccessLevel::Manage,
    ]);

    expect($employee->canManagePrivateNotesOn($granted))->toBeTrue()
        ->and($employee->canManagePrivateNotesOn($ungranted))->toBeFalse();
});

test('a role-access grant authorizes only the granted board, for members with that role', function () {
    ['team' => $team, 'user' => $employee] = teamWithMember(TeamRole::Employee);
    $granted = boardStack($team)['board'];
    $ungranted = boardStack($team)['board'];

    IdeaBoardRoleAccess::factory()->create([
        'board_id' => $granted->id,
        'role' => TeamRole::Employee,
    ]);

    expect($employee->canManagePrivateNotesOn($granted))->toBeTrue()
        ->and($employee->canManagePrivateNotesOn($ungranted))->toBeFalse();

    $viewer = User::factory()->create();
    $team->members()->attach($viewer, ['role' => TeamRole::Viewer->value]);

    expect($viewer->canManagePrivateNotesOn($granted))->toBeFalse();
});

test('View and Contribute user-access levels do not authorize private-note management', function (AccessLevel $accessLevel) {
    ['team' => $team, 'user' => $employee] = teamWithMember(TeamRole::Employee);
    $board = boardStack($team)['board'];

    IdeaBoardUserAccess::factory()->create([
        'board_id' => $board->id,
        'user_id' => $employee->id,
        'access_level' => $accessLevel,
    ]);

    expect($employee->canManagePrivateNotesOn($board))->toBeFalse();
})->with([
    'view' => AccessLevel::View,
    'contribute' => AccessLevel::Contribute,
]);

test('a user with no membership on the board team has no private-note access', function () {
    ['team' => $team] = teamWithMember(TeamRole::Employee);
    $board = boardStack($team)['board'];
    $outsider = User::factory()->create();

    expect($outsider->canManagePrivateNotesOn($board))->toBeFalse();
});
