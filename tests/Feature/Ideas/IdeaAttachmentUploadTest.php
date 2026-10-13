<?php

use App\Enums\TeamRole;
use App\Models\Idea;
use App\Models\IdeaAttachment;
use App\Models\IdeaAttachmentHistory;
use App\Rules\IdeaAttachmentFile;
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
        ->assertHasErrors(['newAttachments'])
        ->call('save');

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
        ->assertHasErrors(['newAttachments'])
        ->call('save');

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

test('an office file sniffed as a generic zip container is accepted by extension', function () {
    $file = UploadedFile::fake()->create('report.docx', 20, 'application/zip');

    $rule = new IdeaAttachmentFile;
    $failed = false;
    $rule->validate('newAttachments.0', $file, function () use (&$failed) {
        $failed = true;
    });

    expect($failed)->toBeFalse();
});

test('a non-office file is not accepted just because of its mime type', function () {
    $file = UploadedFile::fake()->create('page.html', 20, 'application/zip');

    $failed = false;
    (new IdeaAttachmentFile)->validate('newAttachments.0', $file, function () use (&$failed) {
        $failed = true;
    });

    expect($failed)->toBeTrue();
});

test('an invalid file staged first is dropped and does not block a valid file', function () {
    Storage::fake(config('idea_attachments.disk'));

    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Manager);
    $idea = makeIdea($team, ['submitted_by_user_id' => $user->id]);

    $component = Livewire::actingAs($user)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->set('newAttachments', [UploadedFile::fake()->create('page.html', 10, 'text/html')])
        ->assertHasErrors(['newAttachments'])
        ->set('newAttachments', [UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf')])
        ->assertHasNoErrors()
        ->call('addAttachments')
        ->assertHasNoErrors();

    expect(IdeaAttachment::where('idea_id', $idea->id)->count())->toBe(1);
});

test('the upload button is disabled until a valid file is staged', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Manager);
    $idea = makeIdea($team, ['submitted_by_user_id' => $user->id]);

    $buttonIsDisabled = fn ($component): bool => (bool) preg_match(
        '/<button[^>]*\sdisabled[\s=>][^>]*data-test="save-attachments"|<button[^>]*data-test="save-attachments"[^>]*\sdisabled[\s=>]/s',
        $component->html(),
    );

    $component = Livewire::actingAs($user)->test('pages::ideas.show', ['idea' => $idea->slug]);

    expect($buttonIsDisabled($component))->toBeTrue();

    $component->set('newAttachments', [UploadedFile::fake()->create('page.html', 10, 'text/html')]);
    expect($buttonIsDisabled($component))->toBeTrue();

    $component->set('newAttachments', [UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf')]);
    expect($buttonIsDisabled($component))->toBeFalse();
});
