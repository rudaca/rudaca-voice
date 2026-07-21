<?php

use App\Enums\TeamRole;
use App\Models\IdeaCategory;
use App\Models\User;
use Livewire\Livewire;

test('the organization settings page renders all four tabs and defaults to Boards', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    boardStack($team);

    $this->actingAs($admin)
        ->get(route('ideas.settings', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertSee('Organization settings')
        ->assertSee('Boards')
        ->assertSee('Categories')
        ->assertSee('Members')
        ->assertSee('Integrations');
});

test('the Members tab lists team members with their role', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    $manager = User::factory()->create();
    $team->members()->attach($manager, ['role' => TeamRole::Manager->value]);

    Livewire::actingAs($admin)
        ->test('pages::ideas.settings')
        ->set('tab', 'members')
        ->assertSee($admin->name)
        ->assertSee($manager->name)
        ->assertSee($manager->email)
        ->assertSee('Manager');
});

test('the Integrations tab renders a static GitHub placeholder', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);

    Livewire::actingAs($admin)
        ->test('pages::ideas.settings')
        ->set('tab', 'integrations')
        ->assertSee('GitHub')
        ->assertSee('Connected')
        ->assertSee('Auto-create issues on approval');
});

test('quick-adding a category on the Categories tab creates it against the chosen board', function () {
    ['team' => $team, 'user' => $admin] = teamWithMember(TeamRole::Admin);
    $stack = boardStack($team);

    Livewire::actingAs($admin)
        ->test('pages::ideas.settings')
        ->set('tab', 'categories')
        ->set('quickCategoryBoardId', (string) $stack['board']->id)
        ->set('quickCategoryName', 'Automation')
        ->call('quickAddCategory')
        ->assertHasNoErrors();

    $category = IdeaCategory::where('team_id', $team->id)->where('name', 'Automation')->first();

    expect($category)->not->toBeNull()
        ->and($category->board_id)->toBe($stack['board']->id)
        ->and($category->is_active)->toBeTrue();
});
