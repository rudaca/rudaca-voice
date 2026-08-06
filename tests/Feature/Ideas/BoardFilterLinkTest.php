<?php

use App\Enums\TeamRole;

test('board filter links use clean board[]= query syntax instead of an indexed board[0]=', function () {
    ['team' => $team] = teamWithMember(TeamRole::Employee);
    ['board' => $board] = boardStack($team);

    expect($board->filterUrl())
        ->toBe(route('ideas.index').'?board[]='.$board->id)
        ->not->toContain('board[0]=')
        ->and($board->filterUrl('ideas.review'))
        ->toBe(route('ideas.review').'?board[]='.$board->id);
});
