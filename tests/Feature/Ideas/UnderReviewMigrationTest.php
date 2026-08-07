<?php

use App\Enums\TeamRole;

test('the under_review remap migration moves existing under_review ideas to new without touching other statuses', function () {
    ['team' => $team] = teamWithMember(TeamRole::Owner);

    $underReview = makeIdea($team, ['status' => 'under_review']);
    $planned = makeIdea($team, ['status' => 'planned']);
    $new = makeIdea($team, ['status' => 'new']);

    $migration = require base_path('database/migrations/2026_08_06_220645_remap_under_review_ideas_to_new_status.php');
    $migration->up();

    expect($underReview->refresh()->status)->toBe('new')
        ->and($planned->refresh()->status)->toBe('planned')
        ->and($new->refresh()->status)->toBe('new');
});
