<?php

use App\Enums\TeamRole;
use App\Models\IdeaOfficialResponse;
use App\Models\User;
use App\Notifications\Ideas\IdeaCompleted;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

test('moving an idea to Completed emails its author', function () {
    Notification::fake();

    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);
    $author = User::factory()->create();
    $team->members()->attach($author, ['role' => TeamRole::Employee->value]);

    $idea = makeIdea($team, ['status' => 'planned', 'submitted_by_user_id' => $author->id]);

    Livewire::actingAs($manager)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->set('status', 'released')
        ->call('attemptUpdateManagement')
        ->call('confirmUpdateManagement')
        ->assertHasNoErrors();

    expect($idea->refresh()->status)->toBe('released');

    Notification::assertSentTo($author, IdeaCompleted::class);
});

test('an idea submitted on behalf of another user notifies the actual author, not the enterer', function () {
    Notification::fake();

    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);
    $author = User::factory()->create();
    $team->members()->attach($author, ['role' => TeamRole::Employee->value]);
    $enteredBy = User::factory()->create();
    $team->members()->attach($enteredBy, ['role' => TeamRole::Employee->value]);

    $idea = makeIdea($team, [
        'status' => 'planned',
        'submitted_by_user_id' => $author->id,
        'entered_by_user_id' => $enteredBy->id,
    ]);

    Livewire::actingAs($manager)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->set('status', 'released')
        ->call('attemptUpdateManagement')
        ->call('confirmUpdateManagement');

    Notification::assertSentTo($author, IdeaCompleted::class);
    Notification::assertNotSentTo($enteredBy, IdeaCompleted::class);
});

test('moving an idea to a non-Completed status does not send the notification', function () {
    Notification::fake();

    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);
    $author = User::factory()->create();
    $team->members()->attach($author, ['role' => TeamRole::Employee->value]);

    $idea = makeIdea($team, ['status' => 'new', 'submitted_by_user_id' => $author->id]);

    Livewire::actingAs($manager)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->set('status', 'planned')
        ->call('attemptUpdateManagement');

    expect($idea->refresh()->status)->toBe('planned');

    Notification::assertNothingSent();
});

test('editing an already Completed idea does not resend the notification', function () {
    Notification::fake();

    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);
    $author = User::factory()->create();
    $team->members()->attach($author, ['role' => TeamRole::Employee->value]);

    $idea = makeIdea($team, ['status' => 'planned', 'submitted_by_user_id' => $author->id]);

    $component = Livewire::actingAs($manager)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->set('status', 'released')
        ->call('attemptUpdateManagement')
        ->call('confirmUpdateManagement');

    Notification::assertSentToTimes($author, IdeaCompleted::class, 1);

    $component
        ->set('priority', 'high')
        ->call('attemptUpdateManagement');

    expect($idea->refresh()->status)->toBe('released');

    Notification::assertSentToTimes($author, IdeaCompleted::class, 1);
});

test('a manager completing their own idea still emails them', function () {
    Notification::fake();

    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);
    $idea = makeIdea($team, ['status' => 'planned', 'submitted_by_user_id' => $manager->id]);

    Livewire::actingAs($manager)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->set('status', 'released')
        ->call('attemptUpdateManagement')
        ->call('confirmUpdateManagement');

    expect($idea->refresh()->status)->toBe('released');

    Notification::assertSentTo($manager, IdeaCompleted::class);
});

test('the Completed email includes the idea title, a link, and the official response when present', function () {
    ['team' => $team] = teamWithMember(TeamRole::Manager);
    $author = User::factory()->create();
    $idea = makeIdea($team, ['title' => 'Add dark mode', 'submitted_by_user_id' => $author->id]);

    $mail = (new IdeaCompleted($idea))->toMail($author);
    $rendered = (string) $mail->render();

    expect($mail->subject)->toBe("Your idea has been completed -(Idea #{$idea->id})")
        ->and($rendered)->toContain('Add dark mode')
        ->and($rendered)->toContain(route('ideas.show', ['current_team' => $team->slug, 'idea' => $idea->slug]))
        ->and($rendered)->not->toContain('Official response:');

    IdeaOfficialResponse::factory()->create([
        'idea_id' => $idea->id,
        'body' => 'Shipped in version 4.2.',
    ]);

    $mailWithResponse = (new IdeaCompleted($idea->refresh()))->toMail($author);
    $renderedWithResponse = (string) $mailWithResponse->render();

    expect($renderedWithResponse)->toContain('Official response:')
        ->and($renderedWithResponse)->toContain('Shipped in version 4.2.');
});
