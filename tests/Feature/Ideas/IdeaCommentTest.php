<?php

use App\Enums\AccessLevel;
use App\Enums\TeamRole;
use App\Models\IdeaBoardRoleAccess;
use App\Models\IdeaBoardUserAccess;
use App\Models\IdeaComment;
use App\Models\User;
use Livewire\Livewire;

test('owner, admin, manager and employee can comment on an idea', function (TeamRole $role) {
    ['team' => $team, 'user' => $user] = teamWithMember($role);
    $idea = makeIdea($team);

    Livewire::actingAs($user)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->set('commentBody', 'Great idea, would love to see this shipped.')
        ->call('addComment')
        ->assertHasNoErrors();

    expect(IdeaComment::where('idea_id', $idea->id)->where('user_id', $user->id)->count())->toBe(1);
})->with([
    'owner' => TeamRole::Owner,
    'admin' => TeamRole::Admin,
    'manager' => TeamRole::Manager,
    'employee' => TeamRole::Employee,
]);

test('owner, admin and manager can post a private management note', function (TeamRole $role) {
    ['team' => $team, 'user' => $user] = teamWithMember($role);
    $idea = makeIdea($team);

    Livewire::actingAs($user)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->set('commentBody', 'Private note for the management team.')
        ->set('isPrivateNote', true)
        ->call('addComment')
        ->assertHasNoErrors();

    $comment = IdeaComment::where('idea_id', $idea->id)->where('user_id', $user->id)->first();

    expect($comment->is_internal)->toBeTrue();
})->with([
    'owner' => TeamRole::Owner,
    'admin' => TeamRole::Admin,
    'manager' => TeamRole::Manager,
]);

test('an employee cannot force a comment to be a private note', function () {
    ['team' => $team, 'user' => $employee] = teamWithMember(TeamRole::Employee);
    $idea = makeIdea($team);

    Livewire::actingAs($employee)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->set('commentBody', 'Trying to sneak a private note.')
        ->set('isPrivateNote', true)
        ->call('addComment')
        ->assertHasNoErrors();

    $comment = IdeaComment::where('idea_id', $idea->id)->where('user_id', $employee->id)->first();

    expect($comment->is_internal)->toBeFalse();
});

test('the private management note checkbox is hidden from employees', function () {
    ['team' => $team, 'user' => $employee] = teamWithMember(TeamRole::Employee);
    $idea = makeIdea($team);

    Livewire::actingAs($employee)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->assertDontSee('Private management note');
});

test('a board-scoped employee with a Manage user-access grant can post and view a private note on their board only', function () {
    ['team' => $team, 'user' => $employee] = teamWithMember(TeamRole::Employee);
    $idea = makeIdea($team);
    $otherIdea = makeIdea($team);

    IdeaBoardUserAccess::factory()->create([
        'board_id' => $idea->board_id,
        'user_id' => $employee->id,
        'access_level' => AccessLevel::Manage,
    ]);

    Livewire::actingAs($employee)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->set('commentBody', 'Board-scoped private note.')
        ->set('isPrivateNote', true)
        ->call('addComment')
        ->assertHasNoErrors();

    $comment = IdeaComment::where('idea_id', $idea->id)->where('user_id', $employee->id)->first();
    expect($comment->is_internal)->toBeTrue();

    Livewire::actingAs($employee)
        ->test('pages::ideas.show', ['idea' => $otherIdea->slug])
        ->set('commentBody', 'Trying on a board I do not manage.')
        ->set('isPrivateNote', true)
        ->call('addComment')
        ->assertHasNoErrors();

    $otherComment = IdeaComment::where('idea_id', $otherIdea->id)->where('user_id', $employee->id)->first();
    expect($otherComment->is_internal)->toBeFalse();
});

test('a board-scoped employee with a role-access grant can post and view a private note on their board only', function () {
    ['team' => $team, 'user' => $employee] = teamWithMember(TeamRole::Employee);
    $idea = makeIdea($team);
    $otherIdea = makeIdea($team);

    IdeaBoardRoleAccess::factory()->create([
        'board_id' => $idea->board_id,
        'role' => TeamRole::Employee,
    ]);

    Livewire::actingAs($employee)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->set('commentBody', 'Role-scoped private note.')
        ->set('isPrivateNote', true)
        ->call('addComment')
        ->assertHasNoErrors();

    $comment = IdeaComment::where('idea_id', $idea->id)->where('user_id', $employee->id)->first();
    expect($comment->is_internal)->toBeTrue();

    Livewire::actingAs($employee)
        ->test('pages::ideas.show', ['idea' => $otherIdea->slug])
        ->set('commentBody', 'Trying on a board without the role grant.')
        ->set('isPrivateNote', true)
        ->call('addComment')
        ->assertHasNoErrors();

    $otherComment = IdeaComment::where('idea_id', $otherIdea->id)->where('user_id', $employee->id)->first();
    expect($otherComment->is_internal)->toBeFalse();
});

test('View or Contribute board access does not grant private-note permission', function (AccessLevel $accessLevel) {
    ['team' => $team, 'user' => $employee] = teamWithMember(TeamRole::Employee);
    $idea = makeIdea($team);

    IdeaBoardUserAccess::factory()->create([
        'board_id' => $idea->board_id,
        'user_id' => $employee->id,
        'access_level' => $accessLevel,
    ]);

    Livewire::actingAs($employee)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->set('commentBody', 'Trying with insufficient board access.')
        ->set('isPrivateNote', true)
        ->call('addComment')
        ->assertHasNoErrors();

    $comment = IdeaComment::where('idea_id', $idea->id)->where('user_id', $employee->id)->first();
    expect($comment->is_internal)->toBeFalse();
})->with([
    'view' => AccessLevel::View,
    'contribute' => AccessLevel::Contribute,
]);

test('an existing private note comment remains private after the rename', function () {
    ['team' => $team, 'user' => $employee] = teamWithMember(TeamRole::Employee);
    $idea = makeIdea($team);

    $note = IdeaComment::factory()->privateNote()->create(['idea_id' => $idea->id]);

    Livewire::actingAs($employee)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->assertDontSee($note->body);

    $manager = User::factory()->create();
    $team->members()->attach($manager, ['role' => TeamRole::Manager->value]);
    $manager->switchTeam($team);

    Livewire::actingAs($manager)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->assertSee($note->body);
});

test('a guest is redirected instead of seeing the idea page', function () {
    ['team' => $team] = teamWithMember(TeamRole::Employee);
    $idea = makeIdea($team);

    $this->get(route('ideas.show', ['current_team' => $team->slug, 'idea' => $idea->slug]))
        ->assertRedirect();
});

test('a viewer cannot comment on an idea', function () {
    ['team' => $team, 'user' => $viewer] = teamWithMember(TeamRole::Viewer);
    $idea = makeIdea($team);

    Livewire::actingAs($viewer)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->set('commentBody', 'Trying to comment as a viewer.')
        ->call('addComment')
        ->assertStatus(403);

    expect(IdeaComment::where('idea_id', $idea->id)->count())->toBe(0);
});

test('the comment composer is hidden for a viewer', function () {
    ['team' => $team, 'user' => $viewer] = teamWithMember(TeamRole::Viewer);
    $idea = makeIdea($team);

    Livewire::actingAs($viewer)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->assertSee('Viewers have read-only access')
        ->assertDontSee('Comment');
});

test('an admin can flag and unflag a comment from the idea page', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    $idea = makeIdea($team);
    $comment = IdeaComment::factory()->create(['idea_id' => $idea->id, 'user_id' => $admin->id]);

    Livewire::actingAs($admin)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->call('hideComment', $comment->id)
        ->assertHasNoErrors();

    expect($comment->refresh()->isHidden())->toBeTrue();

    Livewire::actingAs($admin)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->call('unhideComment', $comment->id)
        ->assertHasNoErrors();

    expect($comment->refresh()->isHidden())->toBeFalse();
});

test('a manager cannot flag a comment from the idea page', function () {
    ['team' => $team, 'user' => $author] = teamWithMember(TeamRole::Employee);
    $idea = makeIdea($team);
    $comment = IdeaComment::factory()->create(['idea_id' => $idea->id, 'user_id' => $author->id]);

    $manager = User::factory()->create();
    $team->members()->attach($manager, ['role' => TeamRole::Manager->value]);
    $manager->switchTeam($team);

    Livewire::actingAs($manager)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->call('hideComment', $comment->id)
        ->assertStatus(403);

    expect($comment->refresh()->isHidden())->toBeFalse();
});

test('the moderation menu is hidden from a manager but visible to an admin', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    $idea = makeIdea($team);
    IdeaComment::factory()->create(['idea_id' => $idea->id, 'user_id' => $admin->id]);

    $manager = User::factory()->create();
    $team->members()->attach($manager, ['role' => TeamRole::Manager->value]);
    $manager->switchTeam($team);

    Livewire::actingAs($manager)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->assertDontSeeHtml('data-test="comment-actions-trigger"');

    Livewire::actingAs($admin)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->assertSeeHtml('data-test="comment-actions-trigger"');
});
