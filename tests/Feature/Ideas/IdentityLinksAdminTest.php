<?php

use App\Enums\TeamRole;
use App\Enums\UserIdentityAccountAuditAction;
use App\Models\User;
use App\Models\UserIdentityAccount;
use App\Models\UserIdentityAccountAudit;
use Livewire\Livewire;

test('an owner sees linked Microsoft accounts with only safe metadata', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);
    $member = User::factory()->create(['name' => 'Linked Member']);
    $team->members()->attach($member, ['role' => TeamRole::Employee->value]);

    $link = UserIdentityAccount::factory()->create([
        'user_id' => $member->id,
        'team_id' => $team->id,
        'provider_tenant_id' => 'super-secret-tenant-guid',
        'provider_subject_id' => 'super-secret-subject-guid',
        'email_at_link_time' => 'linked-member@example.com',
    ]);

    $component = Livewire::actingAs($owner)
        ->test('pages::ideas.authentication-settings', ['team' => $team])
        ->assertSeeHtml('data-test="identity-links"')
        ->assertSee('Linked Member')
        ->assertSee('linked-member@example.com')
        ->assertSeeHtml('data-test="unlink-identity-button"');

    expect($component->html())
        ->not->toContain('super-secret-tenant-guid')
        ->not->toContain('super-secret-subject-guid');

    expect(UserIdentityAccount::find($link->id))->not->toBeNull();
});

test('a manager without manage-authentication permission cannot view identity links', function () {
    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);
    UserIdentityAccount::factory()->create(['team_id' => $team->id]);

    Livewire::actingAs($manager)
        ->test('pages::ideas.authentication-settings', ['team' => $team])
        ->assertForbidden();
});

test('an owner can unlink an identity, which is audited', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);
    $member = User::factory()->create();
    $team->members()->attach($member, ['role' => TeamRole::Employee->value]);

    // The member has two links so unlinking one doesn't trip the orphan guard.
    UserIdentityAccount::factory()->create(['user_id' => $member->id, 'team_id' => $team->id]);
    $link = UserIdentityAccount::factory()->create(['user_id' => $member->id, 'team_id' => $team->id]);

    Livewire::actingAs($owner)
        ->test('pages::ideas.authentication-settings', ['team' => $team])
        ->call('confirmUnlink', $link->id)
        ->call('unlink')
        ->assertHasNoErrors();

    expect(UserIdentityAccount::find($link->id))->toBeNull();

    // The audit's user_identity_account_id is nulled by the FK's ON DELETE SET
    // NULL as soon as the link row is gone — the same thing already happens to
    // TeamIdentityProviderAudit rows on disconnect, so audits here are always
    // looked up by team_id, never by the nullable link reference.
    $audit = UserIdentityAccountAudit::where('team_id', $team->id)->sole();

    expect($audit->action)->toBe(UserIdentityAccountAuditAction::Unlinked)
        ->and($audit->performed_by_user_id)->toBe($owner->id)
        ->and($audit->user_id)->toBe($member->id);
});

test('unlinking a user\'s only identity link is blocked unless forced', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);
    $member = User::factory()->create();
    $team->members()->attach($member, ['role' => TeamRole::Employee->value]);

    $link = UserIdentityAccount::factory()->create(['user_id' => $member->id, 'team_id' => $team->id]);

    $component = Livewire::actingAs($owner)
        ->test('pages::ideas.authentication-settings', ['team' => $team])
        ->call('confirmUnlink', $link->id)
        ->call('unlink')
        ->assertSet('unlinkNeedsForce', true);

    expect(UserIdentityAccount::find($link->id))->not->toBeNull();

    $component->call('unlink', true);

    expect(UserIdentityAccount::find($link->id))->toBeNull();

    $audit = UserIdentityAccountAudit::where('team_id', $team->id)->sole();
    expect($audit->changed_fields)->toBe(['forced']);
});

test('an outsider cannot unlink an identity by passing the team directly', function () {
    ['team' => $team] = teamWithMember(TeamRole::Owner);
    $outsider = User::factory()->create();
    $link = UserIdentityAccount::factory()->create(['team_id' => $team->id]);

    Livewire::actingAs($outsider)
        ->test('pages::ideas.authentication-settings', ['team' => $team])
        ->assertForbidden();

    expect(UserIdentityAccount::find($link->id))->not->toBeNull();
});

test('a super admin can view identity links for a team they do not belong to', function () {
    ['team' => $team] = teamWithMember(TeamRole::Owner);
    $member = User::factory()->create(['name' => 'Linked Member']);
    $team->members()->attach($member, ['role' => TeamRole::Employee->value]);
    UserIdentityAccount::factory()->create(['user_id' => $member->id, 'team_id' => $team->id]);
    $superAdmin = User::factory()->create(['is_super_admin' => true]);

    Livewire::actingAs($superAdmin)
        ->test('pages::ideas.authentication-settings', ['team' => $team])
        ->assertSeeHtml('data-test="identity-links"')
        ->assertSee('Linked Member');
});
