<?php

use App\Enums\TeamRole;
use App\Models\Idea;
use App\Models\IdeaStatusHistory;
use App\Models\Team;
use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Review queue')] class extends Component {
    use WithPagination;

    /**
     * Statuses that make up the review queue: ideas still waiting on a decision.
     *
     * @var array<int, string>
     */
    public const QUEUE_STATUSES = ['new', 'under_review'];

    /**
     * Which queue status the table below is narrowed to; 'all' shows the full queue.
     */
    #[Url(as: 'status')]
    public string $filter = 'all';

    #[Url(as: 'sort')]
    public string $sort = 'top';

    #[Url(as: 'group')]
    public string $group = '';

    /**
     * @var array<int, string>
     */
    #[Url(as: 'board')]
    public array $board = [];

    /**
     * @var array<int, string>
     */
    #[Url(as: 'category')]
    public array $category = [];

    /**
     * @var array<int, string>
     */
    #[Url(as: 'author')]
    public array $author = [];

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'from')]
    public string $dateFrom = '';

    #[Url(as: 'to')]
    public string $dateTo = '';

    /**
     * Display metadata for each queue status (label + Flux badge color).
     *
     * @var array<string, array{label: string, color: string, badge_dot: string}>
     */
    public const STATUS_META = [
        'new' => ['label' => 'New', 'color' => 'zinc', 'badge_dot' => 'bg-zinc-800 dark:bg-zinc-200'],
        'under_review' => ['label' => 'Under Review', 'color' => 'amber', 'badge_dot' => 'bg-amber-800 dark:bg-amber-200'],
    ];

    /**
     * Reset pagination whenever a filter changes.
     */
    public function updated(string $property): void
    {
        $property = explode('.', $property)[0];

        // Changing the group narrows the board list, so drop board selections it no longer contains.
        if ($property === 'group') {
            $this->board = [];
        }

        if (in_array($property, ['filter', 'group', 'board', 'category', 'author', 'search', 'dateFrom', 'dateTo'], true)) {
            $this->resetPage();
        }
    }

    /**
     * Clear the board filter.
     */
    public function clearBoardFilter(): void
    {
        $this->board = [];
        $this->resetPage();
    }

    /**
     * Clear the category filter.
     */
    public function clearCategoryFilter(): void
    {
        $this->category = [];
        $this->resetPage();
    }

    /**
     * Clear the author filter.
     */
    public function clearAuthorFilter(): void
    {
        $this->author = [];
        $this->resetPage();
    }

    /**
     * Escape LIKE wildcard characters so raw user input can't widen the match.
     */
    protected function likeTerm(): string
    {
        return '%'.addcslashes(trim($this->search), '%_').'%';
    }

    /**
     * Change the active sort order.
     */
    public function sortBy(string $sort): void
    {
        $this->sort = in_array($sort, ['top', 'newest', 'trending'], true) ? $sort : 'top';
        $this->resetPage();
    }

    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    /**
     * Whether the current user may approve or decline queued ideas.
     */
    #[Computed]
    public function canReview(): bool
    {
        return Auth::user()->teamRole($this->team)?->isAtLeast(TeamRole::Manager) ?? false;
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
            ->when($this->group !== '', fn ($query) => $query->where('board_group_id', $this->group))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * Distinct active category names for the current team, used for the filter dropdown.
     * Categories are scoped per board, so the same name may exist on multiple boards;
     * only unique names are listed and the filter matches on name across all of them.
     *
     * @return SupportCollection<int, string>
     */
    #[Computed]
    public function categories(): SupportCollection
    {
        return $this->team->categories()
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name')
            ->unique()
            ->values();
    }

    /**
     * Users who have submitted at least one idea for the current team, used for the author filter dropdown.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function authors(): Collection
    {
        return User::query()
            ->whereIn('id', Idea::query()
                ->where('team_id', $this->team->id)
                ->whereNotNull('submitted_by_user_id')
                ->distinct()
                ->pluck('submitted_by_user_id'))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * Whether any of the filters tucked away in the collapsible second row are active.
     * Drives the "filters in use" indicator dot on the row's collapse toggle.
     */
    #[Computed]
    public function hasSecondRowFilters(): bool
    {
        return $this->category !== []
            || $this->author !== []
            || $this->dateFrom !== ''
            || $this->dateTo !== '';
    }

    /**
     * Whether any filter control (across both rows) differs from its default, used to
     * show/hide the "Clear" button.
     */
    #[Computed]
    public function hasActiveFilters(): bool
    {
        return $this->filter !== 'all'
            || $this->group !== ''
            || $this->board !== []
            || $this->search !== ''
            || $this->hasSecondRowFilters;
    }

    /**
     * Reset every filter control back to its default.
     */
    public function clearFilters(): void
    {
        $this->reset(['filter', 'group', 'board', 'category', 'author', 'search', 'dateFrom', 'dateTo']);
        $this->resetPage();
    }

    /**
     * Base query for ideas awaiting a decision in the current team.
     */
    protected function queueQuery(): Builder
    {
        return Idea::query()
            ->where('team_id', $this->team->id)
            ->whereIn('status', self::QUEUE_STATUSES);
    }

    /**
     * The review queue, filtered and sorted per the toolbar controls.
     *
     * @return LengthAwarePaginator<int, Idea>
     */
    #[Computed]
    public function ideas(): LengthAwarePaginator
    {
        $query = $this->queueQuery()
            ->when($this->filter !== 'all', fn (Builder $query) => $query->where('status', $this->filter))
            ->when($this->group !== '', fn (Builder $query) => $query->where('board_group_id', $this->group))
            ->when($this->board !== [], fn (Builder $query) => $query->whereIn('board_id', $this->board))
            ->when($this->category !== [], fn (Builder $query) => $query->whereHas('category', fn ($query) => $query->whereIn('name', $this->category)))
            ->when($this->author !== [], fn (Builder $query) => $query->whereIn('submitted_by_user_id', $this->author))
            ->when(trim($this->search) !== '', fn (Builder $query) => $query->where('title', 'like', $this->likeTerm()))
            ->when($this->dateFrom !== '', fn (Builder $query) => $query->whereDate('created_at', '>=', $this->dateFrom))
            ->when($this->dateTo !== '', fn (Builder $query) => $query->whereDate('created_at', '<=', $this->dateTo))
            ->with(['board:id,name', 'submittedBy:id,name'])
            ->withCount(['votes', 'comments']);

        match ($this->sort) {
            'newest' => $query->orderByDesc('created_at')->orderByDesc('id'),
            'trending' => $query->orderByRaw('(votes_count + comments_count * 3) desc')->orderByDesc('id'),
            default => $query->orderByDesc('votes_count')->orderByDesc('id'),
        };

        return $query->paginate(15);
    }

    /**
     * Summary stats shown above the queue table.
     *
     * @return array{awaiting: int, newThisWeek: int, totalVotes: int}
     */
    #[Computed]
    public function stats(): array
    {
        $queue = $this->queueQuery()->withCount('votes')->get(['id', 'created_at']);

        return [
            'awaiting' => $queue->count(),
            'newThisWeek' => $queue->where('created_at', '>=', now()->startOfWeek())->count(),
            'totalVotes' => (int) $queue->sum('votes_count'),
        ];
    }

    /**
     * Approve a queued idea, moving it to Planned.
     */
    public function approve(int $ideaId): void
    {
        $this->decide($ideaId, 'planned');

        Flux::toast(variant: 'success', text: __('Idea approved.'));
    }

    /**
     * Decline a queued idea.
     */
    public function decline(int $ideaId): void
    {
        $this->decide($ideaId, 'not_doing');

        Flux::toast(variant: 'success', text: __('Idea declined.'));
    }

    /**
     * Move a queued idea to the given status and record the change in its history.
     */
    private function decide(int $ideaId, string $newStatus): void
    {
        abort_unless($this->canReview, 403);

        $idea = $this->queueQuery()->findOrFail($ideaId);

        $previousStatus = $idea->status;

        $idea->update(['status' => $newStatus]);

        IdeaStatusHistory::create([
            'idea_id' => $idea->id,
            'changed_by_user_id' => Auth::id(),
            'old_status' => $previousStatus,
            'new_status' => $newStatus,
        ]);

        unset($this->ideas, $this->stats);

        if ($this->ideas->isEmpty() && $this->ideas->currentPage() > 1) {
            $this->resetPage();
            unset($this->ideas);
        }
    }

    /**
     * Get the display metadata for a queue status value.
     *
     * @return array{label: string, color: string, badge_dot: string}
     */
    public function statusMeta(string $status): array
    {
        return self::STATUS_META[$status] ?? ['label' => str($status)->headline()->value(), 'color' => 'zinc', 'badge_dot' => 'bg-zinc-800 dark:bg-zinc-200'];
    }
}; ?>

@push('breadcrumbs')
    <x-breadcrumbs :items="[
        ['label' => __('Review Queue'), 'href' => null],
    ]" />
@endpush

<section class="mx-auto container px-6 pb-7 lg:px-8">
    {{-- Header --}}
    <div class="flex flex-col gap-1">
        <flux:heading size="xl">{{ __('Review queue') }}</flux:heading>
        <flux:text class="text-slate-600 dark:text-slate-500">
            {{ __('New and under-review ideas waiting on a decision. Triage the highest-voted first.') }}
        </flux:text>
    </div>

    {{-- Stats --}}
    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" data-test="stat-awaiting">
            <flux:text class="text-sm text-slate-600 dark:text-slate-500">{{ __('Awaiting review') }}</flux:text>
            <div class="mt-1 text-3xl font-bold text-slate-900 dark:text-white">{{ $this->stats['awaiting'] }}</div>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" data-test="stat-new-this-week">
            <flux:text class="text-sm text-slate-600 dark:text-slate-500">{{ __('New this week') }}</flux:text>
            <div class="mt-1 text-3xl font-bold text-slate-900 dark:text-white">{{ $this->stats['newThisWeek'] }}</div>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" data-test="stat-total-votes">
            <flux:text class="text-sm text-slate-600 dark:text-slate-500">{{ __('Total votes in queue') }}</flux:text>
            <div class="mt-1 text-3xl font-bold text-slate-900 dark:text-white">{{ $this->stats['totalVotes'] }}</div>
        </div>
    </div>

    {{--
        Controls: sort + search (row 1 left), board/queue-status filters + collapse toggle (row 1 right).
        Categories, authors and the date range live in a collapsible second row that's tucked
        away by default. Both rows stick below the app header while the list scrolls.
    --}}
    <x-sticky-toolbar class="mt-6 py-2.5">
        <div class="flex flex-col gap-1.5" x-data="{ expanded: @js($this->hasSecondRowFilters) }">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div class="flex flex-wrap items-center gap-3">
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

                    <flux:input
                        wire:model.live.debounce.300ms="search"
                        icon="magnifying-glass"
                        size="sm"
                        clearable
                        placeholder="{{ __('Search ideas') }}"
                        data-test="filter-search"
                        @class([
                            'w-full sm:w-64 md:w-80 lg:w-96',
                            'border-gray-800! font-semibold! dark:border-gray-400!' => trim($search) !== '',
                        ])
                    />

                    @php($selectedItemClasses = 'data-checked:font-semibold [&[data-checked]_[data-flux-menu-item-icon]]:text-green-500!')

                    <flux:select wire:model.live="group" size="sm" data-test="filter-group" @class([
                        'w-auto min-w-32',
                        'border-gray-800! font-semibold! dark:border-gray-400!' => $group !== '',
                    ])>
                        <flux:select.option value="">{{ __('All groups') }}</flux:select.option>
                        @foreach ($this->boardGroups as $boardGroup)
                            <flux:select.option value="{{ $boardGroup->id }}">{{ $boardGroup->name }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:dropdown position="bottom" align="start">
                        <flux:button size="sm" icon:trailing="chevron-down" data-test="filter-board-trigger" @class([
                            'w-auto',
                            'border-gray-800! font-semibold! dark:border-gray-400!' => $board !== [],
                        ])>
                            {{ __('Board') }}
                            @if ($board !== [])
                                <flux:badge size="sm" color="zinc">{{ count($board) }}</flux:badge>
                            @endif
                        </flux:button>

                        <flux:menu class="w-56">
                            <flux:menu.item
                                keep-open
                                wire:click="clearBoardFilter"
                                icon:trailing="{{ $board === [] ? 'check' : '' }}"
                                class="{{ $board === [] ? 'font-semibold' : '' }}"
                                data-test="filter-board-all"
                            >
                                {{ __('All Boards') }}
                            </flux:menu.item>
                            <flux:menu.separator />

                            <flux:menu.checkbox.group wire:model.live="board">
                                @foreach ($this->boards as $boardOption)
                                    <flux:menu.checkbox value="{{ $boardOption->id }}" keep-open class="{{ $selectedItemClasses }}" data-test="filter-board-{{ $boardOption->id }}">{{ $boardOption->name }}</flux:menu.checkbox>
                                @endforeach
                            </flux:menu.checkbox.group>
                        </flux:menu>
                    </flux:dropdown>

                    <flux:radio.group wire:model.live="filter" variant="segmented" size="sm">
                        <flux:radio value="all">{{ __('All') }}</flux:radio>
                        <flux:radio value="new">{{ __('New') }}</flux:radio>
                        <flux:radio value="under_review">{{ __('Under Review') }}</flux:radio>
                    </flux:radio.group>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    @if ($this->hasActiveFilters)
                        <flux:button
                            wire:click="clearFilters"
                            variant="outline"
                            size="sm"
                            icon="x-mark"
                            class="border-red-500! text-red-500! hover:bg-red-50! dark:hover:bg-red-500/10!"
                            data-test="clear-filters-row1"
                        >
                            {{ __('Clear') }}
                        </flux:button>
                    @endif

                    <flux:tooltip :content="__('More filters')">
                        <button
                            type="button"
                            x-on:click="expanded = !expanded"
                            :aria-expanded="expanded.toString()"
                            aria-controls="review-more-filters"
                            aria-label="{{ __('More filters') }}"
                            class="relative inline-flex items-center justify-center rounded-lg border border-zinc-200 p-2 text-slate-600 transition hover:border-indigo-200 hover:text-indigo-600 dark:border-zinc-700 dark:text-slate-400 dark:hover:border-indigo-500/40"
                            data-test="toggle-more-filters"
                        >
                            <flux:icon.chevron-down class="size-4 transition-transform duration-200" :class="expanded ? 'rotate-180' : ''" />
                            @if ($this->hasSecondRowFilters)
                                <span
                                    class="absolute -top-1 -right-1 block size-3 rounded-full bg-red-500 ring-2 ring-white dark:ring-zinc-800"
                                    data-test="filters-active-dot"
                                ></span>
                            @endif
                        </button>
                    </flux:tooltip>
                </div>
            </div>

            <div
                id="review-more-filters"
                class="grid transition-[grid-template-rows] duration-200 ease-out"
                :class="expanded ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]'"
            >
                <div class="overflow-hidden">
                    <div class="flex flex-wrap items-center gap-2 pt-2">
                        <flux:dropdown position="bottom" align="start">
                            <flux:button size="sm" icon:trailing="chevron-down" data-test="filter-category-trigger" @class([
                                'w-auto',
                                'border-gray-800! font-semibold! dark:border-gray-400!' => $category !== [],
                            ])>
                                {{ __('Category') }}
                                @if ($category !== [])
                                    <flux:badge size="sm" color="zinc">{{ count($category) }}</flux:badge>
                                @endif
                            </flux:button>

                            <flux:menu class="w-56">
                                <flux:menu.item
                                    keep-open
                                    wire:click="clearCategoryFilter"
                                    icon:trailing="{{ $category === [] ? 'check' : '' }}"
                                    class="{{ $category === [] ? 'font-semibold' : '' }}"
                                    data-test="filter-category-all"
                                >
                                    {{ __('All Categories') }}
                                </flux:menu.item>
                                <flux:menu.separator />

                                <flux:menu.checkbox.group wire:model.live="category">
                                    @foreach ($this->categories as $categoryIndex => $categoryName)
                                        <flux:menu.checkbox value="{{ $categoryName }}" keep-open class="{{ $selectedItemClasses }}" data-test="filter-category-{{ $categoryIndex }}">{{ $categoryName }}</flux:menu.checkbox>
                                    @endforeach
                                </flux:menu.checkbox.group>
                            </flux:menu>
                        </flux:dropdown>

                        <flux:dropdown position="bottom" align="start">
                            <flux:button size="sm" icon:trailing="chevron-down" data-test="filter-author-trigger" @class([
                                'w-auto',
                                'border-gray-800! font-semibold! dark:border-gray-400!' => $author !== [],
                            ])>
                                {{ __('Author') }}
                                @if ($author !== [])
                                    <flux:badge size="sm" color="zinc">{{ count($author) }}</flux:badge>
                                @endif
                            </flux:button>

                            <flux:menu class="w-56">
                                <flux:menu.item
                                    keep-open
                                    wire:click="clearAuthorFilter"
                                    icon:trailing="{{ $author === [] ? 'check' : '' }}"
                                    class="{{ $author === [] ? 'font-semibold' : '' }}"
                                    data-test="filter-author-all"
                                >
                                    {{ __('All Authors') }}
                                </flux:menu.item>
                                <flux:menu.separator />

                                <flux:menu.checkbox.group wire:model.live="author">
                                    @foreach ($this->authors as $authorOption)
                                        <flux:menu.checkbox value="{{ $authorOption->id }}" keep-open class="{{ $selectedItemClasses }}" data-test="filter-author-{{ $authorOption->id }}">{{ $authorOption->name }}</flux:menu.checkbox>
                                    @endforeach
                                </flux:menu.checkbox.group>
                            </flux:menu>
                        </flux:dropdown>

                        <div class="flex items-center py-1 px-3 bg-gray-100 rounded-lg dark:bg-zinc-800">
                            <div class="flex items-center gap-1.5">
                                <flux:text class="shrink-0 text-xs text-slate-500 dark:text-slate-500">{{ __('Created From') }}</flux:text>
                                <flux:input type="date" wire:model.live="dateFrom" size="sm" data-test="filter-date-from" @class([
                                    'w-auto',
                                    'border-gray-800! font-semibold! dark:border-gray-400!' => $dateFrom !== '',
                                ]) />
                            </div>

                            <div class="flex items-center gap-1.5">
                                <flux:text class="shrink-0 text-xs text-slate-500 dark:text-slate-500">{{ __('To') }}</flux:text>
                                <flux:input type="date" wire:model.live="dateTo" size="sm" data-test="filter-date-to" @class([
                                    'w-auto',
                                    'border-gray-800! font-semibold! dark:border-gray-400!' => $dateTo !== '',
                                ]) />
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </x-sticky-toolbar>

    <div class="mt-4 space-y-3">
        @forelse ($this->ideas as $idea)
            @php($meta = $this->statusMeta($idea->status))
            @php($authorName = $idea->is_anonymous ? __('Anonymous') : ($idea->submittedBy?->name ?? __('Unknown')))
            <div
                class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900 sm:flex-row sm:items-center"
                wire:key="queue-{{ $idea->id }}"
                data-test="queue-row"
            >
                <div class="flex w-14 shrink-0 flex-col items-center gap-0.5 self-start rounded-lg border border-zinc-200 py-1.5 dark:border-zinc-700 sm:self-center">
                    <span class="text-base font-extrabold text-slate-900 dark:text-slate-200">{{ $idea->votes_count }}</span>
                    <flux:icon.chevron-up class="size-3 text-slate-500" />
                </div>

                <div class="min-w-0 flex-1">
                    <a href="{{ route('ideas.show', ['idea' => $idea->slug]) }}" wire:navigate class="hover:underline">
                        <flux:heading size="lg" class="w-fit max-w-full truncate">{{ $idea->title }}</flux:heading>
                    </a>

                    <div class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-600 dark:text-slate-500">
                        <div class="flex items-center gap-1.5">
                            <flux:avatar size="xs" :name="$authorName" color="auto" color:seed="{{ $idea->submitted_by_user_id ?? $authorName }}" />
                            <span>{{ $authorName }}</span>
                        </div>

                        <span aria-hidden="true" class="text-base leading-none">&middot;</span>

                        <flux:badge :color="$meta['color']" size="sm">
                            <span class="me-1 inline-block size-2 rounded-full {{ $meta['badge_dot'] }}"></span>{{ $meta['label'] }}
                        </flux:badge>

                        @if ($idea->board)
                            <span aria-hidden="true" class="text-base leading-none">&middot;</span>
                            <flux:badge color="zinc" size="sm" variant="outline" icon="chalkboard">{{ $idea->board->name }}</flux:badge>
                        @endif

                        <span aria-hidden="true" class="text-base leading-none">&middot;</span>

                        <flux:badge color="zinc" size="sm" variant="outline" icon="chat-bubble-left" icon:variant="outline" class="text-gray-500! dark:text-gray-400!">
                            {{ trans_choice(':count comment|:count comments', $idea->comments_count, ['count' => $idea->comments_count]) }}
                        </flux:badge>

                        <span aria-hidden="true" class="text-base leading-none">&middot;</span>

                        <span>{{ $idea->created_at->format('M j, Y') }}</span>
                    </div>
                </div>

                <div class="flex shrink-0 items-center gap-2">
                    <flux:button
                        variant="ghost"
                        size="sm"
                        class="review-action-button border border-emerald-600 text-emerald-600 hover:bg-emerald-50 dark:border-emerald-400 dark:text-emerald-400 dark:hover:bg-emerald-950"
                        wire:click="approve({{ $idea->id }})"
                        data-test="approve-idea"
                    >
                        {{ __('Approve') }}
                    </flux:button>

                    <flux:button
                        variant="ghost"
                        size="sm"
                        class="review-action-button border border-red-600 text-red-600 hover:bg-red-50 dark:border-red-400 dark:text-red-400 dark:hover:bg-red-950"
                        wire:click="decline({{ $idea->id }})"
                        data-test="decline-idea"
                    >
                        {{ __('Decline') }}
                    </flux:button>
                </div>
            </div>
        @empty
            <div class="rounded-xl border border-dashed border-zinc-300 py-14 text-center dark:border-zinc-700" data-test="review-empty">
                <flux:icon.check-circle class="mx-auto size-8 text-emerald-400 dark:text-emerald-500" />
                <flux:heading class="mt-3">{{ __('Queue is clear') }}</flux:heading>
                <flux:text class="mt-1 text-sm text-slate-600 dark:text-slate-500">{{ __('Nothing needs attention right now. Nice work. 🎉') }}</flux:text>
            </div>
        @endforelse
    </div>

    @if ($this->ideas->hasPages())
        <div class="mt-6">
            {{ $this->ideas->links() }}
        </div>
    @endif
</section>
