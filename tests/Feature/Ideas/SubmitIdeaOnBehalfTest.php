<?php

use App\Enums\TeamRole;
use App\Models\Idea;
use App\Models\IdeaStatusHistory;
use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Livewire;

test('an admin can submit an idea on behalf of another active team member', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    $beneficiary = User::factory()->create();
    $team->members()->attach($beneficiary, ['role' => TeamRole::Employee->value]);

    ['group' => $group, 'board' => $board, 'category' => $category] = boardStack($team);

    // Creating $beneficiary above switched the global URL::defaults() team
    // context to their own personal team; restore it to $admin's before
    // asserting the redirect URL.
    $admin->switchTeam($team);

    $title = 'Add a suggestion box to the break room';
    $slug = Str::slug($title);

    Livewire::actingAs($admin)
        ->test('pages::ideas.create')
        ->set('board_group_id', (string) $group->id)
        ->set('board_id', (string) $board->id)
        ->set('category_id', (string) $category->id)
        ->set('title', $title)
        ->set('description', 'Jane suggested this in the hallway.')
        ->set('on_behalf_of_user_id', $beneficiary->id)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('ideas.show', ['current_team' => $team->slug, 'idea' => $slug]));

    $idea = Idea::where('team_id', $team->id)->where('slug', $slug)->first();

    expect($idea)->not->toBeNull()
        ->and($idea->submitted_by_user_id)->toBe($beneficiary->id)
        ->and($idea->entered_by_user_id)->toBe($admin->id);

    $history = IdeaStatusHistory::where('idea_id', $idea->id)->first();

    expect($history->changed_by_user_id)->toBe($admin->id)
        ->and($history->note)->toBe("Entered by {$admin->name} on behalf of {$beneficiary->name}.");
});

test('the on-behalf-of field is hidden from users without the permission', function () {
    ['team' => $team, 'user' => $employee] = teamWithMember(TeamRole::Employee);

    Livewire::actingAs($employee)
        ->test('pages::ideas.create')
        ->assertDontSeeHtml('data-test="idea-on-behalf-of"');
});

test('a manager cannot submit an idea on behalf of another user', function () {
    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);

    Livewire::actingAs($manager)
        ->test('pages::ideas.create')
        ->assertDontSeeHtml('data-test="idea-on-behalf-of"');
});

test('a crafted on-behalf-of submission is rejected for a user without the permission', function () {
    ['team' => $team, 'user' => $employee] = teamWithMember(TeamRole::Employee);
    $target = User::factory()->create();
    $team->members()->attach($target, ['role' => TeamRole::Employee->value]);

    ['group' => $group, 'board' => $board, 'category' => $category] = boardStack($team);

    Livewire::actingAs($employee)
        ->test('pages::ideas.create')
        ->set('board_group_id', (string) $group->id)
        ->set('board_id', (string) $board->id)
        ->set('category_id', (string) $category->id)
        ->set('title', 'Sneaky on-behalf-of attempt')
        ->set('description', 'Trying to submit as someone else without permission.')
        ->set('on_behalf_of_user_id', $target->id)
        ->call('save')
        ->assertHasErrors(['on_behalf_of_user_id']);

    expect(Idea::where('team_id', $team->id)->count())->toBe(0);
});

test('submitting on behalf of a user in another organization is rejected', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    ['user' => $stranger] = teamWithMember(TeamRole::Employee);

    ['group' => $group, 'board' => $board, 'category' => $category] = boardStack($team);

    Livewire::actingAs($admin)
        ->test('pages::ideas.create')
        ->set('board_group_id', (string) $group->id)
        ->set('board_id', (string) $board->id)
        ->set('category_id', (string) $category->id)
        ->set('title', 'Cross-org on-behalf-of attempt')
        ->set('description', 'The target user belongs to a different organization.')
        ->set('on_behalf_of_user_id', $stranger->id)
        ->call('save')
        ->assertHasErrors(['on_behalf_of_user_id']);

    expect(Idea::where('team_id', $team->id)->count())->toBe(0);
});

test('submitting on behalf of an inactive user is rejected', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    $inactive = User::factory()->inactive()->create();
    $team->members()->attach($inactive, ['role' => TeamRole::Employee->value]);

    ['group' => $group, 'board' => $board, 'category' => $category] = boardStack($team);

    Livewire::actingAs($admin)
        ->test('pages::ideas.create')
        ->set('board_group_id', (string) $group->id)
        ->set('board_id', (string) $board->id)
        ->set('category_id', (string) $category->id)
        ->set('title', 'Inactive on-behalf-of attempt')
        ->set('description', 'The target user has been deactivated.')
        ->set('on_behalf_of_user_id', $inactive->id)
        ->call('save')
        ->assertHasErrors(['on_behalf_of_user_id']);

    expect(Idea::where('team_id', $team->id)->count())->toBe(0);
});

test('leaving on-behalf-of as Myself submits under the authenticated user for both fields', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    ['group' => $group, 'board' => $board, 'category' => $category] = boardStack($team);

    Livewire::actingAs($admin)
        ->test('pages::ideas.create')
        ->set('board_group_id', (string) $group->id)
        ->set('board_id', (string) $board->id)
        ->set('category_id', (string) $category->id)
        ->set('title', 'A regular self-submitted idea')
        ->set('description', 'No on-behalf-of selection made.')
        ->call('save')
        ->assertHasNoErrors();

    $idea = Idea::where('team_id', $team->id)->first();

    expect($idea->submitted_by_user_id)->toBe($admin->id)
        ->and($idea->entered_by_user_id)->toBe($admin->id);

    $history = IdeaStatusHistory::where('idea_id', $idea->id)->first();

    expect($history->note)->toBeNull();
});

test('a normal idea submission by an employee sets both submitted-by and entered-by to the employee', function () {
    ['team' => $team, 'user' => $employee] = teamWithMember(TeamRole::Employee);
    ['group' => $group, 'board' => $board, 'category' => $category] = boardStack($team);

    Livewire::actingAs($employee)
        ->test('pages::ideas.create')
        ->set('board_group_id', (string) $group->id)
        ->set('board_id', (string) $board->id)
        ->set('category_id', (string) $category->id)
        ->set('title', 'Employee self-submission')
        ->set('description', 'No permission, no on-behalf-of field.')
        ->call('save')
        ->assertHasNoErrors();

    $idea = Idea::where('team_id', $team->id)->first();

    expect($idea->submitted_by_user_id)->toBe($employee->id)
        ->and($idea->entered_by_user_id)->toBe($employee->id);
});

test('the on-behalf-of search only returns active members of the current team', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);

    $match = User::factory()->create(['name' => 'Jane Smith']);
    $team->members()->attach($match, ['role' => TeamRole::Employee->value]);

    $inactive = User::factory()->inactive()->create(['name' => 'Jane Inactive']);
    $team->members()->attach($inactive, ['role' => TeamRole::Employee->value]);

    ['team' => $otherTeam] = teamWithMember(TeamRole::Employee);
    $stranger = User::factory()->create(['name' => 'Jane Stranger']);
    $otherTeam->members()->attach($stranger, ['role' => TeamRole::Employee->value]);

    Livewire::actingAs($admin)
        ->test('pages::ideas.create')
        ->set('on_behalf_of_search', 'Jane')
        ->assertSee('Jane Smith')
        ->assertDontSee('Jane Inactive')
        ->assertDontSee('Jane Stranger');
});
