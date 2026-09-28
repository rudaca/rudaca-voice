<?php

use App\Enums\TeamRole;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

test('moving an idea to Completed stages the change and requires confirmation before saving', function () {
    Notification::fake();

    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);
    $author = User::factory()->create();
    $team->members()->attach($author, ['role' => TeamRole::Employee->value]);

    $idea = makeIdea($team, ['status' => 'planned', 'submitted_by_user_id' => $author->id]);

    Livewire::actingAs($manager)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->set('status', 'released')
        ->call('attemptUpdateManagement')
        ->assertSet('pendingManagementUpdate.status', 'released')
        ->assertDispatched('modal-show', name: 'confirm-manage-idea');

    expect($idea->refresh()->status)->toBe('planned');

    Notification::assertNothingSent();
});

test('canceling the confirmation leaves the idea unchanged and sends no email', function () {
    Notification::fake();

    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);
    $author = User::factory()->create();
    $team->members()->attach($author, ['role' => TeamRole::Employee->value]);

    $idea = makeIdea($team, ['status' => 'planned', 'submitted_by_user_id' => $author->id]);

    Livewire::actingAs($manager)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->set('status', 'released')
        ->call('attemptUpdateManagement')
        ->call('cancelManagementConfirmation')
        ->assertSet('pendingManagementUpdate', null);

    expect($idea->refresh()->status)->toBe('planned');

    Notification::assertNothingSent();
});

test('a change that does not notify the author is saved immediately without a confirmation modal', function () {
    Notification::fake();

    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);
    $author = User::factory()->create();
    $team->members()->attach($author, ['role' => TeamRole::Employee->value]);

    $idea = makeIdea($team, ['status' => 'new', 'submitted_by_user_id' => $author->id]);

    Livewire::actingAs($manager)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->set('status', 'planned')
        ->call('attemptUpdateManagement')
        ->assertNotDispatched('modal-show', name: 'confirm-manage-idea');

    expect($idea->refresh()->status)->toBe('planned');

    Notification::assertNothingSent();
});

test('confirming without a staged update is rejected', function () {
    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);
    $idea = makeIdea($team, ['status' => 'planned']);

    Livewire::actingAs($manager)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->call('confirmUpdateManagement')
        ->assertStatus(404);
});
