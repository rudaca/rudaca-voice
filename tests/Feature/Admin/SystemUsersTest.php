<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;

test('non super admins are forbidden from the system users page', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Owner);

    $this->actingAs($admin)
        ->get(route('admin.users'))
        ->assertForbidden();
});

test('super admins can view the system users page', function () {
    $superAdmin = User::factory()->create(['is_super_admin' => true]);

    $this->actingAs($superAdmin)
        ->get(route('admin.users'))
        ->assertOk()
        ->assertSee('System Users');
});

test('the user list shows the last login time, or "Never" if the user has not logged in', function () {
    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $loggedIn = User::factory()->create(['name' => 'Has Logged In', 'last_login_at' => now()->subDay()]);
    $neverLoggedIn = User::factory()->create(['name' => 'Never Logged In', 'last_login_at' => null]);

    Livewire::actingAs($superAdmin)
        ->test('pages::admin.users')
        ->assertSee($loggedIn->last_login_at->format('M d, Y'))
        ->assertSeeInOrder(['Never Logged In', 'Never']);
});

test('the sidebar only shows the System Users link to super admins', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Owner);
    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $team->members()->attach($superAdmin, ['role' => TeamRole::Owner->value]);
    $superAdmin->switchTeam($team);

    $this->actingAs($admin)
        ->get(route('dashboard', ['current_team' => $team->slug]))
        ->assertDontSee('System Users');

    $this->actingAs($superAdmin)
        ->get(route('dashboard', ['current_team' => $team->slug]))
        ->assertSee('System Users');
});

test('a super admin can create a new user who can then log in', function () {
    $superAdmin = User::factory()->create(['is_super_admin' => true]);

    Livewire::actingAs($superAdmin)
        ->test('pages::admin.users')
        ->set('name', 'New Person')
        ->set('email', 'new-person@example.com')
        ->set('password', 'password123!A')
        ->set('password_confirmation', 'password123!A')
        ->call('saveUser')
        ->assertDispatched('modal-close', name: 'user');

    $user = User::where('email', 'new-person@example.com')->firstOrFail();

    expect($user->name)->toBe('New Person')
        ->and($user->is_active)->toBeTrue()
        ->and($user->is_super_admin)->toBeFalse();

    auth()->logout();

    $this->post(route('login'), [
        'email' => 'new-person@example.com',
        'password' => 'password123!A',
    ])->assertRedirect();

    $this->assertAuthenticatedAs($user);
});

test('a super admin can edit a user\'s name, email, and password', function () {
    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $user = User::factory()->create(['name' => 'Old Name', 'email' => 'old@example.com']);

    Livewire::actingAs($superAdmin)
        ->test('pages::admin.users')
        ->call('editUser', $user->id)
        ->set('name', 'New Name')
        ->set('email', 'new@example.com')
        ->set('password', 'brand-new-pass1!')
        ->set('password_confirmation', 'brand-new-pass1!')
        ->call('saveUser')
        ->assertDispatched('modal-close', name: 'user');

    $user->refresh();

    expect($user->name)->toBe('New Name')
        ->and($user->email)->toBe('new@example.com');

    auth()->logout();

    $this->post(route('login'), [
        'email' => 'new@example.com',
        'password' => 'brand-new-pass1!',
    ])->assertRedirect();
    $this->assertAuthenticatedAs($user);
});

test('editing a user without a password leaves the existing password untouched', function () {
    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $user = User::factory()->create();
    $originalHash = $user->password;

    Livewire::actingAs($superAdmin)
        ->test('pages::admin.users')
        ->call('editUser', $user->id)
        ->set('name', 'Still Works')
        ->call('saveUser')
        ->assertHasNoErrors();

    expect($user->fresh()->password)->toBe($originalHash);
});

test('a super admin can assign and revoke super admin access', function () {
    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $user = User::factory()->create();

    Livewire::actingAs($superAdmin)
        ->test('pages::admin.users')
        ->call('assignRole', $user->id, true);

    expect($user->fresh()->is_super_admin)->toBeTrue();

    Livewire::actingAs($superAdmin)
        ->test('pages::admin.users')
        ->call('assignRole', $user->id, false);

    expect($user->fresh()->is_super_admin)->toBeFalse();
});

test('a super admin cannot change their own role', function () {
    $superAdmin = User::factory()->create(['is_super_admin' => true]);

    Livewire::actingAs($superAdmin)
        ->test('pages::admin.users')
        ->call('assignRole', $superAdmin->id, false);

    expect($superAdmin->fresh()->is_super_admin)->toBeTrue();
});

test('the Super Admin checkbox in the new user modal grants access on creation', function () {
    $superAdmin = User::factory()->create(['is_super_admin' => true]);

    Livewire::actingAs($superAdmin)
        ->test('pages::admin.users')
        ->set('name', 'Brand New Admin')
        ->set('email', 'brand-new-admin@example.com')
        ->set('password', 'password123!A')
        ->set('password_confirmation', 'password123!A')
        ->set('isSuperAdmin', true)
        ->call('saveUser');

    expect(User::where('email', 'brand-new-admin@example.com')->firstOrFail()->is_super_admin)->toBeTrue();
});

test('the Super Admin checkbox in the edit user modal grants and revokes access for another user', function () {
    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $user = User::factory()->create();

    Livewire::actingAs($superAdmin)
        ->test('pages::admin.users')
        ->call('editUser', $user->id)
        ->set('isSuperAdmin', true)
        ->call('saveUser');

    expect($user->fresh()->is_super_admin)->toBeTrue();

    Livewire::actingAs($superAdmin)
        ->test('pages::admin.users')
        ->call('editUser', $user->id)
        ->set('isSuperAdmin', false)
        ->call('saveUser');

    expect($user->fresh()->is_super_admin)->toBeFalse();
});

test('the Super Admin and Active checkboxes are hidden when editing your own account and cannot revoke your own access', function () {
    $superAdmin = User::factory()->create(['is_super_admin' => true]);

    Livewire::actingAs($superAdmin)
        ->test('pages::admin.users')
        ->call('editUser', $superAdmin->id)
        ->assertDontSeeHtml('data-test="user-super-admin-checkbox"')
        ->assertDontSeeHtml('data-test="user-active-checkbox"')
        ->set('isSuperAdmin', false)
        ->set('isActive', false)
        ->call('saveUser');

    expect($superAdmin->fresh())
        ->is_super_admin->toBeTrue()
        ->is_active->toBeTrue();
});

test('the Active checkbox in the new user modal creates an inactive user when unchecked', function () {
    $superAdmin = User::factory()->create(['is_super_admin' => true]);

    Livewire::actingAs($superAdmin)
        ->test('pages::admin.users')
        ->set('name', 'Inactive From Birth')
        ->set('email', 'inactive-from-birth@example.com')
        ->set('password', 'password123!A')
        ->set('password_confirmation', 'password123!A')
        ->set('isActive', false)
        ->call('saveUser');

    expect(User::where('email', 'inactive-from-birth@example.com')->firstOrFail()->is_active)->toBeFalse();
});

test('the Active checkbox in the edit user modal deactivates and reactivates another user', function () {
    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $user = User::factory()->create();

    Livewire::actingAs($superAdmin)
        ->test('pages::admin.users')
        ->call('editUser', $user->id)
        ->set('isActive', false)
        ->call('saveUser');

    expect($user->fresh()->is_active)->toBeFalse();

    Livewire::actingAs($superAdmin)
        ->test('pages::admin.users')
        ->call('editUser', $user->id)
        ->set('isActive', true)
        ->call('saveUser');

    expect($user->fresh()->is_active)->toBeTrue();
});

test('a super admin can deactivate and reactivate another user, locking them out of login', function () {
    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $user = User::factory()->create();

    Livewire::actingAs($superAdmin)
        ->test('pages::admin.users')
        ->call('toggleActive', $user->id);

    expect($user->fresh()->is_active)->toBeFalse();

    auth()->logout();

    $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertSessionHasErrors('email');
    $this->assertGuest();

    Livewire::actingAs($superAdmin)
        ->test('pages::admin.users')
        ->call('toggleActive', $user->id);

    expect($user->fresh()->is_active)->toBeTrue();

    auth()->logout();

    $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect();
    $this->assertAuthenticatedAs($user);
});

test('a super admin cannot deactivate their own account', function () {
    $superAdmin = User::factory()->create(['is_super_admin' => true]);

    Livewire::actingAs($superAdmin)
        ->test('pages::admin.users')
        ->call('toggleActive', $superAdmin->id);

    expect($superAdmin->fresh()->is_active)->toBeTrue();
});

test('the user list can be searched by name or email', function () {
    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $match = User::factory()->create(['name' => 'Findable Person', 'email' => 'findme@example.com']);
    $other = User::factory()->create(['name' => 'Nobody Else', 'email' => 'nobody@example.com']);

    Livewire::actingAs($superAdmin)
        ->test('pages::admin.users')
        ->set('search', 'findable')
        ->assertSee($match->name)
        ->assertDontSee($other->name);
});

test('the user list can be filtered by role', function () {
    $superAdmin = User::factory()->create(['is_super_admin' => true, 'name' => 'Super One']);
    $regular = User::factory()->create(['name' => 'Regular One']);

    Livewire::actingAs($superAdmin)
        ->test('pages::admin.users')
        ->set('role', 'super_admin')
        ->assertSee('Super One')
        ->assertDontSee('Regular One')
        ->set('role', 'user')
        ->assertDontSee('Super One')
        ->assertSee('Regular One')
        ->set('role', '')
        ->assertSee('Super One')
        ->assertSee('Regular One');
});

test('the user list can be filtered by status', function () {
    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $active = User::factory()->create(['name' => 'Active Person']);
    $inactive = User::factory()->inactive()->create(['name' => 'Inactive Person']);

    Livewire::actingAs($superAdmin)
        ->test('pages::admin.users')
        ->set('status', ['active'])
        ->assertSee('Active Person')
        ->assertDontSee('Inactive Person')
        ->set('status', ['inactive'])
        ->assertDontSee('Active Person')
        ->assertSee('Inactive Person')
        ->set('status', [])
        ->assertSee('Active Person')
        ->assertSee('Inactive Person');
});

test('the user list can be filtered by organization', function () {
    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $orgA = Team::factory()->create(['name' => 'Org A', 'is_personal' => false]);
    $orgB = Team::factory()->create(['name' => 'Org B', 'is_personal' => false]);
    $memberA = User::factory()->create(['name' => 'Member Of A']);
    $memberB = User::factory()->create(['name' => 'Member Of B']);
    $orgA->members()->attach($memberA, ['role' => TeamRole::Employee->value]);
    $orgB->members()->attach($memberB, ['role' => TeamRole::Employee->value]);

    Livewire::actingAs($superAdmin)
        ->test('pages::admin.users')
        ->set('organization', [$orgA->id])
        ->assertSee('Member Of A')
        ->assertDontSee('Member Of B')
        ->set('organization', [])
        ->assertSee('Member Of A')
        ->assertSee('Member Of B');
});

test('the Clear button only appears when a filter is active and resets every filter', function () {
    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $team = Team::factory()->create(['is_personal' => false]);

    $component = Livewire::actingAs($superAdmin)
        ->test('pages::admin.users')
        ->assertDontSeeHtml('data-test="clear-filters"')
        ->set('search', 'something')
        ->assertSeeHtml('data-test="clear-filters"')
        ->set('search', '')
        ->set('role', 'user')
        ->assertSeeHtml('data-test="clear-filters"')
        ->set('role', '')
        ->set('status', ['active'])
        ->assertSeeHtml('data-test="clear-filters"')
        ->set('status', [])
        ->set('organization', [$team->id])
        ->assertSeeHtml('data-test="clear-filters"');

    $component->call('clearFilters')
        ->assertDontSeeHtml('data-test="clear-filters"');

    expect($component->get('search'))->toBe('')
        ->and($component->get('role'))->toBe('')
        ->and($component->get('status'))->toBe([])
        ->and($component->get('organization'))->toBe([]);
});
