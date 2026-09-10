<?php

use App\Enums\TeamRole;
use App\Models\Idea;
use App\Models\IdeaAttachment;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

function makeIdeaAttachment(Team $team, Idea $idea): IdeaAttachment
{
    $disk = config('idea_attachments.disk');
    $path = "idea-attachments/{$team->id}/{$idea->id}/".Str::uuid().'.pdf';
    Storage::disk($disk)->put($path, 'fake pdf contents');

    return IdeaAttachment::factory()->for($idea)->create([
        'disk' => $disk,
        'path' => $path,
        'original_filename' => 'evidence.pdf',
    ]);
}

test('a team member can download their own team\'s idea attachment', function () {
    Storage::fake(config('idea_attachments.disk'));

    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    $idea = makeIdea($team, ['submitted_by_user_id' => $user->id]);
    $attachment = makeIdeaAttachment($team, $idea);

    $response = $this->actingAs($user)
        ->get(route('ideas.attachments.download', [
            'current_team' => $team->slug,
            'idea' => $idea->slug,
            'attachment' => $attachment->id,
        ]))
        ->assertOk();

    expect($response->headers->get('content-disposition'))
        ->toContain('attachment')
        ->toContain('evidence.pdf');
});

test('substituting another team\'s attachment id into a valid idea url is rejected', function () {
    Storage::fake(config('idea_attachments.disk'));

    ['team' => $teamA] = teamWithMember(TeamRole::Owner);
    $ideaA = makeIdea($teamA);
    $foreignAttachment = makeIdeaAttachment($teamA, $ideaA);

    ['team' => $teamB, 'user' => $userB] = teamWithMember(TeamRole::Employee);
    $ideaB = makeIdea($teamB, ['submitted_by_user_id' => $userB->id]);

    $this->actingAs($userB)
        ->get(route('ideas.attachments.download', [
            'current_team' => $teamB->slug,
            'idea' => $ideaB->slug,
            'attachment' => $foreignAttachment->id,
        ]))
        ->assertNotFound();
});

test('substituting another team\'s idea slug into the download url is rejected', function () {
    Storage::fake(config('idea_attachments.disk'));

    ['team' => $teamA] = teamWithMember(TeamRole::Owner);
    $ideaA = makeIdea($teamA);
    $attachmentA = makeIdeaAttachment($teamA, $ideaA);

    ['team' => $teamB, 'user' => $userB] = teamWithMember(TeamRole::Employee);

    $this->actingAs($userB)
        ->get(route('ideas.attachments.download', [
            'current_team' => $teamB->slug,
            'idea' => $ideaA->slug,
            'attachment' => $attachmentA->id,
        ]))
        ->assertNotFound();
});

test('a user who cannot see a private idea cannot download its attachments', function () {
    Storage::fake(config('idea_attachments.disk'));

    ['team' => $team, 'user' => $submitter] = teamWithMember(TeamRole::Employee);
    $idea = makeIdea($team, ['submitted_by_user_id' => $submitter->id, 'is_private' => true]);
    $attachment = makeIdeaAttachment($team, $idea);

    $outsider = User::factory()->create();
    $team->members()->attach($outsider, ['role' => TeamRole::Employee->value]);
    $outsider->switchTeam($team);

    $this->actingAs($outsider)
        ->get(route('ideas.attachments.download', [
            'current_team' => $team->slug,
            'idea' => $idea->slug,
            'attachment' => $attachment->id,
        ]))
        ->assertNotFound();

    // Sanity check: the submitter themself can still reach it.
    $this->actingAs($submitter)
        ->get(route('ideas.attachments.download', [
            'current_team' => $team->slug,
            'idea' => $idea->slug,
            'attachment' => $attachment->id,
        ]))
        ->assertOk();
});
