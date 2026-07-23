<?php

use App\Enums\TeamRole;
use App\Models\Idea;
use App\Models\IdeaVote;
use App\Models\Team;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

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
        'duplicate' => ['label' => 'Duplicate', 'color' => 'rose', 'class' => 'bg-rose-700! text-white!'],
    ];

    #[Url(as: 'sort')]
    public string $sort = 'newest';

    #[Url(as: 'status')]
    public string $status = '';

    #[Url(as: 'group')]
    public string $group = '';

    #[Url(as: 'board')]
    public string $board = '';

    #[Url(as: 'category')]
    public string $category = '';

    #[Url(as: 'hide_duplicates')]
    public bool $hideDuplicates = true;

    /**
     * Reset pagination whenever a filter changes.
     */
    public function updated(string $property): void
    {
        // Selecting the Duplicate status while "hide duplicates" is on would
        // otherwise return zero results, so switch it off automatically.
        if ($property === 'status' && $this->status === 'duplicate') {
            $this->hideDuplicates = false;
        }

        if (in_array($property, ['status', 'group', 'board', 'category', 'hideDuplicates'], true)) {
            $this->resetPage();
        }
    }

    /**
     * Change the active sort order.
     */
    public function sortBy(string $sort): void
    {
        $this->sort = in_array($sort, ['newest', 'top', 'trending'], true) ? $sort : 'newest';
        $this->resetPage();
    }

    /**
     * Toggle the current user's vote on an idea belonging to their current team.
     */
    public function toggleVote(int $ideaId): void
    {
        abort_unless($this->canParticipate, 403);

        $idea = Idea::where('team_id', Auth::user()->current_team_id)
            ->visibleTo(Auth::user()->teamRole($this->team), Auth::id())
            ->findOrFail($ideaId);

        $existingVote = IdeaVote::where('idea_id', $idea->id)
            ->where('user_id', Auth::id())
            ->first();

        if ($existingVote) {
            $existingVote->delete();
        } else {
            IdeaVote::firstOrCreate([
                'idea_id' => $idea->id,
                'user_id' => Auth::id(),
            ]);
        }
    }

    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    /**
     * Whether the current user may vote and comment (employee and above; viewers are read-only).
     */
    #[Computed]
    public function canParticipate(): bool
    {
        return Auth::user()->teamRole($this->team)?->isAtLeast(TeamRole::Employee) ?? false;
    }

    /**
     * Active board groups for the current team, used for the filter dropdown.
     *
     * @return Collection<int, \App\Models\IdeaBoardGroup>
     */
    #[Computed]
    public function boardGroups(): Collection
    {
        return $this->team->boardGroups()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * Active boards for the current team, used for the filter dropdown.
     *
     * @return Collection<int, \App\Models\IdeaBoard>
     */
    #[Computed]
    public function boards(): Collection
    {
        return $this->team->boards()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * Active categories for the current team, used for the filter dropdown.
     *
     * @return Collection<int, \App\Models\IdeaCategory>
     */
    #[Computed]
    public function categories(): Collection
    {
        return $this->team->categories()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * The name of the board or board group currently filtering the list, if any.
     * Used to swap the "All Ideas" heading/title for something less misleading
     * when the view is scoped to a single board or group.
     */
    #[Computed]
    public function activeFilterLabel(): ?string
    {
        if ($this->board !== '') {
            return $this->boards->firstWhere('id', (int) $this->board)?->name;
        }

        if ($this->group !== '') {
            return $this->boardGroups->firstWhere('id', (int) $this->group)?->name;
        }

        return null;
    }

    public function render()
    {
        return $this->view()->title(
            $this->activeFilterLabel ? __(':name Ideas', ['name' => $this->activeFilterLabel]) : __('Ideas')
        );
    }

    /**
     * The filtered, sorted, paginated ideas for the current team.
     *
     * @return LengthAwarePaginator<int, Idea>
     */
    #[Computed]
    public function ideas(): LengthAwarePaginator
    {
        $query = Idea::query()
            ->where('team_id', $this->team->id)
            ->visibleTo(Auth::user()->teamRole($this->team), Auth::id())
            ->with(['boardGroup:id,name', 'board:id,name', 'category:id,name', 'submittedBy:id,name'])
            ->withCount(['votes', 'comments'])
            ->withExists(['votes as voted' => fn ($query) => $query->where('user_id', Auth::id())])
            ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
            ->when($this->group !== '', fn ($query) => $query->where('board_group_id', $this->group))
            ->when($this->board !== '', fn ($query) => $query->where('board_id', $this->board))
            ->when($this->category !== '', fn ($query) => $query->where('category_id', $this->category))
            ->when($this->hideDuplicates, fn ($query) => $query->where('status', '!=', 'duplicate'));

        match ($this->sort) {
            'top' => $query->orderByDesc('votes_count')->orderByDesc('created_at')->orderByDesc('id'),
            'trending' => $query->orderByRaw('(votes_count + comments_count * 3) desc')->orderByDesc('id'),
            default => $query->orderByDesc('created_at')->orderByDesc('id'),
        };

        return $query->paginate(10);
    }

    /**
     * Get the display metadata for a status value.
     *
     * @return array{label: string, color: string, class?: string}
     */
    public function statusMeta(string $status): array
    {
        return self::STATUS_META[$status] ?? ['label' => str($status)->headline()->value(), 'color' => 'zinc'];
    }
}; ?>

@push('breadcrumbs')
    <x-breadcrumbs :items="[
        ['label' => __('All Ideas'), 'href' => $this->activeFilterLabel ? route('ideas.index') : null],
        ...($this->activeFilterLabel ? [['label' => $this->activeFilterLabel, 'href' => null]] : []),
    ]" />
@endpush

<section class="mx-auto w-full container px-6 pb-7 lg:px-8">
    <div>
        {{-- Header --}}
        <div class="flex items-start justify-between gap-4">
            <div class="flex flex-col gap-1">
                <flux:heading size="xl" class="flex items-center gap-2">
                    @if ($this->activeFilterLabel)
                        <flux:tooltip :content="$this->board !== '' ? __('Board') : __('Board group')">
                            <flux:icon.chalkboard class="size-6 shrink-0 text-slate-500 dark:text-slate-600" />
                        </flux:tooltip>
                    @endif
                    {{ $this->activeFilterLabel ?? __('All Ideas') }}
                </flux:heading>
                <flux:text class="text-slate-600 dark:text-slate-500">
                    @if ($this->activeFilterLabel)
                        {{ trans_choice(':count idea|:count ideas', $this->ideas->total(), ['count' => $this->ideas->total()]) }}
                    @else
                        {{ __(':ideas ideas across :boards boards', [
                            'ideas' => $this->ideas->total(),
                            'boards' => $this->boards->count(),
                        ]) }}
                    @endif
                </flux:text>
            </div>

            @if ($this->canParticipate)
                <flux:button :href="route('ideas.create')" wire:navigate variant="primary" icon="plus" data-test="new-idea-button">
                    {{ __('New idea') }}
                </flux:button>
            @endif
        </div>

        {{-- Controls: sort (left) + filters (right). Sticks below the app header while the list scrolls. --}}
        <x-sticky-toolbar class="mt-6 flex flex-col gap-3 py-3 lg:flex-row lg:items-center lg:justify-between">
            <div
                class="relative inline-flex rounded-lg bg-zinc-100 p-0.5 dark:bg-zinc-800"
                role="group"
                aria-label="{{ __('Sort ideas') }}"
                data-sort="{{ $sort }}"
                x-data="{
                    sort: null,
                    indicator: { left: 0, width: 0 },
                    updateIndicator() {
                        let el = this.$refs['sort-' + this.sort];
                        if (el) {
                            this.indicator = { left: el.offsetLeft, width: el.offsetWidth };
                        }
                    },
                }"
                x-init="sort = $el.dataset.sort; updateIndicator()"
                x-effect="sort; updateIndicator()"
            >
                <div
                    class="absolute inset-y-0.5 rounded-md bg-white shadow-sm transition-all duration-200 ease-out dark:bg-zinc-700"
                    :style="`transform: translateX(${indicator.left}px); width: ${indicator.width}px`"
                ></div>

                @foreach (['top' => __('Top voted'), 'newest' => __('Newest'), 'trending' => __('Trending')] as $value => $label)
                    <button
                        type="button"
                        x-ref="sort-{{ $value }}"
                        x-on:click="sort = '{{ $value }}'"
                        wire:click="sortBy('{{ $value }}')"
                        @class([
                            'relative rounded-md px-3 py-1.5 text-sm font-medium transition-colors',
                            'text-slate-900 dark:text-white' => $sort === $value,
                            'text-slate-600 hover:text-slate-900 dark:text-slate-500 dark:hover:text-slate-300' => $sort !== $value,
                        ])
                        data-test="sort-{{ $value }}"
                    >
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <flux:checkbox wire:model.live="hideDuplicates" :label="__('Do not show duplicates')" data-test="filter-hide-duplicates" />

                <flux:select wire:model.live="status" size="sm" class="w-auto min-w-40" data-test="filter-status">
                    <flux:select.option value="">{{ __('All statuses') }}</flux:select.option>
                    @foreach (self::STATUS_META as $value => $meta)
                        <flux:select.option value="{{ $value }}">{{ $meta['label'] }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model.live="board" size="sm" class="w-auto min-w-40" data-test="filter-board">
                    <flux:select.option value="">{{ __('All boards') }}</flux:select.option>
                    @foreach ($this->boards as $board)
                        <flux:select.option value="{{ $board->id }}">{{ $board->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model.live="category" size="sm" class="w-auto min-w-40" data-test="filter-category">
                    <flux:select.option value="">{{ __('All categories') }}</flux:select.option>
                    @foreach ($this->categories as $category)
                        <flux:select.option value="{{ $category->id }}">{{ $category->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        </x-sticky-toolbar>

        {{-- Ideas list --}}
        <div class="mt-5 space-y-3">
            @forelse ($this->ideas as $idea)
                @php($meta = $this->statusMeta($idea->status))
                <div
                    class="flex gap-4 rounded-xl border border-zinc-200 bg-white p-4 transition hover:border-indigo-200 hover:shadow-sm dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-indigo-900/60"
                    wire:key="idea-{{ $idea->id }}"
                    data-test="idea-row"
                >
                    {{-- Vote toggle --}}
                    <flux:tooltip :content="$this->canParticipate ? ($idea->voted ? __('You voted this idea..') : __('Click to vote for this idea..')) : __('Viewers have read-only access.')">
                        <button
                            type="button"
                            wire:click="toggleVote({{ $idea->id }})"
                            wire:loading.attr="disabled"
                            @disabled(! $this->canParticipate)
                            aria-pressed="{{ $idea->voted ? 'true' : 'false' }}"
                            @class([
                                'flex w-12 shrink-0 flex-col items-center justify-center gap-0.5 self-start rounded-lg border py-2 transition',
                                'cursor-not-allowed opacity-60' => ! $this->canParticipate,
                                'cursor-pointer' => $this->canParticipate,
                                'border-indigo-200 bg-indigo-50 text-indigo-600 dark:border-indigo-500/40 dark:bg-indigo-500/10 dark:text-indigo-300' => $idea->voted,
                                'border-zinc-200 text-slate-600 hover:border-indigo-200 hover:text-indigo-600 dark:border-zinc-700 dark:text-slate-500 dark:hover:border-indigo-500/40' => ! $idea->voted,
                            ])
                            data-test="vote-button"
                        >
                            <flux:icon.chevron-up class="size-4" />
                            <span class="text-sm font-extrabold">{{ $idea->votes_count }}</span>
                            <span class="text-[10px] font-medium uppercase tracking-wide {{ $idea->voted ? 'text-indigo-500/80 dark:text-indigo-300/80' : 'text-slate-500' }}">{{ trans_choice('vote|votes', $idea->votes_count) }}</span>
                        </button>
                    </flux:tooltip>

                    <a
                        href="{{ route('ideas.show', ['idea' => $idea->slug]) }}"
                        wire:navigate
                        class="min-w-0 flex-1"
                        data-test="idea-link"
                    >
                        <div class="flex flex-wrap items-center gap-2">
                            <flux:badge :color="$meta['color']" size="sm" class="{{ $meta['class'] ?? '' }}">
                                <span class="me-1 inline-block size-1.5 rounded-full bg-current"></span>{{ $meta['label'] }}
                            </flux:badge>
                            @if ($idea->category)
                                <flux:badge color="zinc" size="sm" variant="outline">{{ $idea->category->name }}</flux:badge>
                            @endif
                        </div>

                        <flux:heading size="lg" class="mt-2 truncate">{{ $idea->title }}</flux:heading>

                        <flux:text class="mt-1 line-clamp-2 text-sm text-slate-600 dark:text-slate-500">
                            {{ \Illuminate\Support\Str::limit(strip_tags($idea->description), 130) }}
                        </flux:text>

                        @php($author = $idea->is_anonymous ? __('Anonymous') : ($idea->submittedBy?->name ?? __('Unknown')))

                        <div class="mt-3 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-600 dark:text-slate-500">
                            <div class="flex items-center gap-1.5">
                                <flux:avatar size="xs" :name="$author" color="auto" color:seed="{{ $idea->submitted_by_user_id ?? $author }}" />
                                <span>{{ $author }}</span>
                            </div>

                            @if ($idea->board)
                                <span aria-hidden="true">·</span>
                                <span>{{ $idea->board->name }}</span>
                            @endif

                            <span aria-hidden="true">·</span>
                            <span>{{ trans_choice(':count comment|:count comments', $idea->comments_count, ['count' => $idea->comments_count]) }}</span>

                            <flux:tooltip :content="$idea->created_at->format('M j, Y \a\t g:i A')" class="ms-auto shrink-0">
                                <span>{{ $idea->created_at->format('M j, Y') }}</span>
                            </flux:tooltip>
                        </div>
                    </a>
                </div>
            @empty
                <div class="rounded-xl border border-dashed border-zinc-300 py-14 text-center dark:border-zinc-700" data-test="ideas-empty">
                    <flux:icon.light-bulb class="mx-auto size-8 text-slate-400 dark:text-slate-700" />
                    <flux:heading class="mt-3">{{ __('No ideas here yet') }}</flux:heading>
                    <flux:text class="mt-1 text-sm text-slate-600 dark:text-slate-500">
                        {{ $this->canParticipate ? __('Try clearing the filters, or be the first to submit one.') : __('Try clearing the filters.') }}
                    </flux:text>
                    @if ($this->canParticipate)
                        <flux:button :href="route('ideas.create')" wire:navigate variant="primary" icon="plus" size="sm" class="mt-4">{{ __('New idea') }}</flux:button>
                    @endif
                </div>
            @endforelse
        </div>

        @if ($this->ideas->hasPages())
            <div class="mt-6">
                {{ $this->ideas->links() }}
            </div>
        @endif
    </div>
</section>
