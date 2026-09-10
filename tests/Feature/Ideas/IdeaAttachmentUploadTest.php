<?php

use App\Enums\TeamRole;
use App\Models\Idea;
use App\Models\IdeaAttachment;
use App\Models\IdeaAttachmentHistory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

test('an allowed file type can be attached during idea creation', function () {
    Storage::fake(config('idea_attachments.disk'));

    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    ['group' => $group, 'board' => $board, 'category' => $category] = boardStack($team);

    $file = UploadedFile::fake()->create('spec.pdf', 100, 'application/pdf');

    $title = 'Idea with attachment';
    $slug = Str::slug($title);

    Livewire::actingAs($user)
        ->test('pages::ideas.create')
        ->set('board_group_id', (string) $group->id)
        ->set('board_id', (string) $board->id)
        ->set('category_id', (string) $category->id)
        ->set('title', $title)
        ->set('description', 'A description.')
        ->set('newAttachments', [$file])
        ->call('save')
        ->assertHasNoErrors();

    $idea = Idea::where('team_id', $team->id)->where('slug', $slug)->firstOrFail();

    $attachment = IdeaAttachment::where('idea_id', $idea->id)->firstOrFail();

    expect($attachment->original_filename)->toBe('spec.pdf')
        ->and($attachment->extension)->toBe('pdf')
        ->and($attachment->disk)->toBe(config('idea_attachments.disk'))
        ->and($attachment->path)->toContain("idea-attachments/{$team->id}/{$idea->id}/");

    Storage::disk($attachment->disk)->assertExists($attachment->path);

    $history = IdeaAttachmentHistory::where('idea_id', $idea->id)->firstOrFail();

    expect($history->action)->toBe(IdeaAttachmentHistory::ACTION_ADDED)
        ->and($history->idea_attachment_id)->toBe($attachment->id)
        ->and($history->actor_user_id)->toBe($user->id);
});

test('a disallowed file type is rejected and nothing is stored', function () {
    Storage::fake(config('idea_attachments.disk'));

    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    ['group' => $group, 'board' => $board, 'category' => $category] = boardStack($team);

    $file = UploadedFile::fake()->create('malware.exe', 10);

    Livewire::actingAs($user)
        ->test('pages::ideas.create')
        ->set('board_group_id', (string) $group->id)
        ->set('board_id', (string) $board->id)
        ->set('category_id', (string) $category->id)
        ->set('title', 'Bad attachment')
        ->set('description', 'A description.')
        ->set('newAttachments', [$file])
        ->call('save')
        ->assertHasErrors(['newAttachments.0']);

    expect(IdeaAttachment::count())->toBe(0);
});

test('a file over the configured size limit is rejected', function () {
    Storage::fake(config('idea_attachments.disk'));
    config(['idea_attachments.max_file_size_kb' => 100]);

    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    ['group' => $group, 'board' => $board, 'category' => $category] = boardStack($team);

    $file = UploadedFile::fake()->create('huge.pdf', 200, 'application/pdf');

    Livewire::actingAs($user)
        ->test('pages::ideas.create')
        ->set('board_group_id', (string) $group->id)
        ->set('board_id', (string) $board->id)
        ->set('category_id', (string) $category->id)
        ->set('title', 'Oversized attachment')
        ->set('description', 'A description.')
        ->set('newAttachments', [$file])
        ->call('save')
        ->assertHasErrors(['newAttachments.0']);

    expect(IdeaAttachment::count())->toBe(0);
});

test('exceeding the configured attachment count is rejected', function () {
    Storage::fake(config('idea_attachments.disk'));
    config(['idea_attachments.max_attachments_per_idea' => 2]);

    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    ['group' => $group, 'board' => $board, 'category' => $category] = boardStack($team);

    $files = [
        UploadedFile::fake()->create('one.pdf', 10, 'application/pdf'),
        UploadedFile::fake()->create('two.pdf', 10, 'application/pdf'),
        UploadedFile::fake()->create('three.pdf', 10, 'application/pdf'),
    ];

    Livewire::actingAs($user)
        ->test('pages::ideas.create')
        ->set('board_group_id', (string) $group->id)
        ->set('board_id', (string) $board->id)
        ->set('category_id', (string) $category->id)
        ->set('title', 'Too many attachments')
        ->set('description', 'A description.')
        ->set('newAttachments', $files)
        ->call('save')
        ->assertHasErrors(['newAttachments']);

    expect(IdeaAttachment::count())->toBe(0);
});
