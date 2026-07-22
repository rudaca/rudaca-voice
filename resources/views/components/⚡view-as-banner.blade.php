<?php

use App\Enums\ViewAsSessionEndReason;
use App\Models\ViewAsSession;
use Livewire\Component;

new class extends Component {
    public function activeSession(): ?ViewAsSession
    {
        return ViewAsSession::current()?->load(['superAdmin', 'targetUser']);
    }

    public function exit(): void
    {
        $this->activeSession()?->end(ViewAsSessionEndReason::Manual);

        $this->redirect(route('home'));
    }
}; ?>

<div>
    @if ($session = $this->activeSession())
        <div
            class="flex w-full items-center justify-center gap-3 bg-amber-500 px-4 py-2 text-sm font-medium text-white dark:bg-amber-600"
            data-test="view-as-banner"
        >
            <flux:icon name="eye" class="size-4 shrink-0" />

            <span>
                {{ __('Viewing as: :name — :role', ['name' => $session->targetUser->name, 'role' => $session->role_viewed_as->label()]) }}
            </span>

            <flux:button size="xs" variant="ghost" class="text-white hover:bg-white/10" wire:click="exit" data-test="view-as-exit">
                {{ __('Exit') }}
            </flux:button>
        </div>
    @endif
</div>
