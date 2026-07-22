<?php

use App\Enums\TeamRole;
use App\Models\IdeaComment;
use App\Models\User;
use Livewire\Livewire;

test('an owner can delete an idea', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);
    $idea = makeIdea($team);

    Livewire::actingAs($owner)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->call('deleteIdea')
        ->assertHasNoErrors()
        ->assertRedirect(route('ideas.index'));

    $this->assertSoftDeleted('ideas', ['id' => $idea->id]);
});

test('a deleted idea no longer appears in the All Ideas list', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);
    $idea = makeIdea($team, ['title' => 'Idea to delete']);

    Livewire::actingAs($owner)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->call('deleteIdea');

    Livewire::actingAs($owner)
        ->test('pages::ideas.index')
        ->assertDontSee('Idea to delete');
});

test('a deleted idea 404s on direct access', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);
    $idea = makeIdea($team);

    Livewire::actingAs($owner)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->call('deleteIdea');

    $this->actingAs($owner)
        ->get(route('ideas.show', ['current_team' => $team->slug, 'idea' => $idea->slug]))
        ->assertNotFound();
});

test('an admin cannot delete an idea', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);
    $idea = makeIdea($team);

    $admin = User::factory()->create();
    $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);
    $admin->switchTeam($team);

    Livewire::actingAs($admin)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->call('deleteIdea')
        ->assertStatus(403);

    $this->assertDatabaseHas('ideas', ['id' => $idea->id, 'deleted_at' => null]);
});

test('a manager cannot delete an idea', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);
    $idea = makeIdea($team);

    $manager = User::factory()->create();
    $team->members()->attach($manager, ['role' => TeamRole::Manager->value]);
    $manager->switchTeam($team);

    Livewire::actingAs($manager)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->call('deleteIdea')
        ->assertStatus(403);

    $this->assertDatabaseHas('ideas', ['id' => $idea->id, 'deleted_at' => null]);
});

test('the delete idea button is hidden from non owners', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);
    $idea = makeIdea($team);

    $manager = User::factory()->create();
    $team->members()->attach($manager, ['role' => TeamRole::Manager->value]);
    $manager->switchTeam($team);

    Livewire::actingAs($manager)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->assertDontSeeHtml('data-test="delete-idea-button"');
});

test('an owner can delete a comment', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);
    $idea = makeIdea($team);
    $comment = IdeaComment::factory()->create(['idea_id' => $idea->id, 'user_id' => $owner->id]);

    Livewire::actingAs($owner)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->call('deleteComment', $comment->id)
        ->assertHasNoErrors();

    $this->assertSoftDeleted('idea_comments', ['id' => $comment->id]);
});

test('a manager cannot delete a comment', function () {
    ['team' => $team, 'user' => $author] = teamWithMember(TeamRole::Employee);
    $idea = makeIdea($team);
    $comment = IdeaComment::factory()->create(['idea_id' => $idea->id, 'user_id' => $author->id]);

    $manager = User::factory()->create();
    $team->members()->attach($manager, ['role' => TeamRole::Manager->value]);
    $manager->switchTeam($team);

    Livewire::actingAs($manager)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->call('deleteComment', $comment->id)
        ->assertStatus(403);

    $this->assertDatabaseHas('idea_comments', ['id' => $comment->id, 'deleted_at' => null]);
});
