<?php

use App\Enums\TeamRole;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

test('an employee does not see another member\'s private idea in the All Ideas list', function () {
    ['team' => $team, 'user' => $author] = teamWithMember(TeamRole::Employee);
    $privateIdea = makeIdea($team, ['title' => 'Private idea', 'status' => 'new', 'is_private' => true, 'submitted_by_user_id' => $author->id]);

    $employee = User::factory()->create();
    $team->members()->attach($employee, ['role' => TeamRole::Employee->value]);
    $employee->switchTeam($team);

    $component = Livewire::actingAs($employee)
        ->test('pages::ideas.index')
        ->assertOk()
        ->assertDontSee('Private idea');

    expect($component->instance()->ideas->pluck('id')->all())->not->toContain($privateIdea->id);
});

test('the original submitter can still see their own private idea in the list', function () {
    ['team' => $team, 'user' => $author] = teamWithMember(TeamRole::Employee);
    makeIdea($team, ['title' => 'My private idea', 'status' => 'new', 'is_private' => true, 'submitted_by_user_id' => $author->id]);

    Livewire::actingAs($author)
        ->test('pages::ideas.index')
        ->assertOk()
        ->assertSee('My private idea');
});

test('a manager can see another member\'s private idea in the list', function () {
    ['team' => $team, 'user' => $author] = teamWithMember(TeamRole::Employee);
    makeIdea($team, ['title' => 'Private idea for managers', 'status' => 'new', 'is_private' => true, 'submitted_by_user_id' => $author->id]);

    $manager = User::factory()->create();
    $team->members()->attach($manager, ['role' => TeamRole::Manager->value]);
    $manager->switchTeam($team);

    Livewire::actingAs($manager)
        ->test('pages::ideas.index')
        ->assertOk()
        ->assertSee('Private idea for managers');
});

test('an employee gets a 404 opening another member\'s private idea directly', function () {
    ['team' => $team, 'user' => $author] = teamWithMember(TeamRole::Employee);
    $privateIdea = makeIdea($team, ['is_private' => true, 'submitted_by_user_id' => $author->id]);

    $employee = User::factory()->create();
    $team->members()->attach($employee, ['role' => TeamRole::Employee->value]);
    $employee->switchTeam($team);

    $this->actingAs($employee)
        ->get(route('ideas.show', ['current_team' => $team->slug, 'idea' => $privateIdea->slug]))
        ->assertNotFound();
});

test('a manager can open another member\'s private idea directly', function () {
    ['team' => $team, 'user' => $author] = teamWithMember(TeamRole::Employee);
    $privateIdea = makeIdea($team, ['title' => 'Manager visible private idea', 'is_private' => true, 'submitted_by_user_id' => $author->id]);

    $manager = User::factory()->create();
    $team->members()->attach($manager, ['role' => TeamRole::Manager->value]);
    $manager->switchTeam($team);

    $this->actingAs($manager)
        ->get(route('ideas.show', ['current_team' => $team->slug, 'idea' => $privateIdea->slug]))
        ->assertOk()
        ->assertSee('Manager visible private idea');
});

test('an employee cannot vote on another member\'s private idea via the list route', function () {
    ['team' => $team, 'user' => $author] = teamWithMember(TeamRole::Employee);
    $privateIdea = makeIdea($team, ['is_private' => true, 'submitted_by_user_id' => $author->id]);

    $employee = User::factory()->create();
    $team->members()->attach($employee, ['role' => TeamRole::Employee->value]);
    $employee->switchTeam($team);

    expect(fn () => Livewire::actingAs($employee)
        ->test('pages::ideas.index')
        ->call('toggleVote', $privateIdea->id))
        ->toThrow(ModelNotFoundException::class);
});
