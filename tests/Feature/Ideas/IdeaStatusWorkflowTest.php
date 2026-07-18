<?php

use App\Enums\TeamRole;
use App\Models\IdeaStatusHistory;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

test('a manager can update status and a history record is created', function () {
    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);
    $idea = makeIdea($team, ['status' => 'new']);

    Livewire::actingAs($manager)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->set('status', 'planned')
        ->set('statusNote', 'Approved for Q3')
        ->call('updateManagement')
        ->assertHasNoErrors();

    expect($idea->refresh()->status)->toBe('planned');

    $history = IdeaStatusHistory::where('idea_id', $idea->id)->latest('id')->first();

    expect($history)->not->toBeNull()
        ->and($history->idea_id)->toBe($idea->id)
        ->and($history->changed_by_user_id)->toBe($manager->id)
        ->and($history->old_status)->toBe('new')
        ->and($history->new_status)->toBe('planned')
        ->and($history->note)->toBe('Approved for Q3')
        ->and($history->created_at)->not->toBeNull();
});

test('a manager can update priority, impact and effort without a status change', function () {
    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);
    $idea = makeIdea($team, [
        'status' => 'under_review',
        'priority' => 'low',
        'impact' => 'low',
        'effort' => 'small',
    ]);

    $historyBefore = IdeaStatusHistory::where('idea_id', $idea->id)->count();

    Livewire::actingAs($manager)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->set('priority', 'high')
        ->set('impact', 'high')
        ->set('effort', 'large')
        ->call('updateManagement')
        ->assertHasNoErrors();

    $idea->refresh();

    expect($idea->priority)->toBe('high')
        ->and($idea->impact)->toBe('high')
        ->and($idea->effort)->toBe('large')
        ->and($idea->status)->toBe('under_review')
        // Status unchanged, so no new history entry is written.
        ->and(IdeaStatusHistory::where('idea_id', $idea->id)->count())->toBe($historyBefore);
});

test('owner, admin and manager can update an idea status', function (TeamRole $role) {
    ['team' => $team, 'user' => $user] = teamWithMember($role);
    $idea = makeIdea($team, ['status' => 'new']);

    Livewire::actingAs($user)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->set('status', 'in_progress')
        ->call('updateManagement')
        ->assertHasNoErrors();

    expect($idea->refresh()->status)->toBe('in_progress')
        ->and(IdeaStatusHistory::where('idea_id', $idea->id)->count())->toBe(1);
})->with([
    'owner' => TeamRole::Owner,
    'admin' => TeamRole::Admin,
    'manager' => TeamRole::Manager,
]);

test('employee and viewer cannot update an idea status', function (TeamRole $role) {
    ['team' => $team, 'user' => $user] = teamWithMember($role);
    $idea = makeIdea($team, ['status' => 'new']);

    Livewire::actingAs($user)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->set('status', 'planned')
        ->call('updateManagement')
        ->assertStatus(403);

    expect($idea->refresh()->status)->toBe('new')
        ->and(IdeaStatusHistory::where('idea_id', $idea->id)->count())->toBe(0);
})->with([
    'employee' => TeamRole::Employee,
    'viewer' => TeamRole::Viewer,
]);

test('a manager cannot update an idea in another team', function () {
    ['team' => $teamA] = teamWithMember(TeamRole::Owner);
    $ideaA = makeIdea($teamA, ['status' => 'new']);

    ['user' => $managerB] = teamWithMember(TeamRole::Manager);

    // The idea detail is resolved scoped to the manager's current team (B), so a
    // Team A idea slug is not found — surfaces as a 404 in production.
    expect(fn () => Livewire::actingAs($managerB)
        ->test('pages::ideas.show', ['idea' => $ideaA->slug]))
        ->toThrow(ModelNotFoundException::class);

    expect($ideaA->refresh()->status)->toBe('new')
        ->and(IdeaStatusHistory::where('idea_id', $ideaA->id)->count())->toBe(0);
});
