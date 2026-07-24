<?php

use App\Enums\TeamRole;
use App\Models\Idea;
use App\Models\IdeaBoard;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    /**
     * Display metadata for each idea status (label + Flux badge color).
     *
     * @var array<string, array{label: string, color: string, class?: string}>
     */
    public const STATUS_META = [
        'new' => ['label' => 'New', 'color' => 'zinc'],
        'under_review' => ['label' => 'Under Review', 'color' => 'amber'],
        'planned' => ['label' => 'Planned', 'color' => 'blue'],
        'in_progress' => ['label' => 'In Progress', 'color' => 'indigo'],
        'released' => ['label' => 'Implemented', 'color' => 'green'],
        'not_doing' => ['label' => 'Declined', 'color' => 'red'],
        'duplicate' => ['label' => 'Duplicate', 'color' => 'rose', 'class' => 'bg-red-100! text-red-700! dark:bg-red-900/40! dark:text-red-300!'],
    ];

    public string $query = '';

    /**
     * Get the display metadata for a status value.
     *
     * @return array{label: string, color: string, class?: string}
     */
    public function statusMeta(string $status): array
    {
        return self::STATUS_META[$status] ?? ['label' => str($status)->headline()->value(), 'color' => 'zinc'];
    }

    /**
     * Resolve the display name for an idea's author, respecting anonymity.
     */
    public function ideaAuthor(Idea $idea): string
    {
        return $idea->is_anonymous ? __('Anonymous') : ($idea->submittedBy?->name ?? __('Unknown'));
    }

    /**
     * Get a team member's activity stats within the current team.
     *
     * @return array{boards: int, ideas: int, comments: int}
     */
    public function personStats(User $person): array
    {
        $teamId = $this->team->id;

        return [
            'boards' => Idea::query()
                ->where('team_id', $teamId)
                ->where('submitted_by_user_id', $person->id)
                ->visibleTo($this->role, Auth::id())
                ->distinct('board_id')
                ->count('board_id'),
            'ideas' => $person->submittedIdeas()->where('team_id', $teamId)->visibleTo($this->role, Auth::id())->count(),
            'comments' => $person->ideaComments()->whereHas('idea', fn ($query) => $query->where('team_id', $teamId)->visibleTo($this->role, Auth::id()))->count(),
        ];
    }

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
    public function role(): ?TeamRole
    {
        return $this->team ? Auth::user()?->teamRole($this->team) : null;
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
            ->visibleTo($this->role, Auth::id())
            ->where('title', 'like', $this->likeTerm())
            ->with('submittedBy:id,name')
            ->withCount(['votes', 'comments'])
            ->orderByDesc('created_at')
            ->limit(5)
            ->get(['id', 'title', 'slug', 'status', 'submitted_by_user_id', 'is_anonymous', 'created_at']);
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
            ->withCount('ideas')
            ->with('boardGroup:id,name')
            ->orderBy('name')
            ->limit(5)
            ->get(['id', 'name', 'slug', 'board_group_id']);
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
            class="absolute top-full z-40 mt-2 w-full max-w-md overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-lg dark:border-zinc-700 dark:bg-zinc-900"
            data-test="global-search-results"
        >
            @if (! $this->hasResults)
                <div class="px-4 py-6 text-center text-sm text-slate-600 dark:text-slate-500">
                    {{ __('No results for ":query".', ['query' => $query]) }}
                </div>
            @else
                @if ($this->ideas->isNotEmpty())
                    <div class="border-b border-zinc-100 py-2 dark:border-zinc-800">
                        <div class="px-4 pb-1 text-xs font-semibold tracking-wide text-slate-500 uppercase dark:text-slate-600">{{ __('Ideas') }}</div>
                        @foreach ($this->ideas as $idea)
                            @php($meta = $this->statusMeta($idea->status))
                            @php($author = $this->ideaAuthor($idea))
                            <a
                                href="{{ route('ideas.show', ['idea' => $idea->slug]) }}"
                                wire:navigate
                                x-on:click="open = false"
                                class="flex items-start gap-2 px-4 py-2 text-sm text-slate-800 hover:bg-zinc-50 dark:text-slate-300 dark:hover:bg-zinc-800/60"
                                data-test="global-search-idea"
                            >
                                <flux:icon.light-bulb class="mt-0.5 size-4 shrink-0 text-slate-500" />
                                <div class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-semibold">{{ $idea->title }}</span>
                                    <div class="mt-1 flex flex-wrap items-center gap-x-1.5 gap-y-1 text-xs text-slate-600 dark:text-slate-500">
                                        <flux:avatar size="xs" :name="$author" color="auto" color:seed="{{ $idea->submitted_by_user_id ?? $author }}" />
                                        <span>{{ $author }}</span>
                                        <span aria-hidden="true">·</span>
                                        <flux:badge :color="$meta['color']" size="sm" class="{{ $meta['class'] ?? '' }}">{{ $meta['label'] }}</flux:badge>
                                        <span aria-hidden="true">·</span>
                                        <span>{{ $idea->created_at->format('M j, Y') }}</span>
                                        <span aria-hidden="true">·</span>
                                        <flux:icon.chevron-up class="size-3.5" />
                                        <span>{{ $idea->votes_count }}</span>
                                        <flux:icon.chat-bubble-left class="size-3.5" />
                                        <span>{{ $idea->comments_count }}</span>
                                    </div>
                                </div>
                            </a>
                        @endforeach
                    </div>
                @endif

                @if ($this->boards->isNotEmpty())
                    <div class="border-b border-zinc-100 py-2 dark:border-zinc-800">
                        <div class="px-4 pb-1 text-xs font-semibold tracking-wide text-slate-500 uppercase dark:text-slate-600">{{ __('Boards') }}</div>
                        @foreach ($this->boards as $board)
                            <a
                                href="{{ route('ideas.index', ['board' => [$board->id]]) }}"
                                wire:navigate
                                x-on:click="open = false"
                                class="flex items-start gap-2 px-4 py-2 text-sm text-slate-800 hover:bg-zinc-50 dark:text-slate-300 dark:hover:bg-zinc-800/60"
                                data-test="global-search-board"
                            >
                                <x-board-avatar :name="$board->name" size="size-5 text-[10px]" />
                                <div class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-semibold">{{ $board->name }}</span>
                                    <div class="mt-1 flex flex-wrap items-center gap-x-1.5 gap-y-1 text-xs text-slate-600 dark:text-slate-500">
                                        @if ($board->boardGroup)
                                            <span>{{ $board->boardGroup->name }}</span>
                                            <span aria-hidden="true">·</span>
                                        @endif
                                        <flux:icon.chalkboard class="size-3.5" />
                                        <span>{{ $board->ideas_count }} {{ Str::plural('idea', $board->ideas_count) }}</span>
                                    </div>
                                </div>
                            </a>
                        @endforeach
                    </div>
                @endif

                @if ($this->people->isNotEmpty())
                    <div class="py-2">
                        <div class="px-4 pb-1 text-xs font-semibold tracking-wide text-slate-500 uppercase dark:text-slate-600">{{ __('People') }}</div>
                        @foreach ($this->people as $person)
                            @php($stats = $this->personStats($person))
                            <div class="flex items-start gap-2 px-4 py-2 text-sm text-slate-800 dark:text-slate-300" data-test="global-search-person">
                                <flux:avatar :name="$person->name" size="xs" color="auto" color:seed="{{ $person->id }}" />
                                <div class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-semibold">{{ $person->name }}</span>
                                    <div class="mt-1 flex flex-wrap items-center gap-x-1.5 gap-y-1 text-xs text-slate-600 dark:text-slate-500">
                                        <flux:icon.chalkboard class="size-3.5" />
                                        <span>{{ $stats['boards'] }} {{ Str::plural('board', $stats['boards']) }}</span>
                                        <span aria-hidden="true">·</span>
                                        <flux:icon.light-bulb class="size-3.5" />
                                        <span>{{ $stats['ideas'] }} {{ Str::plural('idea', $stats['ideas']) }}</span>
                                        <span aria-hidden="true">·</span>
                                        <flux:icon.chat-bubble-left class="size-3.5" />
                                        <span>{{ $stats['comments'] }} {{ Str::plural('comment', $stats['comments']) }}</span>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            @endif
        </div>
    @endif
</div>
