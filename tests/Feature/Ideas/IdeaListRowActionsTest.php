<?php

use App\Enums\TeamRole;
use App\Models\User;
use Livewire\Livewire;

test('the edit and delete row actions are visible to an owner', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);
    makeIdea($team);

    Livewire::actingAs($owner)
        ->test('pages::ideas.index')
        ->assertSeeHtml('data-test="idea-row-edit"')
        ->assertSeeHtml('data-test="idea-row-delete"');
});

test('the edit and delete row actions are visible to a manager, even on someone else\'s idea', function () {
    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);
    $otherAuthor = User::factory()->create();
    $team->members()->attach($otherAuthor, ['role' => TeamRole::Employee->value]);
    makeIdea($team, ['submitted_by_user_id' => $otherAuthor->id]);

    Livewire::actingAs($manager)
        ->test('pages::ideas.index')
        ->assertSeeHtml('data-test="idea-row-edit"')
        ->assertSeeHtml('data-test="idea-row-delete"');
});

test('the edit and delete row actions are visible to a regular employee on their own idea', function () {
    ['team' => $team, 'user' => $employee] = teamWithMember(TeamRole::Employee);
    makeIdea($team, ['submitted_by_user_id' => $employee->id]);

    Livewire::actingAs($employee)
        ->test('pages::ideas.index')
        ->assertSeeHtml('data-test="idea-row-edit"')
        ->assertSeeHtml('data-test="idea-row-delete"');
});

test('the row actions are hidden from a regular employee viewing someone else\'s idea', function () {
    ['team' => $team, 'user' => $employee] = teamWithMember(TeamRole::Employee);
    $otherAuthor = User::factory()->create();
    $team->members()->attach($otherAuthor, ['role' => TeamRole::Employee->value]);
    makeIdea($team, ['submitted_by_user_id' => $otherAuthor->id]);

    Livewire::actingAs($employee)
        ->test('pages::ideas.index')
        ->assertDontSeeHtml('data-test="idea-row-edit"')
        ->assertDontSeeHtml('data-test="idea-row-delete"');
});

test('an owner can delete an idea from the list', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);
    $idea = makeIdea($team);

    Livewire::actingAs($owner)
        ->test('pages::ideas.index')
        ->call('deleteIdea', $idea->id)
        ->assertHasNoErrors()
        ->assertDispatched('modal-close', name: "confirm-delete-idea-{$idea->id}");

    $this->assertSoftDeleted('ideas', ['id' => $idea->id]);
});

test('a manager can delete any idea from the list', function () {
    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);
    $otherAuthor = User::factory()->create();
    $team->members()->attach($otherAuthor, ['role' => TeamRole::Employee->value]);
    $idea = makeIdea($team, ['submitted_by_user_id' => $otherAuthor->id]);

    Livewire::actingAs($manager)
        ->test('pages::ideas.index')
        ->call('deleteIdea', $idea->id)
        ->assertHasNoErrors();

    $this->assertSoftDeleted('ideas', ['id' => $idea->id]);
});

test('a regular employee can delete their own idea from the list', function () {
    ['team' => $team, 'user' => $employee] = teamWithMember(TeamRole::Employee);
    $idea = makeIdea($team, ['submitted_by_user_id' => $employee->id]);

    Livewire::actingAs($employee)
        ->test('pages::ideas.index')
        ->call('deleteIdea', $idea->id)
        ->assertHasNoErrors();

    $this->assertSoftDeleted('ideas', ['id' => $idea->id]);
});

test('a regular employee cannot delete someone else\'s idea from the list', function () {
    ['team' => $team, 'user' => $employee] = teamWithMember(TeamRole::Employee);
    $otherAuthor = User::factory()->create();
    $team->members()->attach($otherAuthor, ['role' => TeamRole::Employee->value]);
    $idea = makeIdea($team, ['submitted_by_user_id' => $otherAuthor->id]);

    Livewire::actingAs($employee)
        ->test('pages::ideas.index')
        ->call('deleteIdea', $idea->id)
        ->assertForbidden();

    $this->assertDatabaseHas('ideas', ['id' => $idea->id, 'deleted_at' => null]);
});

test('visiting an idea with ?edit=1 opens the edit modal for a manager', function () {
    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);
    $idea = makeIdea($team);

    Livewire::actingAs($manager)
        ->withQueryParams(['edit' => '1'])
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->assertDispatched('modal-show', name: 'edit-idea');
});

test('visiting an idea with ?edit=1 opens the edit modal for the idea\'s own author', function () {
    ['team' => $team, 'user' => $employee] = teamWithMember(TeamRole::Employee);
    $idea = makeIdea($team, ['submitted_by_user_id' => $employee->id]);

    Livewire::actingAs($employee)
        ->withQueryParams(['edit' => '1'])
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->assertDispatched('modal-show', name: 'edit-idea');
});

test('visiting an idea with ?edit=1 does not open the edit modal or fail for an employee viewing someone else\'s idea', function () {
    ['team' => $team, 'user' => $employee] = teamWithMember(TeamRole::Employee);
    $otherAuthor = User::factory()->create();
    $team->members()->attach($otherAuthor, ['role' => TeamRole::Employee->value]);
    $idea = makeIdea($team, ['submitted_by_user_id' => $otherAuthor->id]);

    Livewire::actingAs($employee)
        ->withQueryParams(['edit' => '1'])
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->assertOk()
        ->assertNotDispatched('modal-show', name: 'edit-idea');
});
