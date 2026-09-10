<?php

use App\Enums\TeamRole;
use App\Models\Idea;
use App\Models\IdeaOfficialResponse;
use Illuminate\Support\Str;
use Livewire\Livewire;

test('submitting an idea with Trix-produced HTML persists it sanitized with description_format html', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    ['group' => $group, 'board' => $board, 'category' => $category] = boardStack($team);

    $title = 'Formatted idea';
    $slug = Str::slug($title);

    Livewire::actingAs($user)
        ->test('pages::ideas.create')
        ->set('board_group_id', (string) $group->id)
        ->set('board_id', (string) $board->id)
        ->set('category_id', (string) $category->id)
        ->set('title', $title)
        ->set('description', '<h1>Heading</h1><p>Some <strong>bold</strong> and <em>italic</em> text.</p><ul><li>One</li><li>Two</li></ul><p><a href="https://example.com">a link</a></p>')
        ->call('save')
        ->assertHasNoErrors();

    $idea = Idea::where('team_id', $team->id)->where('slug', $slug)->firstOrFail();

    expect($idea->description_format)->toBe('html')
        ->and($idea->description)->toContain('<h1>Heading</h1>')
        ->and($idea->description)->toContain('<strong>bold</strong>')
        ->and($idea->description)->toContain('<em>italic</em>')
        ->and($idea->description)->toContain('<li>One</li>')
        ->and($idea->description)->toContain('href="https://example.com"');
});

test('a tampered description payload has scripts and unsafe attributes stripped before storage', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    ['group' => $group, 'board' => $board, 'category' => $category] = boardStack($team);

    Livewire::actingAs($user)
        ->test('pages::ideas.create')
        ->set('board_group_id', (string) $group->id)
        ->set('board_id', (string) $board->id)
        ->set('category_id', (string) $category->id)
        ->set('title', 'Tampered payload')
        ->set('description', '<p onclick="alert(1)">Hello</p><script>alert(1)</script><img src=x onerror="alert(1)"><a href="javascript:alert(1)">click</a>')
        ->call('save')
        ->assertHasNoErrors();

    $idea = Idea::where('team_id', $team->id)->where('title', 'Tampered payload')->firstOrFail();

    expect($idea->description)->not->toContain('<script')
        ->and($idea->description)->not->toContain('onclick')
        ->and($idea->description)->not->toContain('onerror')
        ->and($idea->description)->not->toContain('javascript:')
        ->and($idea->description)->not->toContain('<img')
        ->and($idea->description)->toContain('Hello');
});

test('a description whose visible text exceeds the length limit is rejected', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    ['group' => $group, 'board' => $board, 'category' => $category] = boardStack($team);

    Livewire::actingAs($user)
        ->test('pages::ideas.create')
        ->set('board_group_id', (string) $group->id)
        ->set('board_id', (string) $board->id)
        ->set('category_id', (string) $category->id)
        ->set('title', 'Too long')
        ->set('description', '<p>'.str_repeat('a', 20001).'</p>')
        ->call('save')
        ->assertHasErrors(['description']);

    expect(Idea::where('team_id', $team->id)->where('title', 'Too long')->exists())->toBeFalse();
});

test('an official response is sanitized and stored as html', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    $idea = makeIdea($team);

    Livewire::actingAs($admin)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->set('officialResponseBody', '<p>We <strong>reviewed</strong> this.</p><script>alert(1)</script>')
        ->call('saveOfficialResponse')
        ->assertHasNoErrors();

    $response = IdeaOfficialResponse::where('idea_id', $idea->id)->firstOrFail();

    expect($response->body_format)->toBe('html')
        ->and($response->body)->toContain('<strong>reviewed</strong>')
        ->and($response->body)->not->toContain('<script');
});

test('an official response whose visible text exceeds the length limit is rejected', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    $idea = makeIdea($team);

    Livewire::actingAs($admin)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->set('officialResponseBody', '<p>'.str_repeat('a', 5001).'</p>')
        ->call('saveOfficialResponse')
        ->assertHasErrors(['officialResponseBody']);

    expect(IdeaOfficialResponse::where('idea_id', $idea->id)->exists())->toBeFalse();
});
