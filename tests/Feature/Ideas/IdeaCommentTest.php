<?php

use App\Enums\TeamRole;
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

test('a manager can post an internal comment', function () {
    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);
    $idea = makeIdea($team);

    Livewire::actingAs($manager)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->set('commentBody', 'Internal note for staff.')
        ->set('isInternal', true)
        ->call('addComment')
        ->assertHasNoErrors();

    $comment = IdeaComment::where('idea_id', $idea->id)->where('user_id', $manager->id)->first();

    expect($comment->is_internal)->toBeTrue();
});

test('an employee cannot force a comment to be internal', function () {
    ['team' => $team, 'user' => $employee] = teamWithMember(TeamRole::Employee);
    $idea = makeIdea($team);

    Livewire::actingAs($employee)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->set('commentBody', 'Trying to sneak an internal note.')
        ->set('isInternal', true)
        ->call('addComment')
        ->assertHasNoErrors();

    $comment = IdeaComment::where('idea_id', $idea->id)->where('user_id', $employee->id)->first();

    expect($comment->is_internal)->toBeFalse();
});

test('the internal note checkbox is hidden from employees', function () {
    ['team' => $team, 'user' => $employee] = teamWithMember(TeamRole::Employee);
    $idea = makeIdea($team);

    Livewire::actingAs($employee)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->assertDontSee('Internal note');
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
