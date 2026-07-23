<?php

use App\Enums\TeamRole;
use Livewire\Livewire;

test('opening the mark-duplicate modal dispatches the event Flux actually listens for', function () {
    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);
    $idea = makeIdea($team);

    Livewire::actingAs($manager)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->call('openMarkDuplicate')
        // Flux's <flux:modal> only reacts to "modal-show"/"modal-close" browser
        // events; the previous "open-modal"/"close-modal" names were a silent
        // no-op — no error, no modal.
        ->assertDispatched('modal-show', name: 'mark-duplicate');
});

test('marking an idea as a duplicate closes the modal', function () {
    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);
    $idea = makeIdea($team);
    $original = makeIdea($team);

    Livewire::actingAs($manager)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->set('duplicateOfId', $original->id)
        ->call('markDuplicate')
        ->assertHasNoErrors()
        ->assertDispatched('modal-close', name: 'mark-duplicate');

    expect($idea->fresh()->status)->toBe('duplicate')
        ->and($idea->fresh()->duplicate_of_idea_id)->toBe($original->id);
});
