<?php

use App\Enums\TeamRole;
use App\Models\IdeaComment;
use Livewire\Livewire;

test('an admin can hide a comment', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    $idea = makeIdea($team);
    $comment = IdeaComment::factory()->create(['idea_id' => $idea->id, 'user_id' => $admin->id]);

    Livewire::actingAs($admin)
        ->test('pages::ideas.moderate-comments')
        ->call('hideComment', $comment->id)
        ->assertHasNoErrors();

    $comment->refresh();

    expect($comment->isHidden())->toBeTrue()
        ->and($comment->hidden_by_user_id)->toBe($admin->id);
});

test('an admin can restore a hidden comment', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    $idea = makeIdea($team);
    $comment = IdeaComment::factory()->create(['idea_id' => $idea->id, 'user_id' => $admin->id]);
    $comment->hide($admin->id);

    Livewire::actingAs($admin)
        ->test('pages::ideas.moderate-comments')
        ->call('unhideComment', $comment->id)
        ->assertHasNoErrors();

    expect($comment->refresh()->isHidden())->toBeFalse();
});

test('a hidden comment disappears from the idea thread', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    $idea = makeIdea($team);
    $comment = IdeaComment::factory()->create(['idea_id' => $idea->id, 'user_id' => $admin->id, 'body' => 'Visible then hidden comment']);

    Livewire::actingAs($admin)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->assertSee('Visible then hidden comment');

    $comment->hide($admin->id);

    Livewire::actingAs($admin)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->assertDontSee('Visible then hidden comment');
});

test('a manager cannot hide a comment', function () {
    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);
    $idea = makeIdea($team);
    $comment = IdeaComment::factory()->create(['idea_id' => $idea->id, 'user_id' => $manager->id]);

    $this->actingAs($manager)
        ->get(route('ideas.moderate-comments', ['current_team' => $team->slug]))
        ->assertForbidden();

    expect($comment->fresh()->isHidden())->toBeFalse();
});

test('the hidden filter only shows hidden comments', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    $idea = makeIdea($team);

    $visible = IdeaComment::factory()->create(['idea_id' => $idea->id, 'user_id' => $admin->id, 'body' => 'Still visible comment']);
    $hidden = IdeaComment::factory()->create(['idea_id' => $idea->id, 'user_id' => $admin->id, 'body' => 'Already hidden comment']);
    $hidden->hide($admin->id);

    Livewire::actingAs($admin)
        ->test('pages::ideas.moderate-comments')
        ->set('filter', 'hidden')
        ->assertSee('Already hidden comment')
        ->assertDontSee('Still visible comment');
});
