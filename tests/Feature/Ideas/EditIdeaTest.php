<?php

use App\Enums\TeamRole;
use App\Models\IdeaBoard;
use App\Models\IdeaBoardGroup;
use App\Models\IdeaCategory;
use App\Models\IdeaEditHistory;
use App\Models\IdeaStatusHistory;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

test('a manager can open the edit-idea form for an idea submitted by someone else', function () {
    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);
    $author = User::factory()->create();
    $team->members()->attach($author, ['role' => TeamRole::Employee->value]);
    $idea = makeIdea($team, ['submitted_by_user_id' => $author->id]);

    Livewire::actingAs($manager)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->call('openEditIdea')
        ->assertDispatched('modal-show', name: 'edit-idea')
        ->assertSet('editTitle', $idea->title)
        ->assertSet('editAuthorUserId', $author->id);
});

test('an owner can edit any idea regardless of who submitted it', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);
    $author = User::factory()->create();
    $team->members()->attach($author, ['role' => TeamRole::Employee->value]);
    $idea = makeIdea($team, ['submitted_by_user_id' => $author->id]);

    Livewire::actingAs($owner)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->call('openEditIdea')
        ->assertDispatched('modal-show', name: 'edit-idea');
});

test('the edit idea button is visible to managers and above, and to the idea\'s own author', function () {
    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);
    $idea = makeIdea($team);

    Livewire::actingAs($manager)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->assertSeeHtml('data-test="edit-idea-button"');

    ['team' => $employeeTeam, 'user' => $author] = teamWithMember(TeamRole::Employee);
    $ownIdea = makeIdea($employeeTeam, ['submitted_by_user_id' => $author->id]);

    Livewire::actingAs($author)
        ->test('pages::ideas.show', ['idea' => $ownIdea->slug])
        ->assertSeeHtml('data-test="edit-idea-button"');
});

test('the edit idea button is hidden from a regular employee viewing someone else\'s idea', function () {
    ['team' => $team, 'user' => $employee] = teamWithMember(TeamRole::Employee);
    $otherAuthor = User::factory()->create();
    $team->members()->attach($otherAuthor, ['role' => TeamRole::Employee->value]);
    $idea = makeIdea($team, ['submitted_by_user_id' => $otherAuthor->id]);

    Livewire::actingAs($employee)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->assertDontSeeHtml('data-test="edit-idea-button"');
});

test('a manager can update title, description, board, category, and reassign the author', function () {
    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);
    $originalAuthor = User::factory()->create();
    $team->members()->attach($originalAuthor, ['role' => TeamRole::Employee->value]);
    $newAuthor = User::factory()->create();
    $team->members()->attach($newAuthor, ['role' => TeamRole::Employee->value]);

    $idea = makeIdea($team, ['submitted_by_user_id' => $originalAuthor->id]);

    $group = IdeaBoardGroup::factory()->create(['team_id' => $team->id, 'is_active' => true]);
    $board = IdeaBoard::factory()->create(['team_id' => $team->id, 'board_group_id' => $group->id, 'is_active' => true]);
    $category = IdeaCategory::factory()->create(['team_id' => $team->id, 'board_id' => $board->id, 'is_active' => true]);

    Livewire::actingAs($manager)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->call('openEditIdea')
        ->set('editTitle', 'A retitled idea')
        ->set('editDescription', '<p>Updated description</p>')
        ->set('editBoardGroupId', (string) $group->id)
        ->set('editBoardId', (string) $board->id)
        ->set('editCategoryId', (string) $category->id)
        ->call('selectEditAuthor', $newAuthor->id)
        ->call('updateIdea')
        ->assertHasNoErrors()
        ->assertDispatched('modal-close', name: 'edit-idea');

    $idea->refresh();

    expect($idea->title)->toBe('A retitled idea')
        ->and($idea->description)->toContain('Updated description')
        ->and($idea->board_group_id)->toBe($group->id)
        ->and($idea->board_id)->toBe($board->id)
        ->and($idea->category_id)->toBe($category->id)
        ->and($idea->submitted_by_user_id)->toBe($newAuthor->id);
});

test('reassigning the author does not touch entered_by_user_id or notify anyone, but is recorded in the edit history', function () {
    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);
    $enteredBy = User::factory()->create();
    $team->members()->attach($enteredBy, ['role' => TeamRole::Manager->value]);
    $originalAuthor = User::factory()->create(['name' => 'Jane Original']);
    $team->members()->attach($originalAuthor, ['role' => TeamRole::Employee->value]);
    $newAuthor = User::factory()->create(['name' => 'John New']);
    $team->members()->attach($newAuthor, ['role' => TeamRole::Employee->value]);

    $idea = makeIdea($team, [
        'submitted_by_user_id' => $originalAuthor->id,
        'entered_by_user_id' => $enteredBy->id,
        // Already-purified HTML, like a real record, so re-running it through
        // Purify in updateIdea() is a no-op and doesn't register as a change.
        'description' => '<p>Original description</p>',
        'description_format' => 'html',
    ]);

    $historyCountBefore = IdeaStatusHistory::where('idea_id', $idea->id)->count();

    Notification::fake();

    Livewire::actingAs($manager)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->call('openEditIdea')
        ->call('selectEditAuthor', $newAuthor->id)
        ->call('updateIdea')
        ->assertHasNoErrors();

    Notification::assertNothingSent();

    $idea->refresh();

    expect($idea->submitted_by_user_id)->toBe($newAuthor->id)
        ->and($idea->entered_by_user_id)->toBe($enteredBy->id)
        ->and(IdeaStatusHistory::where('idea_id', $idea->id)->count())->toBe($historyCountBefore);

    $editHistory = IdeaEditHistory::where('idea_id', $idea->id)->first();

    expect($editHistory)->not->toBeNull()
        ->and($editHistory->actor_user_id)->toBe($manager->id)
        ->and($editHistory->summary)->toBe('Author changed from Jane Original to John New.');
});

test('editing title, board, and category creates an edit-history entry describing each change', function () {
    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);
    $idea = makeIdea($team, [
        'submitted_by_user_id' => $manager->id,
        'description' => '<p>Original description</p>',
        'description_format' => 'html',
    ]);

    $group = IdeaBoardGroup::factory()->create(['team_id' => $team->id, 'is_active' => true]);
    $board = IdeaBoard::factory()->create(['team_id' => $team->id, 'board_group_id' => $group->id, 'is_active' => true, 'name' => 'Support']);
    $category = IdeaCategory::factory()->create(['team_id' => $team->id, 'board_id' => $board->id, 'is_active' => true, 'name' => 'Bug']);

    Livewire::actingAs($manager)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->call('openEditIdea')
        ->set('editTitle', 'A retitled idea')
        ->set('editBoardGroupId', (string) $group->id)
        ->set('editBoardId', (string) $board->id)
        ->set('editCategoryId', (string) $category->id)
        ->call('updateIdea')
        ->assertHasNoErrors();

    $editHistory = IdeaEditHistory::where('idea_id', $idea->id)->first();

    expect($editHistory)->not->toBeNull()
        ->and($editHistory->summary)->toContain('Title updated.')
        ->and($editHistory->summary)->toContain('Board changed to Support.')
        ->and($editHistory->summary)->toContain('Category changed to Bug.');
});

test('saving the edit form without any changes does not create an edit-history entry', function () {
    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);
    $idea = makeIdea($team, [
        'submitted_by_user_id' => $manager->id,
        'description' => '<p>Original description</p>',
        'description_format' => 'html',
    ]);

    Livewire::actingAs($manager)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->call('openEditIdea')
        ->call('updateIdea')
        ->assertHasNoErrors();

    expect(IdeaEditHistory::where('idea_id', $idea->id)->count())->toBe(0);
});

test('an edit-history entry appears in the idea\'s activity timeline', function () {
    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);
    $idea = makeIdea($team, [
        'submitted_by_user_id' => $manager->id,
        'description' => '<p>Original description</p>',
        'description_format' => 'html',
    ]);

    Livewire::actingAs($manager)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->call('openEditIdea')
        ->set('editTitle', 'A retitled idea')
        ->call('updateIdea')
        ->assertHasNoErrors()
        ->assertSee('Idea edited')
        ->assertSee('Title updated.');
});

test('the author can only be reassigned to an active member of the team', function () {
    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);
    $idea = makeIdea($team, ['submitted_by_user_id' => $manager->id]);

    $inactive = User::factory()->inactive()->create();
    $team->members()->attach($inactive, ['role' => TeamRole::Employee->value]);

    $stranger = User::factory()->create();

    Livewire::actingAs($manager)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->call('openEditIdea')
        ->set('editAuthorUserId', $inactive->id)
        ->call('updateIdea')
        ->assertHasErrors(['editAuthorUserId']);

    Livewire::actingAs($manager)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->call('openEditIdea')
        ->set('editAuthorUserId', $stranger->id)
        ->call('updateIdea')
        ->assertHasErrors(['editAuthorUserId']);

    expect($idea->fresh()->submitted_by_user_id)->toBe($manager->id);
});

test('a regular employee can edit an idea they submitted themselves', function () {
    ['team' => $team, 'user' => $employee] = teamWithMember(TeamRole::Employee);
    $idea = makeIdea($team, ['submitted_by_user_id' => $employee->id]);

    Livewire::actingAs($employee)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->call('openEditIdea')
        ->set('editTitle', 'Updated by the author')
        ->call('updateIdea')
        ->assertHasNoErrors();

    expect($idea->fresh()->title)->toBe('Updated by the author');
});

test('a regular employee cannot edit someone else\'s idea', function () {
    ['team' => $team, 'user' => $employee] = teamWithMember(TeamRole::Employee);
    $otherAuthor = User::factory()->create();
    $team->members()->attach($otherAuthor, ['role' => TeamRole::Employee->value]);
    $idea = makeIdea($team, ['submitted_by_user_id' => $otherAuthor->id]);
    $originalTitle = $idea->title;

    Livewire::actingAs($employee)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->call('openEditIdea')
        ->assertForbidden();

    Livewire::actingAs($employee)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->set('editTitle', 'Hijacked title')
        ->call('updateIdea')
        ->assertForbidden();

    expect($idea->fresh()->title)->toBe($originalTitle);
});
