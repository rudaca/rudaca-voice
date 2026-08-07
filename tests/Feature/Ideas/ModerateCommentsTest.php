<?php

use App\Enums\TeamRole;
use App\Models\IdeaComment;
use App\Models\User;
use Livewire\Livewire;

test('the comment actions menu links to the idea', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    $idea = makeIdea($team);
    IdeaComment::factory()->create(['idea_id' => $idea->id, 'user_id' => $admin->id]);

    Livewire::actingAs($admin)
        ->test('pages::ideas.moderate-comments')
        ->assertSeeHtml(route('ideas.show', ['idea' => $idea->slug]));
});

test('a comment on an idea with a status not in STATUS_META renders without error', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);

    // Simulates a legacy/unmapped status value (e.g. pre-migration data),
    // which previously crashed with "Undefined array key badge_dot" because
    // the statusMeta() fallback didn't include that key.
    $idea = makeIdea($team, ['status' => 'legacy_status']);
    IdeaComment::factory()->create(['idea_id' => $idea->id, 'user_id' => $admin->id]);

    Livewire::actingAs($admin)
        ->test('pages::ideas.moderate-comments')
        ->assertOk();
});

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

test('a flagged comment is replaced with a moderation notice in the idea thread, not deleted', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    $idea = makeIdea($team);
    $comment = IdeaComment::factory()->create(['idea_id' => $idea->id, 'user_id' => $admin->id, 'body' => 'Visible then flagged comment']);

    Livewire::actingAs($admin)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->assertSee('Visible then flagged comment');

    $comment->hide($admin->id);

    Livewire::actingAs($admin)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->assertDontSee('Visible then flagged comment')
        ->assertSee('This comment was flagged by a moderator.')
        ->assertSee($admin->email);

    expect($comment->fresh())
        ->trashed()->toBeFalse()
        ->body->toBe('Visible then flagged comment');
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

test('the visible filter only shows unflagged, non-deleted comments', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    $idea = makeIdea($team);

    $visible = IdeaComment::factory()->create(['idea_id' => $idea->id, 'user_id' => $admin->id, 'body' => 'Still visible unicorn comment']);

    $flagged = IdeaComment::factory()->create(['idea_id' => $idea->id, 'user_id' => $admin->id, 'body' => 'Flagged unicorn comment']);
    $flagged->hide($admin->id);

    $deleted = IdeaComment::factory()->create(['idea_id' => $idea->id, 'user_id' => $admin->id, 'body' => 'Deleted unicorn comment']);
    $deleted->delete();

    Livewire::actingAs($admin)
        ->test('pages::ideas.moderate-comments')
        ->set('filter', 'visible')
        ->assertSee('Still visible unicorn comment')
        ->assertDontSee('Flagged unicorn comment')
        ->assertDontSee('Deleted unicorn comment');
});

test('the board filter only shows comments on ideas from the selected board', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    $ideaOnBoardA = makeIdea($team);
    $ideaOnBoardB = makeIdea($team);

    IdeaComment::factory()->create(['idea_id' => $ideaOnBoardA->id, 'user_id' => $admin->id, 'body' => 'Comment on board A']);
    IdeaComment::factory()->create(['idea_id' => $ideaOnBoardB->id, 'user_id' => $admin->id, 'body' => 'Comment on board B']);

    Livewire::actingAs($admin)
        ->test('pages::ideas.moderate-comments')
        ->set('board', [$ideaOnBoardA->board_id])
        ->assertSee('Comment on board A')
        ->assertDontSee('Comment on board B');
});

test('the group filter only shows comments on ideas from the selected board group', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    $ideaOnGroupA = makeIdea($team);
    $ideaOnGroupB = makeIdea($team);

    IdeaComment::factory()->create(['idea_id' => $ideaOnGroupA->id, 'user_id' => $admin->id, 'body' => 'Comment on group A']);
    IdeaComment::factory()->create(['idea_id' => $ideaOnGroupB->id, 'user_id' => $admin->id, 'body' => 'Comment on group B']);

    Livewire::actingAs($admin)
        ->test('pages::ideas.moderate-comments')
        ->set('group', $ideaOnGroupA->board_group_id)
        ->assertSee('Comment on group A')
        ->assertDontSee('Comment on group B');
});

test('the category filter only shows comments on ideas with the selected category', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    $ideaInCategoryA = makeIdea($team);
    $ideaInCategoryB = makeIdea($team);

    IdeaComment::factory()->create(['idea_id' => $ideaInCategoryA->id, 'user_id' => $admin->id, 'body' => 'Comment in category A']);
    IdeaComment::factory()->create(['idea_id' => $ideaInCategoryB->id, 'user_id' => $admin->id, 'body' => 'Comment in category B']);

    Livewire::actingAs($admin)
        ->test('pages::ideas.moderate-comments')
        ->set('category', [$ideaInCategoryA->category->name])
        ->assertSee('Comment in category A')
        ->assertDontSee('Comment in category B');
});

test('the author filter only shows comments written by the selected author', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    $idea = makeIdea($team);
    $otherAuthor = User::factory()->create();

    IdeaComment::factory()->create(['idea_id' => $idea->id, 'user_id' => $admin->id, 'body' => 'Comment from admin']);
    IdeaComment::factory()->create(['idea_id' => $idea->id, 'user_id' => $otherAuthor->id, 'body' => 'Comment from other author']);

    Livewire::actingAs($admin)
        ->test('pages::ideas.moderate-comments')
        ->set('author', [$otherAuthor->id])
        ->assertSee('Comment from other author')
        ->assertDontSee('Comment from admin');
});

test('the date range filter only shows comments created within the range', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    $idea = makeIdea($team);

    IdeaComment::factory()->create(['idea_id' => $idea->id, 'user_id' => $admin->id, 'body' => 'Comment in range', 'created_at' => '2026-01-15']);
    IdeaComment::factory()->create(['idea_id' => $idea->id, 'user_id' => $admin->id, 'body' => 'Comment out of range', 'created_at' => '2026-03-01']);

    Livewire::actingAs($admin)
        ->test('pages::ideas.moderate-comments')
        ->set('dateFrom', '2026-01-01')
        ->set('dateTo', '2026-01-31')
        ->assertSee('Comment in range')
        ->assertDontSee('Comment out of range');
});

test('the search filter only shows comments matching the search term', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    $idea = makeIdea($team);

    IdeaComment::factory()->create(['idea_id' => $idea->id, 'user_id' => $admin->id, 'body' => 'This mentions unicorns']);
    IdeaComment::factory()->create(['idea_id' => $idea->id, 'user_id' => $admin->id, 'body' => 'This does not']);

    Livewire::actingAs($admin)
        ->test('pages::ideas.moderate-comments')
        ->set('search', 'unicorns')
        ->assertSee('This mentions unicorns')
        ->assertDontSee('This does not');
});

test('the status filter only shows comments on ideas with the selected status', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    $newIdea = makeIdea($team, ['status' => 'new']);
    $plannedIdea = makeIdea($team, ['status' => 'planned']);

    IdeaComment::factory()->create(['idea_id' => $newIdea->id, 'user_id' => $admin->id, 'body' => 'Comment on new idea']);
    IdeaComment::factory()->create(['idea_id' => $plannedIdea->id, 'user_id' => $admin->id, 'body' => 'Comment on planned idea']);

    Livewire::actingAs($admin)
        ->test('pages::ideas.moderate-comments')
        ->set('status', ['planned'])
        ->assertSee('Comment on planned idea')
        ->assertDontSee('Comment on new idea');
});

test('the deleted filter only shows soft-deleted comments', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    $idea = makeIdea($team);

    $active = IdeaComment::factory()->create(['idea_id' => $idea->id, 'user_id' => $admin->id, 'body' => 'Still active comment']);
    $deleted = IdeaComment::factory()->create(['idea_id' => $idea->id, 'user_id' => $admin->id, 'body' => 'Soft-deleted comment']);
    $deleted->delete();

    Livewire::actingAs($admin)
        ->test('pages::ideas.moderate-comments')
        ->set('filter', 'deleted')
        ->assertSee('Soft-deleted comment')
        ->assertDontSee('Still active comment');
});

test('the stats reflect total, under-review, flagged, and deleted comment counts', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    $idea = makeIdea($team);

    IdeaComment::factory()->create(['idea_id' => $idea->id, 'user_id' => $admin->id]);
    IdeaComment::factory()->create(['idea_id' => $idea->id, 'user_id' => $admin->id]);

    $flagged = IdeaComment::factory()->create(['idea_id' => $idea->id, 'user_id' => $admin->id]);
    $flagged->hide($admin->id);

    $deleted = IdeaComment::factory()->create(['idea_id' => $idea->id, 'user_id' => $admin->id]);
    $deleted->delete();

    $stats = Livewire::actingAs($admin)
        ->test('pages::ideas.moderate-comments')
        ->instance()
        ->stats();

    expect($stats)->toBe([
        'total' => 4,
        'underReview' => 2,
        'flagged' => 1,
        'deleted' => 1,
    ]);
});

test('narrowing the board filter narrows every stat card', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);

    $stackA = boardStack($team);
    $stackB = boardStack($team);

    $ideaA = makeIdea($team, [
        'board_id' => $stackA['board']->id,
        'board_group_id' => $stackA['group']->id,
        'category_id' => $stackA['category']->id,
    ]);
    $ideaB = makeIdea($team, [
        'board_id' => $stackB['board']->id,
        'board_group_id' => $stackB['group']->id,
        'category_id' => $stackB['category']->id,
    ]);

    IdeaComment::factory()->create(['idea_id' => $ideaA->id, 'user_id' => $admin->id]);
    IdeaComment::factory()->create(['idea_id' => $ideaA->id, 'user_id' => $admin->id])->hide($admin->id);
    IdeaComment::factory()->create(['idea_id' => $ideaB->id, 'user_id' => $admin->id]);

    Livewire::actingAs($admin)
        ->test('pages::ideas.moderate-comments')
        ->assertSet('stats.total', 3)
        ->set('board', [(string) $stackA['board']->id])
        ->assertSet('stats.total', 2)
        ->assertSet('stats.underReview', 1)
        ->assertSet('stats.flagged', 1)
        ->assertSet('stats.deleted', 0);
});

test('the moderation state tabs leave the stat cards alone', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    $idea = makeIdea($team);

    IdeaComment::factory()->create(['idea_id' => $idea->id, 'user_id' => $admin->id]);
    IdeaComment::factory()->create(['idea_id' => $idea->id, 'user_id' => $admin->id])->hide($admin->id);

    // The cards are the per-state breakdown, so the active tab must not narrow them.
    Livewire::actingAs($admin)
        ->test('pages::ideas.moderate-comments')
        ->set('filter', 'hidden')
        ->assertSet('stats.total', 2)
        ->assertSet('stats.underReview', 1)
        ->assertSet('stats.flagged', 1);
});

test('a stat card selects the moderation state tab it mirrors', function (string $tab) {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    $idea = makeIdea($team);

    IdeaComment::factory()->create(['idea_id' => $idea->id, 'user_id' => $admin->id]);
    IdeaComment::factory()->create(['idea_id' => $idea->id, 'user_id' => $admin->id])->hide($admin->id);
    IdeaComment::factory()->create(['idea_id' => $idea->id, 'user_id' => $admin->id])->delete();

    Livewire::actingAs($admin)
        ->test('pages::ideas.moderate-comments')
        ->call('selectFilter', $tab)
        ->assertSet('filter', $tab)
        // Selecting a tab from a card still leaves the breakdown intact.
        ->assertSet('stats.total', 3)
        ->assertSet('stats.underReview', 1)
        ->assertSet('stats.flagged', 1)
        ->assertSet('stats.deleted', 1);
})->with(['all', 'visible', 'hidden', 'deleted']);

test('an unknown tab value falls back to all rather than filtering to nothing', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    makeIdea($team);

    Livewire::actingAs($admin)
        ->test('pages::ideas.moderate-comments')
        ->set('filter', 'hidden')
        ->call('selectFilter', 'bogus')
        ->assertSet('filter', 'all');
});

test('only the stat card mirroring the active tab is marked active', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    makeIdea($team);

    $html = Livewire::actingAs($admin)
        ->test('pages::ideas.moderate-comments')
        ->set('filter', 'hidden')
        ->html();

    expect(substr_count($html, 'data-active-tab'))->toBe(1)
        ->and($html)->toContain('aria-pressed="true"')
        // The highlight is keyed by tab so it remounts (and replays its animation) on each selection.
        ->and(substr_count($html, 'stat-card-active-ring'))->toBe(1)
        ->and($html)->toContain('wire:key="stat-active-ring-hidden"');

    // The Flagged card carries the marker, and only it.
    preg_match('/data-test="stat-flagged"[^>]*/', $html, $matches);
    expect($matches[0] ?? '')->toContain('data-active-tab');
});

test('the visible stat card is labelled Visible, matching its tab', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    makeIdea($team);

    Livewire::actingAs($admin)
        ->test('pages::ideas.moderate-comments')
        ->assertSee('Visible')
        ->assertDontSee('Under review');
});

test('the stat figures render as rolling-number odometers', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    $idea = makeIdea($team);
    IdeaComment::factory()->create(['idea_id' => $idea->id, 'user_id' => $admin->id]);

    Livewire::actingAs($admin)
        ->test('pages::ideas.moderate-comments')
        ->assertSeeHtml('rolling-total-label')
        ->assertSeeHtml('rolling-under-review-label')
        ->assertSeeHtml('rolling-flagged-label')
        ->assertSeeHtml('rolling-deleted-label');
});

test('an owner can permanently delete a soft-deleted comment', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);
    $idea = makeIdea($team);
    $comment = IdeaComment::factory()->create(['idea_id' => $idea->id, 'user_id' => $owner->id]);
    $comment->delete();

    Livewire::actingAs($owner)
        ->test('pages::ideas.moderate-comments')
        ->set('filter', 'deleted')
        ->call('permanentlyDeleteComment', $comment->id)
        ->assertHasNoErrors();

    expect(IdeaComment::withTrashed()->find($comment->id))->toBeNull();
});

test('an admin cannot permanently delete a soft-deleted comment', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    $idea = makeIdea($team);
    $comment = IdeaComment::factory()->create(['idea_id' => $idea->id, 'user_id' => $admin->id]);
    $comment->delete();

    Livewire::actingAs($admin)
        ->test('pages::ideas.moderate-comments')
        ->set('filter', 'deleted')
        ->call('permanentlyDeleteComment', $comment->id)
        ->assertForbidden();

    expect(IdeaComment::withTrashed()->find($comment->id))->not->toBeNull();
});

test('an admin can restore a soft-deleted comment', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    $idea = makeIdea($team);
    $comment = IdeaComment::factory()->create(['idea_id' => $idea->id, 'user_id' => $admin->id]);
    $comment->delete();

    Livewire::actingAs($admin)
        ->test('pages::ideas.moderate-comments')
        ->set('filter', 'deleted')
        ->call('restoreComment', $comment->id)
        ->assertHasNoErrors();

    expect($comment->fresh()->trashed())->toBeFalse();
});

test('a manager cannot restore a soft-deleted comment', function () {
    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);
    $idea = makeIdea($team);
    $comment = IdeaComment::factory()->create(['idea_id' => $idea->id, 'user_id' => $manager->id]);
    $comment->delete();

    Livewire::actingAs($manager)
        ->test('pages::ideas.moderate-comments')
        ->call('restoreComment', $comment->id)
        ->assertForbidden();

    expect($comment->fresh()->trashed())->toBeTrue();
});
