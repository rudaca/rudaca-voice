<?php

use App\Enums\TeamRole;
use App\Models\IdeaAttachment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('a manager can add and remove attachments on an idea they did not submit', function () {
    Storage::fake(config('idea_attachments.disk'));

    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);
    $submitter = User::factory()->create();
    $team->members()->attach($submitter, ['role' => TeamRole::Employee->value]);
    $idea = makeIdea($team, ['submitted_by_user_id' => $submitter->id]);

    $file = UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf');

    $component = Livewire::actingAs($manager)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->set('newAttachments', [$file])
        ->call('addAttachments')
        ->assertHasNoErrors();

    $attachment = IdeaAttachment::where('idea_id', $idea->id)->firstOrFail();

    $component->call('removeAttachment', $attachment->id)->assertHasNoErrors();

    expect(IdeaAttachment::find($attachment->id))->toBeNull();
});

test('the original submitter can add and remove attachments on their own idea despite an employee role', function () {
    Storage::fake(config('idea_attachments.disk'));

    ['team' => $team, 'user' => $submitter] = teamWithMember(TeamRole::Employee);
    $idea = makeIdea($team, ['submitted_by_user_id' => $submitter->id]);

    $file = UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf');

    $component = Livewire::actingAs($submitter)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->set('newAttachments', [$file])
        ->call('addAttachments')
        ->assertHasNoErrors();

    $attachment = IdeaAttachment::where('idea_id', $idea->id)->firstOrFail();

    $component->call('removeAttachment', $attachment->id)->assertHasNoErrors();

    expect(IdeaAttachment::find($attachment->id))->toBeNull();
});

test('a non-submitter employee cannot add or remove attachments', function () {
    Storage::fake(config('idea_attachments.disk'));

    ['team' => $team, 'user' => $submitter] = teamWithMember(TeamRole::Employee);
    $idea = makeIdea($team, ['submitted_by_user_id' => $submitter->id]);

    $intruder = User::factory()->create();
    $team->members()->attach($intruder, ['role' => TeamRole::Employee->value]);
    $intruder->switchTeam($team);

    $file = UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf');

    Livewire::actingAs($intruder)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->assertDontSeeHtml('data-test="add-attachments-trigger"')
        ->set('newAttachments', [$file])
        ->call('addAttachments')
        ->assertStatus(403);

    expect(IdeaAttachment::count())->toBe(0);

    $attachment = IdeaAttachment::factory()->for($idea)->create();

    Livewire::actingAs($intruder)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->assertDontSeeHtml("data-test=\"attachment-remove-trigger-{$attachment->id}\"")
        ->call('removeAttachment', $attachment->id)
        ->assertStatus(403);

    expect(IdeaAttachment::find($attachment->id))->not->toBeNull();
});

test('a viewer cannot add or remove attachments even on an idea they submitted before losing access', function () {
    Storage::fake(config('idea_attachments.disk'));

    ['team' => $team, 'user' => $viewer] = teamWithMember(TeamRole::Viewer);
    $submitter = User::factory()->create();
    $team->members()->attach($submitter, ['role' => TeamRole::Employee->value]);
    $idea = makeIdea($team, ['submitted_by_user_id' => $submitter->id]);

    Livewire::actingAs($viewer)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->call('removeAttachment', 1)
        ->assertStatus(403);
});
