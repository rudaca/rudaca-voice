<?php

use App\Models\User;

test('grants system owner access to an existing user', function () {
    $user = User::factory()->create();

    $this->artisan('organizations:system-owner', ['email' => $user->email])
        ->assertSuccessful();

    expect($user->fresh()->is_system_owner)->toBeTrue();
});

test('revokes system owner access with --revoke', function () {
    $user = User::factory()->systemOwner()->create();

    $this->artisan('organizations:system-owner', ['email' => $user->email, '--revoke' => true])
        ->assertSuccessful();

    expect($user->fresh()->is_system_owner)->toBeFalse();
});

test('fails when the email does not match any user', function () {
    $this->artisan('organizations:system-owner', ['email' => 'nobody@example.com'])
        ->assertFailed();
});

test('in hosted mode, granting a second system owner is rejected', function () {
    config(['organizations.hosting_mode' => 'hosted']);

    $existing = User::factory()->systemOwner()->create();
    $other = User::factory()->create();

    $this->artisan('organizations:system-owner', ['email' => $other->email])
        ->assertFailed();

    expect($other->fresh()->is_system_owner)->toBeFalse();
    expect($existing->fresh()->is_system_owner)->toBeTrue();
});

test('in hosted mode, revoking the existing owner allows granting a new one', function () {
    config(['organizations.hosting_mode' => 'hosted']);

    $existing = User::factory()->systemOwner()->create();
    $other = User::factory()->create();

    $this->artisan('organizations:system-owner', ['email' => $existing->email, '--revoke' => true])
        ->assertSuccessful();

    $this->artisan('organizations:system-owner', ['email' => $other->email])
        ->assertSuccessful();

    expect($existing->fresh()->is_system_owner)->toBeFalse();
    expect($other->fresh()->is_system_owner)->toBeTrue();
});

test('in self-hosted mode, multiple system owners are allowed', function () {
    config(['organizations.hosting_mode' => 'self-hosted']);

    $first = User::factory()->systemOwner()->create();
    $second = User::factory()->create();

    $this->artisan('organizations:system-owner', ['email' => $second->email])
        ->assertSuccessful();

    expect($first->fresh()->is_system_owner)->toBeTrue();
    expect($second->fresh()->is_system_owner)->toBeTrue();
});
