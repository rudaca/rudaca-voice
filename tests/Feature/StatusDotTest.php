<?php

use App\Enums\TeamRole;
use App\Models\IdeaStatusHistory;
use Livewire\Livewire;

/**
 * The dot shades below are the *text* colors Flux renders for each badge color
 * in its default (soft) variant. If <x-status-dot> ever drifts from these, a
 * dot will visibly clash with the badge sitting next to it.
 */
dataset('badge colors', [
    'zinc' => ['zinc', 'bg-zinc-700 dark:bg-zinc-200'],
    'red' => ['red', 'bg-red-700 dark:bg-red-200'],
    'amber' => ['amber', 'bg-amber-700 dark:bg-amber-200'],
    'blue' => ['blue', 'bg-blue-800 dark:bg-blue-200'],
    'indigo' => ['indigo', 'bg-indigo-700 dark:bg-indigo-200'],
    'green' => ['green', 'bg-green-800 dark:bg-green-200'],
    'rose' => ['rose', 'bg-rose-700 dark:bg-rose-200'],
]);

test('a status dot uses the same shade as its Flux badge', function (string $color, string $expected) {
    $this->blade('<x-status-dot color="'.$color.'" />')->assertSee($expected, escape: false);
})->with('badge colors');

test('a status dot falls back to zinc for an unmapped color', function () {
    $this->blade('<x-status-dot color="not-a-real-color" />')
        ->assertSee('bg-zinc-700 dark:bg-zinc-200', escape: false);
});

test('a status dot keeps its default size and merges extra classes', function () {
    $rendered = $this->blade('<x-status-dot color="green" class="me-1" />');

    $rendered->assertSee('size-2', escape: false);
    $rendered->assertSee('me-1', escape: false);
});

test('a status dot accepts an explicit size', function () {
    $this->blade('<x-status-dot color="green" size="size-2.5" />')->assertSee('size-2.5', escape: false);
});

test('the workflow timeline dots match their badge colors', function () {
    $rendered = $this->blade('<x-workflow-timeline />');

    // New, Declined, Approved, Planned, In Progress, Completed.
    foreach ([
        'bg-zinc-700 dark:bg-zinc-200',
        'bg-red-700 dark:bg-red-200',
        'bg-amber-700 dark:bg-amber-200',
        'bg-blue-800 dark:bg-blue-200',
        'bg-indigo-700 dark:bg-indigo-200',
        'bg-green-800 dark:bg-green-200',
    ] as $dotClasses) {
        $rendered->assertSee($dotClasses, escape: false);
    }
});

test('a status dot is solid and unanimated by default', function () {
    $this->blade('<x-status-dot color="indigo" />')
        ->assertDontSee('status-dot-pulse', escape: false)
        ->assertDontSee('data-pulse', escape: false);
});

test('a pulsing status dot layers an animated halo in its own color', function () {
    $rendered = $this->blade('<x-status-dot color="indigo" size="size-2.5" class="mt-1.5" :pulse="true" />');

    // The halo is a second copy of the dot, so both spans carry the same shade.
    $rendered->assertSee('data-pulse', escape: false);
    $rendered->assertSee('status-dot-pulse absolute inset-0 rounded-full bg-indigo-700 dark:bg-indigo-200', escape: false);
    $rendered->assertSee('relative block size-full rounded-full bg-indigo-700 dark:bg-indigo-200', escape: false);

    // Sizing and alignment move to the wrapper so the halo can fill it.
    $rendered->assertSee('relative inline-flex shrink-0 size-2.5 mt-1.5', escape: false);
});

test('only the current status pulses in the activity timeline', function () {
    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);
    $idea = makeIdea($team, ['status' => 'in_progress']);

    // History renders newest-first, so the later record is the current status.
    IdeaStatusHistory::factory()->for($idea)->create([
        'changed_by_user_id' => $manager->id,
        'old_status' => 'new',
        'new_status' => 'new',
    ]);
    IdeaStatusHistory::factory()->for($idea)->create([
        'changed_by_user_id' => $manager->id,
        'old_status' => 'new',
        'new_status' => 'in_progress',
    ]);

    $html = Livewire::actingAs($manager)
        ->test('pages::ideas.show', ['idea' => $idea->slug])
        ->assertOk()
        ->html();

    expect(substr_count($html, 'status-dot-pulse'))->toBe(1);

    // ...and it's the In Progress dot that pulses, not the older New one.
    expect($html)->toContain('status-dot-pulse absolute inset-0 rounded-full bg-indigo-700 dark:bg-indigo-200')
        ->not->toContain('status-dot-pulse absolute inset-0 rounded-full bg-zinc-700 dark:bg-zinc-200');
});

test('a duplicate idea renders a red dot to match its overridden badge', function () {
    ['team' => $team, 'user' => $user] = teamWithMember(TeamRole::Employee);
    makeIdea($team, ['status' => 'duplicate']);

    // The badge declares color "rose" but a class override paints it red, so
    // the dot has to follow "dotColor" rather than the nominal color.
    Livewire::actingAs($user)
        ->test('pages::ideas.index')
        ->assertOk()
        ->assertSee('bg-red-700 dark:bg-red-200', escape: false)
        ->assertDontSee('bg-rose-700 dark:bg-rose-200', escape: false);
});
