<?php

use App\Enums\TeamRole;
use App\Models\IdeaComment;
use App\Models\IdeaOfficialResponse;
use App\Models\IdeaOfficialResponseHistory;
use App\Models\IdeaVote;
use App\Models\User;
use App\Notifications\Ideas\OfficialResponsePublished;
use App\Notifications\Ideas\OfficialResponseUpdated;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

test('wasEdited is false when updated_at matches published_at and true once it moves past it', function () {
    $response = new IdeaOfficialResponse;
    $response->published_at = now();
    $response->updated_at = $response->published_at;

    expect($response->wasEdited())->toBeFalse();

    $response->updated_at = $response->published_at->copy()->addMinute();

    expect($response->wasEdited())->toBeTrue();
});

test('an official response belongs to an idea and its author', function () {
    ['team' => $team, 'user' => $author] = teamWithMember(TeamRole::Admin);
    $idea = makeIdea($team);

    $response = IdeaOfficialResponse::factory()->create([
        'idea_id' => $idea->id,
        'responded_by_user_id' => $author->id,
    ]);

    expect($response->idea->is($idea))->toBeTrue()
        ->and($response->respondedBy->is($author))->toBeTrue();
});

test('a soft-deleted official response no longer appears as the idea\'s active response', function () {
    ['team' => $team] = teamWithMember(TeamRole::Admin);
    $idea = makeIdea($team);
    $response = IdeaOfficialResponse::factory()->create(['idea_id' => $idea->id]);

    $response->delete();

    expect($idea->refresh()->officialResponse)->toBeNull()
        ->and(IdeaOfficialResponse::withTrashed()->find($response->id))->not->toBeNull();
});

test('an admin can publish an official response', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    $idea = makeIdea($team);

    Livewire::actingAs($admin)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->set('officialResponseBody', 'We have reviewed this and it is now on the roadmap.')
        ->call('saveOfficialResponse')
        ->assertHasNoErrors();

    $response = IdeaOfficialResponse::where('idea_id', $idea->id)->first();

    expect($response)->not->toBeNull()
        ->and($response->responded_by_user_id)->toBe($admin->id)
        ->and($response->body)->toBe('We have reviewed this and it is now on the roadmap.')
        ->and($response->published_at)->not->toBeNull();
});

test('an owner can publish an official response', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);
    $idea = makeIdea($team);

    Livewire::actingAs($owner)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->set('officialResponseBody', 'Thanks for raising this.')
        ->call('saveOfficialResponse')
        ->assertHasNoErrors();

    expect(IdeaOfficialResponse::where('idea_id', $idea->id)->count())->toBe(1);
});

test('publishing an official response records a published history entry with the actor', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    $idea = makeIdea($team);

    Livewire::actingAs($admin)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->set('officialResponseBody', 'Official word on this one.')
        ->call('saveOfficialResponse');

    $response = IdeaOfficialResponse::where('idea_id', $idea->id)->first();

    $history = IdeaOfficialResponseHistory::where('idea_id', $idea->id)->latest('id')->first();

    expect($history)->not->toBeNull()
        ->and($history->official_response_id)->toBe($response->id)
        ->and($history->actor_user_id)->toBe($admin->id)
        ->and($history->action)->toBe(IdeaOfficialResponseHistory::ACTION_PUBLISHED);
});

test('editing an official response updates the existing record instead of creating a new one', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    $idea = makeIdea($team);

    $component = Livewire::actingAs($admin)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->set('officialResponseBody', 'Initial official response.')
        ->call('saveOfficialResponse');

    $original = IdeaOfficialResponse::where('idea_id', $idea->id)->first();

    $component
        ->set('officialResponseBody', 'Updated official response.')
        ->call('saveOfficialResponse')
        ->assertHasNoErrors();

    expect(IdeaOfficialResponse::where('idea_id', $idea->id)->count())->toBe(1);

    $updated = IdeaOfficialResponse::where('idea_id', $idea->id)->first();

    expect($updated->id)->toBe($original->id)
        ->and($updated->body)->toBe('Updated official response.')
        ->and($updated->published_at->eq($original->published_at))->toBeTrue();

    $historyActions = IdeaOfficialResponseHistory::where('idea_id', $idea->id)->orderBy('id')->pluck('action')->all();

    expect($historyActions)->toBe([
        IdeaOfficialResponseHistory::ACTION_PUBLISHED,
        IdeaOfficialResponseHistory::ACTION_UPDATED,
    ]);
});

test('removing an official response soft-deletes it, hides the panel, and does not touch comments', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    $idea = makeIdea($team);

    $comment = IdeaComment::factory()->for($idea)->create();

    $component = Livewire::actingAs($admin)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->set('officialResponseBody', 'Official response to be removed.')
        ->call('saveOfficialResponse');

    $response = IdeaOfficialResponse::where('idea_id', $idea->id)->first();

    $component->call('removeOfficialResponse')->assertHasNoErrors();

    expect($response->fresh()->trashed())->toBeTrue()
        ->and($idea->refresh()->officialResponse)->toBeNull()
        ->and(IdeaComment::find($comment->id))->not->toBeNull();

    $history = IdeaOfficialResponseHistory::where('idea_id', $idea->id)->latest('id')->first();

    expect($history->action)->toBe(IdeaOfficialResponseHistory::ACTION_REMOVED)
        ->and($history->actor_user_id)->toBe($admin->id);

    $html = Livewire::actingAs($admin)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->html();

    expect($html)->not->toContain('data-test="official-response-panel"')
        ->and($html)->not->toContain('Official response to be removed.');
});

test('a manager cannot publish, edit, or remove an official response', function () {
    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);
    $idea = makeIdea($team);

    Livewire::actingAs($manager)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->set('officialResponseBody', 'Trying to sneak this in.')
        ->call('saveOfficialResponse')
        ->assertStatus(403);

    expect(IdeaOfficialResponse::where('idea_id', $idea->id)->count())->toBe(0);
})->with(['manager' => TeamRole::Manager, 'employee' => TeamRole::Employee, 'viewer' => TeamRole::Viewer]);

test('an unauthorized user cannot remove an existing official response', function (TeamRole $role) {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    $idea = makeIdea($team);

    Livewire::actingAs($admin)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->set('officialResponseBody', 'Official response.')
        ->call('saveOfficialResponse');

    $intruder = User::factory()->create();
    $team->members()->attach($intruder, ['role' => $role->value]);
    $intruder->switchTeam($team);

    $response = IdeaOfficialResponse::where('idea_id', $idea->id)->first();

    Livewire::actingAs($intruder)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->call('removeOfficialResponse')
        ->assertStatus(403);

    expect($response->fresh()->trashed())->toBeFalse();
})->with(['manager' => TeamRole::Manager, 'employee' => TeamRole::Employee, 'viewer' => TeamRole::Viewer]);

test('the official response panel is hidden when no response exists', function () {
    ['team' => $team, 'user' => $employee] = teamWithMember(TeamRole::Employee);
    $idea = makeIdea($team);

    Livewire::actingAs($employee)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->assertDontSee('Official response')
        ->assertDontSee('Add Official Response');
});

test('an admin sees an "Add Official Response" action when none exists yet', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    $idea = makeIdea($team);

    Livewire::actingAs($admin)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->assertSee('Add Official Response')
        ->assertDontSee('Official response');
});

test('a non-admin viewing a published official response cannot see edit or remove controls', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    $idea = makeIdea($team);

    Livewire::actingAs($admin)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->set('officialResponseBody', 'Public official word.')
        ->call('saveOfficialResponse');

    $employee = User::factory()->create();
    $team->members()->attach($employee, ['role' => TeamRole::Employee->value]);
    $employee->switchTeam($team);

    $html = Livewire::actingAs($employee)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->assertSee('Official response')
        ->assertSee('Public official word.')
        ->html();

    expect($html)->not->toContain('data-test="edit-official-response-button"')
        ->and($html)->not->toContain('data-test="remove-official-response-trigger"');
});

test('the official response is not counted as a comment and is rendered above the comments list', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    $idea = makeIdea($team);

    IdeaComment::factory()->for($idea)->create(['body' => 'A regular comment.']);

    Livewire::actingAs($admin)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->set('officialResponseBody', 'The official position.')
        ->call('saveOfficialResponse');

    $rendered = Livewire::actingAs($admin)
        ->test('pages::ideas.show', ['idea' => $idea->slug]);

    $rendered->assertSee('1 comment')
        ->assertSee('The official position.')
        ->assertSee('A regular comment.');

    $html = $rendered->html();

    expect(strpos($html, 'The official position.'))->toBeLessThan(strpos($html, 'A regular comment.'));
});

test('publishing an official response does not change the idea status', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    $idea = makeIdea($team, ['status' => 'new']);

    Livewire::actingAs($admin)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->set('officialResponseBody', 'Official response.')
        ->call('saveOfficialResponse');

    expect($idea->refresh()->status)->toBe('new');
});

test('changing the idea status does not auto-generate an official response', function () {
    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);
    $idea = makeIdea($team, ['status' => 'new']);

    Livewire::actingAs($manager)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->set('status', 'planned')
        ->call('updateManagement');

    expect($idea->refresh()->status)->toBe('planned')
        ->and($idea->officialResponse)->toBeNull();
});

test('publishing an official response notifies the submitter and voters but not the author', function () {
    Notification::fake();

    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    $submitter = User::factory()->create();
    $team->members()->attach($submitter, ['role' => TeamRole::Employee->value]);

    $voter = User::factory()->create();
    $team->members()->attach($voter, ['role' => TeamRole::Employee->value]);

    $idea = makeIdea($team, ['submitted_by_user_id' => $submitter->id]);
    IdeaVote::factory()->create(['idea_id' => $idea->id, 'user_id' => $voter->id]);

    Livewire::actingAs($admin)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->set('officialResponseBody', 'We are shipping this next quarter.')
        ->call('saveOfficialResponse');

    Notification::assertSentTo($submitter, OfficialResponsePublished::class);
    Notification::assertSentTo($voter, OfficialResponsePublished::class);
    Notification::assertNotSentTo($admin, OfficialResponsePublished::class);
});

test('editing a published official response sends an update notification', function () {
    Notification::fake();

    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    $submitter = User::factory()->create();
    $team->members()->attach($submitter, ['role' => TeamRole::Employee->value]);

    $idea = makeIdea($team, ['submitted_by_user_id' => $submitter->id]);

    $component = Livewire::actingAs($admin)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->set('officialResponseBody', 'Initial response.')
        ->call('saveOfficialResponse');

    Notification::assertSentTo($submitter, OfficialResponsePublished::class);

    $component
        ->set('officialResponseBody', 'Revised response.')
        ->call('saveOfficialResponse');

    Notification::assertSentTo($submitter, OfficialResponseUpdated::class);
});

test('removing an official response does not send any notification', function () {
    Notification::fake();

    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    $submitter = User::factory()->create();
    $team->members()->attach($submitter, ['role' => TeamRole::Employee->value]);

    $idea = makeIdea($team, ['submitted_by_user_id' => $submitter->id]);

    $component = Livewire::actingAs($admin)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->set('officialResponseBody', 'Initial response.')
        ->call('saveOfficialResponse');

    Notification::fake();

    $component->call('removeOfficialResponse');

    Notification::assertNothingSent();
});

test('publishing and removing an official response appear in the activity timeline', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    $idea = makeIdea($team);

    $component = Livewire::actingAs($admin)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->set('officialResponseBody', 'Official response for the timeline.')
        ->call('saveOfficialResponse');

    $component->assertSee('Official response published');

    $component->call('removeOfficialResponse');

    $component->assertSee('Official response removed');
});
