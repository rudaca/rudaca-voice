<?php

use App\Models\Idea;
use App\Models\IdeaBoard;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public string $query = '';

    /**
     * Escape LIKE wildcard characters so raw user input can't widen the match.
     */
    protected function likeTerm(): string
    {
        return '%'.addcslashes(trim($this->query), '%_').'%';
    }

    #[Computed]
    public function team(): ?Team
    {
        return Auth::user()?->currentTeam;
    }

    #[Computed]
    public function hasQuery(): bool
    {
        return mb_strlen(trim($this->query)) >= 2;
    }

    /**
     * @return Collection<int, Idea>
     */
    #[Computed]
    public function ideas(): Collection
    {
        if (! $this->hasQuery || ! $this->team) {
            return collect();
        }

        return Idea::query()
            ->where('team_id', $this->team->id)
            ->where('title', 'like', $this->likeTerm())
            ->orderByDesc('created_at')
            ->limit(5)
            ->get(['id', 'title', 'slug']);
    }

    /**
     * @return Collection<int, IdeaBoard>
     */
    #[Computed]
    public function boards(): Collection
    {
        if (! $this->hasQuery || ! $this->team) {
            return collect();
        }

        return IdeaBoard::query()
            ->where('team_id', $this->team->id)
            ->where('is_active', true)
            ->where('name', 'like', $this->likeTerm())
            ->orderBy('name')
            ->limit(5)
            ->get(['id', 'name', 'slug']);
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function people(): Collection
    {
        if (! $this->hasQuery || ! $this->team) {
            return collect();
        }

        return $this->team->members()
            ->where('name', 'like', $this->likeTerm())
            ->orderBy('name')
            ->limit(5)
            ->get(['users.id', 'users.name', 'users.email']);
    }

    #[Computed]
    public function hasResults(): bool
    {
        return $this->ideas->isNotEmpty() || $this->boards->isNotEmpty() || $this->people->isNotEmpty();
    }
}; ?>

<div x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false" class="relative w-full">
    <flux:input
        icon="magnifying-glass"
        wire:model.live.debounce.300ms="query"
        x-on:focus="open = true"
        x-on:input="open = true"
        placeholder="{{ __('Search ideas, boards, people') }}"
        autocomplete="off"
        data-test="global-search-input"
    />

    @if ($this->hasQuery)
        <div
            x-show="open"
            x-cloak
            x-transition:enter="transition ease-out duration-150"
            x-transition:enter-start="opacity-0 -translate-y-1"
            x-transition:enter-end="opacity-100 translate-y-0"
            x-transition:leave="transition ease-in duration-100"
            x-transition:leave-start="opacity-100 translate-y-0"
            x-transition:leave-end="opacity-0 -translate-y-1"
            class="absolute top-full z-20 mt-2 w-full max-w-md overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-lg dark:border-zinc-700 dark:bg-zinc-900"
            data-test="global-search-results"
        >
            @if (! $this->hasResults)
                <div class="px-4 py-6 text-center text-sm text-zinc-500 dark:text-zinc-400">
                    {{ __('No results for ":query".', ['query' => $query]) }}
                </div>
            @else
                @if ($this->ideas->isNotEmpty())
                    <div class="border-b border-zinc-100 py-2 dark:border-zinc-800">
                        <div class="px-4 pb-1 text-xs font-semibold tracking-wide text-zinc-400 uppercase dark:text-zinc-500">{{ __('Ideas') }}</div>
                        @foreach ($this->ideas as $idea)
                            <a
                                href="{{ route('ideas.show', ['idea' => $idea->slug]) }}"
                                wire:navigate
                                x-on:click="open = false"
                                class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-200 dark:hover:bg-zinc-800/60"
                                data-test="global-search-idea"
                            >
                                <flux:icon.light-bulb class="size-4 shrink-0 text-zinc-400" />
                                <span class="truncate">{{ $idea->title }}</span>
                            </a>
                        @endforeach
                    </div>
                @endif

                @if ($this->boards->isNotEmpty())
                    <div class="border-b border-zinc-100 py-2 dark:border-zinc-800">
                        <div class="px-4 pb-1 text-xs font-semibold tracking-wide text-zinc-400 uppercase dark:text-zinc-500">{{ __('Boards') }}</div>
                        @foreach ($this->boards as $board)
                            <a
                                href="{{ route('ideas.index', ['board' => $board->id]) }}"
                                wire:navigate
                                x-on:click="open = false"
                                class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-200 dark:hover:bg-zinc-800/60"
                                data-test="global-search-board"
                            >
                                <x-board-avatar :name="$board->name" size="size-5 text-[10px]" />
                                <span class="truncate">{{ $board->name }}</span>
                            </a>
                        @endforeach
                    </div>
                @endif

                @if ($this->people->isNotEmpty())
                    <div class="py-2">
                        <div class="px-4 pb-1 text-xs font-semibold tracking-wide text-zinc-400 uppercase dark:text-zinc-500">{{ __('People') }}</div>
                        @foreach ($this->people as $person)
                            <div class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 dark:text-zinc-200" data-test="global-search-person">
                                <flux:avatar :name="$person->name" size="xs" color="auto" color:seed="{{ $person->id }}" />
                                <span class="truncate">{{ $person->name }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            @endif
        </div>
    @endif
</div>
