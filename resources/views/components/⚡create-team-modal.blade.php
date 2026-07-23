<?php

use App\Actions\Teams\CreateTeam;
use App\Models\Team;
use App\Rules\TeamName;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

new class extends Component {
    public string $teamName = '';

    public bool $allowAnonymousIdeas = false;

    public function createTeam(CreateTeam $createTeam): void
    {
        Gate::authorize('create', Team::class);

        $validated = $this->validate([
            'teamName' => ['required', 'string', 'max:255', new TeamName],
        ]);

        $team = $createTeam->handle(Auth::user(), $validated['teamName'], allowAnonymousIdeas: $this->allowAnonymousIdeas);

        $this->dispatch('modal-close', name: 'create-team-switcher');

        $this->reset('teamName', 'allowAnonymousIdeas');

        Flux::toast(variant: 'success', text: __('Organization created.'));

        $this->redirectRoute('teams.edit', ['team' => $team->slug], navigate: true);
    }
}; ?>

<flux:modal name="create-team-switcher" :show="$errors->isNotEmpty()" focusable :dismissible="false" class="max-w-lg">
    <form wire:submit="createTeam" class="space-y-6">
        <div>
            <flux:heading size="lg">{{ __('Create a new organization') }}</flux:heading>
            <flux:subheading>{{ __('Give your organization a name to get started.') }}</flux:subheading>
        </div>

        <flux:input wire:model="teamName" :label="__('Organization name')" type="text" required autofocus data-test="switcher-create-team-name" />

        <flux:checkbox
            wire:model="allowAnonymousIdeas"
            :label="__('Allow anonymous posting of ideas')"
            data-test="switcher-create-team-allow-anonymous-ideas"
        />

        <div class="flex justify-end space-x-2 rtl:space-x-reverse">
            <flux:modal.close>
                <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
            </flux:modal.close>

            <flux:button variant="primary" type="submit" data-test="switcher-create-team-submit">
                {{ __('Create organization') }}
            </flux:button>
        </div>
    </form>
</flux:modal>
