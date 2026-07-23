<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;

test('creates an owner with a new team when no team_id is given', function () {
    $this->artisan('user:setup', [
        '--email' => 'owner@example.com',
        '--name' => 'Aaron Asuncion',
        '--password' => 'password123',
        '--owner' => true,
    ])->assertSuccessful();

    $user = User::where('email', 'owner@example.com')->firstOrFail();

    expect($user->name)->toBe('Aaron Asuncion');
    expect($user->currentTeam)->not->toBeNull();
    expect($user->teamRole($user->currentTeam))->toBe(TeamRole::Owner);
});

test('creates an owner on an existing team without an owner', function () {
    $team = Team::factory()->create();

    $this->artisan('user:setup', [
        '--email' => 'owner@example.com',
        '--name' => 'Aaron Asuncion',
        '--password' => 'password123',
        '--owner' => true,
        '--team_id' => $team->id,
    ])->assertSuccessful();

    $user = User::where('email', 'owner@example.com')->firstOrFail();

    expect($user->teamRole($team))->toBe(TeamRole::Owner);
});

test('fails to create an owner on a team that already has one', function () {
    $team = Team::factory()->create();
    $existingOwner = User::factory()->create();
    $team->members()->attach($existingOwner, ['role' => TeamRole::Owner->value]);

    $this->artisan('user:setup', [
        '--email' => 'owner@example.com',
        '--name' => 'Aaron Asuncion',
        '--password' => 'password123',
        '--owner' => true,
        '--team_id' => $team->id,
    ])->assertFailed();

    $this->assertDatabaseMissing('users', ['email' => 'owner@example.com']);
});

test('creates a non-owner user on an existing team', function () {
    $team = Team::factory()->create();

    $this->artisan('user:setup', [
        '--email' => 'manager@example.com',
        '--name' => 'Casey Manager',
        '--password' => 'password123',
        '--role' => TeamRole::Manager->value,
        '--team_id' => $team->id,
    ])->assertSuccessful();

    $user = User::where('email', 'manager@example.com')->firstOrFail();

    expect($user->teamRole($team))->toBe(TeamRole::Manager);
    expect($user->current_team_id)->toBe($team->id);
});

test('fails when a non-owner role is given without a team_id', function () {
    $this->artisan('user:setup', [
        '--email' => 'manager@example.com',
        '--name' => 'Casey Manager',
        '--password' => 'password123',
        '--role' => TeamRole::Manager->value,
    ])->assertFailed();

    $this->assertDatabaseMissing('users', ['email' => 'manager@example.com']);
});

test('fails when neither --owner nor --role is given', function () {
    $this->artisan('user:setup', [
        '--email' => 'nobody@example.com',
        '--name' => 'No Body',
        '--password' => 'password123',
    ])->assertFailed();

    $this->assertDatabaseMissing('users', ['email' => 'nobody@example.com']);
});

test('fails when both --owner and --role are given', function () {
    $team = Team::factory()->create();

    $this->artisan('user:setup', [
        '--email' => 'ambiguous@example.com',
        '--name' => 'Ambiguous',
        '--password' => 'password123',
        '--owner' => true,
        '--role' => TeamRole::Manager->value,
        '--team_id' => $team->id,
    ])->assertFailed();

    $this->assertDatabaseMissing('users', ['email' => 'ambiguous@example.com']);
});

test('fails when the email is already taken', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->artisan('user:setup', [
        '--email' => 'taken@example.com',
        '--name' => 'Someone Else',
        '--password' => 'password123',
        '--owner' => true,
    ])->assertFailed();
});
