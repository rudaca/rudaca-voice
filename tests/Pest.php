<?php

use App\Enums\TeamRole;
use App\Models\Idea;
use App\Models\IdeaBoard;
use App\Models\IdeaBoardGroup;
use App\Models\IdeaCategory;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Create a team with a single member holding the given role, and switch that
 * user's current team to it. Returns the team and the member.
 *
 * @return array{team: Team, user: User}
 */
function teamWithMember(TeamRole $role = TeamRole::Employee): array
{
    $team = Team::factory()->create();
    $user = User::factory()->create();

    $team->members()->attach($user, ['role' => $role->value]);
    $user->switchTeam($team);

    return ['team' => $team, 'user' => $user];
}

/**
 * Create an active board group, board, and category scoped to the given team.
 *
 * @return array{group: IdeaBoardGroup, board: IdeaBoard, category: IdeaCategory}
 */
function boardStack(Team $team, ?User $creator = null): array
{
    $creatorId = $creator?->id
        ?? $team->memberships()->value('user_id')
        ?? User::factory()->create()->id;

    $group = IdeaBoardGroup::factory()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $creatorId,
        'is_active' => true,
    ]);

    $board = IdeaBoard::factory()->create([
        'team_id' => $team->id,
        'board_group_id' => $group->id,
        'created_by_user_id' => $creatorId,
        'is_active' => true,
    ]);

    $category = IdeaCategory::factory()->create([
        'team_id' => $team->id,
        'board_id' => $board->id,
        'is_active' => true,
    ]);

    return ['group' => $group, 'board' => $board, 'category' => $category];
}

/**
 * Create an idea scoped to the given team. Builds a board stack automatically
 * unless a board_id is supplied via $overrides.
 *
 * @param  array<string, mixed>  $overrides
 */
function makeIdea(Team $team, array $overrides = []): Idea
{
    if (! isset($overrides['board_id'])) {
        $stack = boardStack($team);
        $overrides['board_id'] = $stack['board']->id;
        $overrides['board_group_id'] = $stack['board']->board_group_id;
        $overrides['category_id'] = $stack['category']->id;
    }

    return Idea::factory()->create(array_merge([
        'team_id' => $team->id,
        'submitted_by_user_id' => $team->memberships()->value('user_id'),
    ], $overrides));
}
