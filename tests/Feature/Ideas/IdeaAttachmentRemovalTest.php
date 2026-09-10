<?php

use App\Enums\TeamRole;
use App\Models\IdeaAttachment;
use App\Models\IdeaAttachmentHistory;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

test('removing an attachment deletes the stored file, the row, and records a removal history entry', function () {
    Storage::fake(config('idea_attachments.disk'));

    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);
    $idea = makeIdea($team, ['submitted_by_user_id' => $manager->id]);

    $disk = config('idea_attachments.disk');
    $path = "idea-attachments/{$team->id}/{$idea->id}/".Str::uuid().'.pdf';
    Storage::disk($disk)->put($path, 'fake pdf contents');

    $attachment = IdeaAttachment::factory()->for($idea)->create([
        'disk' => $disk,
        'path' => $path,
        'original_filename' => 'evidence.pdf',
    ]);

    Livewire::actingAs($manager)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->call('removeAttachment', $attachment->id)
        ->assertHasNoErrors();

    Storage::disk($disk)->assertMissing($path);
    expect(IdeaAttachment::find($attachment->id))->toBeNull();

    // The removed attachment's row is gone, so its foreign key on the history
    // entry has already been nulled out by the ON DELETE SET NULL constraint —
    // find it by idea + action instead, and rely on the denormalized filename.
    $history = IdeaAttachmentHistory::where('idea_id', $idea->id)
        ->where('action', IdeaAttachmentHistory::ACTION_REMOVED)
        ->first();

    expect($history)->not->toBeNull()
        ->and($history->idea_attachment_id)->toBeNull()
        ->and($history->actor_user_id)->toBe($manager->id)
        ->and($history->original_filename)->toBe('evidence.pdf');
});
