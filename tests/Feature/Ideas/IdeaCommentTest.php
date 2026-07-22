<?php

use App\Enums\TeamRole;
use App\Models\IdeaComment;
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
