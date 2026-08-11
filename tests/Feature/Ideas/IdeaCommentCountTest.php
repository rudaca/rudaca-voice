<?php

use App\Enums\AccessLevel;
use App\Enums\TeamRole;
use App\Models\IdeaBoardUserAccess;
use App\Models\IdeaComment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('the visibleTo scope excludes private notes unless the comment\'s board is authorized', function () {
    $idea = makeIdea(teamWithMember(TeamRole::Employee)['team']);
    IdeaComment::factory()->count(2)->create(['idea_id' => $idea->id]);
    IdeaComment::factory()->privateNote()->create(['idea_id' => $idea->id]);

    expect(IdeaComment::query()->visibleTo([])->count())->toBe(2)
        ->and(IdeaComment::query()->visibleTo([$idea->board_id])->count())->toBe(3);
});

test('an idea with only private notes counts as zero comments for an unauthorized user', function () {
    ['team' => $team, 'user' => $employee] = teamWithMember(TeamRole::Employee);
    $idea = makeIdea($team);
    IdeaComment::factory()->privateNote()->count(2)->create(['idea_id' => $idea->id]);

    $component = Livewire::actingAs($employee)->test('pages::ideas.index');
    $found = $component->instance()->ideas->firstWhere('id', $idea->id);

    expect($found->comments_count)->toBe(0);
    $component->assertSeeText('0 comments');
});

test('an idea with mixed public and private notes counts only public comments for an unauthorized user, and all comments for a manager', function () {
    ['team' => $team, 'user' => $employee] = teamWithMember(TeamRole::Employee);
    $idea = makeIdea($team);
    IdeaComment::factory()->count(2)->create(['idea_id' => $idea->id]);
    IdeaComment::factory()->privateNote()->create(['idea_id' => $idea->id]);

    $employeeView = Livewire::actingAs($employee)->test('pages::ideas.index');
    expect($employeeView->instance()->ideas->firstWhere('id', $idea->id)->comments_count)->toBe(2);

    $manager = User::factory()->create();
    $team->members()->attach($manager, ['role' => TeamRole::Manager->value]);
    $manager->switchTeam($team);
    $managerView = Livewire::actingAs($manager)->test('pages::ideas.index');
    expect($managerView->instance()->ideas->firstWhere('id', $idea->id)->comments_count)->toBe(3);
});

test('a board-scoped employee grant only raises the comment count on the granted board', function () {
    ['team' => $team, 'user' => $employee] = teamWithMember(TeamRole::Employee);
    $idea = makeIdea($team);
    $otherIdea = makeIdea($team);

    IdeaComment::factory()->create(['idea_id' => $idea->id]);
    IdeaComment::factory()->privateNote()->create(['idea_id' => $idea->id]);
    IdeaComment::factory()->create(['idea_id' => $otherIdea->id]);
    IdeaComment::factory()->privateNote()->create(['idea_id' => $otherIdea->id]);

    IdeaBoardUserAccess::factory()->create([
        'board_id' => $idea->board_id,
        'user_id' => $employee->id,
        'access_level' => AccessLevel::Manage,
    ]);

    $component = Livewire::actingAs($employee)->test('pages::ideas.index');

    expect($component->instance()->ideas->firstWhere('id', $idea->id)->comments_count)->toBe(2)
        ->and($component->instance()->ideas->firstWhere('id', $otherIdea->id)->comments_count)->toBe(1);
});

test('comment counts on the review queue reflect private notes for the manager viewing them', function () {
    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);
    $idea = makeIdea($team, ['status' => 'new']);
    IdeaComment::factory()->create(['idea_id' => $idea->id]);
    IdeaComment::factory()->privateNote()->create(['idea_id' => $idea->id]);

    $component = Livewire::actingAs($manager)->test('pages::ideas.review');

    expect($component->instance()->ideas->firstWhere('id', $idea->id)->comments_count)->toBe(2);
});

test('a person\'s comment count in global search excludes private notes for an unauthorized viewer', function () {
    ['team' => $team, 'user' => $viewer] = teamWithMember(TeamRole::Employee);
    $author = User::factory()->create(['name' => 'Nora Notes']);
    $team->members()->attach($author, ['role' => TeamRole::Manager->value]);

    $idea = makeIdea($team);
    IdeaComment::factory()->create(['idea_id' => $idea->id, 'user_id' => $author->id]);
    IdeaComment::factory()->privateNote()->create(['idea_id' => $idea->id, 'user_id' => $author->id]);

    $unauthorized = Livewire::actingAs($viewer)->test('global-search')->set('query', 'Nora');
    expect($unauthorized->instance()->personStats($author)['comments'])->toBe(1);

    $manager = User::factory()->create();
    $team->members()->attach($manager, ['role' => TeamRole::Manager->value]);
    $manager->switchTeam($team);
    $authorized = Livewire::actingAs($manager)->test('global-search')->set('query', 'Nora');
    expect($authorized->instance()->personStats($author)['comments'])->toBe(2);
});

test('loading comment counts for many ideas does not issue a query per idea', function () {
    ['team' => $team, 'user' => $employee] = teamWithMember(TeamRole::Employee);
    $stack = boardStack($team);
    $boardArgs = ['board_id' => $stack['board']->id, 'board_group_id' => $stack['board']->board_group_id, 'category_id' => $stack['category']->id];

    $idea = makeIdea($team, $boardArgs);
    IdeaComment::factory()->create(['idea_id' => $idea->id]);
    IdeaComment::factory()->privateNote()->create(['idea_id' => $idea->id]);

    DB::enableQueryLog();
    Livewire::actingAs($employee)->test('pages::ideas.index');
    $queryCountForOneIdea = count(DB::getQueryLog());
    DB::disableQueryLog();
    DB::flushQueryLog();

    foreach (range(1, 9) as $i) {
        $idea = makeIdea($team, $boardArgs);
        IdeaComment::factory()->create(['idea_id' => $idea->id]);
        IdeaComment::factory()->privateNote()->create(['idea_id' => $idea->id]);
    }

    DB::enableQueryLog();
    Livewire::actingAs($employee)->test('pages::ideas.index');
    $queryCountForTenIdeas = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queryCountForTenIdeas)->toBe($queryCountForOneIdea);
});
