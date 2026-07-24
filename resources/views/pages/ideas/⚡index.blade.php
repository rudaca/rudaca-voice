<?php

use App\Enums\TeamRole;
use App\Models\Idea;
use App\Models\IdeaVote;
use App\Models\Team;
use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
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
     * @var array<string, array{label: string, color: string, class?: string, badge_dot: string}>
     */
    public const STATUS_META = [
        'new' => ['label' => 'New', 'color' => 'zinc', 'badge_dot' => 'bg-zinc-800 dark:bg-zinc-200'],
        'under_review' => ['label' => 'Under Review', 'color' => 'amber', 'badge_dot' => 'bg-amber-800 dark:bg-amber-200'],
        'planned' => ['label' => 'Planned', 'color' => 'blue', 'badge_dot' => 'bg-blue-800 dark:bg-blue-200'],
        'in_progress' => ['label' => 'In Progress', 'color' => 'indigo', 'badge_dot' => 'bg-indigo-800 dark:bg-indigo-200'],
        'released' => ['label' => 'Implemented', 'color' => 'green', 'badge_dot' => 'bg-green-800 dark:bg-green-200'],
        'not_doing' => ['label' => 'Declined', 'color' => 'red', 'badge_dot' => 'bg-red-800 dark:bg-red-200'],
        'duplicate' => ['label' => 'Duplicate', 'color' => 'rose', 'class' => 'bg-red-100! text-red-700! dark:bg-red-900/40! dark:text-red-300!', 'badge_dot' => 'bg-red-800 dark:bg-red-200'],
    ];

    #[Url(as: 'sort')]
    public string $sort = 'newest';

    /**
     * @var array<int, string>
     */
    #[Url(as: 'status')]
    public array $status = [];

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

    #[Url(as: 'hide_duplicates')]
    public bool $hideDuplicates = false;

    #[Url(as: 'internal_comments')]
    public bool $onlyInternalComments = false;

    /**
     * Reset pagination whenever a filter changes.
     */
    public function updated(string $property): void
    {
        $property = explode('.', $property)[0];

        // Selecting the Duplicate status while "hide duplicates" is on would
        // otherwise return zero results, so switch it off automatically.
        if ($property === 'status' && in_array('duplicate', $this->status, true)) {
            $this->hideDuplicates = false;
        }

        // Turning on "hide duplicates" while the Duplicate status is selected would
        // otherwise return zero results, so drop it from the status filter.
        if ($property === 'hideDuplicates' && $this->hideDuplicates) {
            $this->status = array_values(array_diff($this->status, ['duplicate']));
        }

        // Changing the group narrows the board list, so drop board selections it no longer contains.
        if ($property === 'group') {
            $this->board = [];
        }

        if (in_array($property, ['status', 'group', 'board', 'category', 'author', 'search', 'dateFrom', 'dateTo', 'hideDuplicates', 'onlyInternalComments'], true)) {
            $this->resetPage();
        }
    }

    /**
     * Clear the status filter.
     */
    public function clearStatusFilter(): void
    {
        $this->status = [];
        $this->resetPage();
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

            $this->dispatch('modal-close', name: "confirm-unvote-{$idea->id}");
        } else {
            IdeaVote::firstOrCreate([
                'idea_id' => $idea->id,
                'user_id' => Auth::id(),
            ]);

            $this->dispatch('idea-voted', ideaId: $idea->id);

            Flux::toast(variant: 'success', text: __('You have successfully casted your vote.'));
        }
    }

    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    /**
     * The current user's role on the current team.
     */
    #[Computed]
    public function role(): ?TeamRole
    {
        return Auth::user()->teamRole($this->team);
    }

    /**
     * Whether the current user may vote and comment (employee and above; viewers are read-only).
     */
    #[Computed]
    public function canParticipate(): bool
    {
        return $this->role?->isAtLeast(TeamRole::Employee) ?? false;
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
            || $this->hideDuplicates
            || $this->onlyInternalComments
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
        return $this->status !== []
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
        $this->reset(['status', 'group', 'board', 'category', 'author', 'search', 'dateFrom', 'dateTo', 'hideDuplicates', 'onlyInternalComments']);
        $this->resetPage();
    }

    /**
     * The name of the board or board group currently filtering the list, if any.
     * Used to swap the "All Ideas" heading/title for something less misleading
     * when the view is scoped to a single board or group.
     */
    #[Computed]
    public function activeFilterLabel(): ?string
    {
        if (count($this->board) === 1) {
            return $this->boards->firstWhere('id', (int) $this->board[0])?->name;
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
            ->withCount([
                'votes',
                'comments',
                'comments as internal_comments_count' => fn ($query) => $query->where('is_internal', true)->whereNull('hidden_at'),
            ])
            ->withExists(['votes as voted' => fn ($query) => $query->where('user_id', Auth::id())])
            ->when($this->status !== [], fn ($query) => $query->whereIn('status', $this->status))
            ->when($this->group !== '', fn ($query) => $query->where('board_group_id', $this->group))
            ->when($this->board !== [], fn ($query) => $query->whereIn('board_id', $this->board))
            ->when($this->category !== [], fn ($query) => $query->whereHas('category', fn ($query) => $query->whereIn('name', $this->category)))
            ->when($this->author !== [], fn ($query) => $query->whereIn('submitted_by_user_id', $this->author))
            ->when(trim($this->search) !== '', fn ($query) => $query->where('title', 'like', $this->likeTerm()))
            ->when($this->dateFrom !== '', fn ($query) => $query->whereDate('created_at', '>=', $this->dateFrom))
            ->when($this->dateTo !== '', fn ($query) => $query->whereDate('created_at', '<=', $this->dateTo))
            ->when($this->hideDuplicates, fn ($query) => $query->where('status', '!=', 'duplicate'))
            ->when(
                $this->onlyInternalComments && $this->role?->isAtLeast(TeamRole::Manager),
                fn ($query) => $query->whereHas('comments', fn ($query) => $query->where('is_internal', true)->whereNull('hidden_at'))
            );

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
                        <flux:tooltip :content="$this->board !== [] ? __('Board') : __('Board group')">
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

        {{--
            Controls: sort + search (row 1 left), board/status filters + collapse toggle (row 1 right).
            Categories, authors, duplicates and the date range live in a collapsible second row that's
            tucked away by default. Both rows stick below the app header while the list scrolls.
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

                        <flux:dropdown position="bottom" align="start">
                            <flux:button size="sm" icon:trailing="chevron-down" data-test="filter-status-trigger" @class([
                                'w-auto',
                                'border-gray-800! font-semibold! dark:border-gray-400!' => $status !== [],
                            ])>
                                {{ __('Status') }}
                                @if ($status !== [])
                                    <flux:badge size="sm" color="zinc">{{ count($status) }}</flux:badge>
                                @endif
                            </flux:button>

                            <flux:menu class="w-56">
                                <flux:menu.item
                                    keep-open
                                    wire:click="clearStatusFilter"
                                    icon:trailing="{{ $status === [] ? 'check' : '' }}"
                                    class="{{ $status === [] ? 'font-semibold' : '' }}"
                                    data-test="filter-status-all"
                                >
                                    {{ __('All Status') }}
                                </flux:menu.item>
                                <flux:menu.separator />

                                <flux:menu.checkbox.group wire:model.live="status">
                                    @php($statusGroups = [
                                        ['new', 'under_review', 'planned', 'in_progress', 'released'],
                                        ['not_doing', 'duplicate'],
                                    ])

                                    @foreach ($statusGroups as $groupIndex => $statusGroup)
                                        @if ($groupIndex > 0)
                                            <flux:menu.separator />
                                        @endif

                                        @foreach ($statusGroup as $value)
                                            @php($meta = self::STATUS_META[$value])
                                            @php($isDanger = in_array($value, ['not_doing', 'duplicate'], true))

                                            <flux:menu.checkbox
                                                value="{{ $value }}"
                                                keep-open
                                                class="{{ $selectedItemClasses }} {{ $isDanger ? 'text-red-600! dark:text-red-400!' : '' }}"
                                                data-test="filter-status-{{ $value }}"
                                            >
                                                <span class="me-2 inline-block size-2 shrink-0 rounded-full {{ $meta['badge_dot'] }}"></span>{{ $meta['label'] }}
                                            </flux:menu.checkbox>
                                        @endforeach
                                    @endforeach
                                </flux:menu.checkbox.group>
                            </flux:menu>
                        </flux:dropdown>
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
                                aria-controls="ideas-more-filters"
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
                    id="ideas-more-filters"
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

                            <div class="ms-auto flex shrink-0 items-center gap-3">
                                <div @class([
                                    'flex items-center gap-2 rounded-md border px-2 py-1 transition-colors',
                                    'border-transparent' => ! $hideDuplicates,
                                    'border-gray-800! font-semibold! dark:border-gray-400!' => $hideDuplicates,
                                ])>
                                    <flux:checkbox wire:model.live="hideDuplicates" :label="__('Do not show duplicates')" data-test="filter-hide-duplicates" />
                                </div>

                                @if ($this->role?->isAtLeast(TeamRole::Manager))
                                    <div @class([
                                        'flex items-center gap-2 rounded-md border px-2 py-1 transition-colors',
                                        'border-transparent' => ! $onlyInternalComments,
                                        'border-gray-800! font-semibold! dark:border-gray-400!' => $onlyInternalComments,
                                    ])>
                                        <flux:checkbox wire:model.live="onlyInternalComments" :label="__('Show only with internal comments')" data-test="filter-only-internal-comments" />
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </x-sticky-toolbar>

        {{-- Ideas list --}}
        <div class="mt-3 space-y-3">
            @forelse ($this->ideas as $idea)
                @php($meta = $this->statusMeta($idea->status))
                <div
                    class="flex cursor-pointer gap-4 rounded-xl border border-zinc-200 bg-white p-4 transition hover:border-indigo-200 hover:bg-gray-50 hover:shadow-sm dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-indigo-900/60 dark:hover:bg-gray-800/40"
                    wire:key="idea-{{ $idea->id }}"
                    data-test="idea-row"
                >
                    {{-- Vote toggle --}}
                    <flux:tooltip :content="$this->canParticipate ? ($idea->voted ? __('You voted this idea..') : __('Click to vote for this idea..')) : __('Viewers have read-only access.')">
                        <button
                            type="button"
                            @if (! $idea->voted) wire:click="toggleVote({{ $idea->id }})" @endif
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
                            x-data="{ justVoted: false }"
                            x-on:idea-voted.window="if ($event.detail.ideaId === {{ $idea->id }}) { justVoted = true; setTimeout(() => justVoted = false, 4000) }"
                            @if ($idea->voted) x-on:click="$dispatch('modal-show', { name: 'confirm-unvote-{{ $idea->id }}' })" @endif
                        >
                            <flux:icon.chevron-up x-show="!justVoted" class="size-4" />
                            <flux:icon.hand-thumb-up
                                x-cloak
                                x-show="justVoted"
                                x-transition:enter="transition ease-out duration-300"
                                x-transition:enter-start="opacity-0 translate-y-2"
                                x-transition:enter-end="opacity-100 translate-y-0"
                                x-transition:leave="transition ease-in duration-150"
                                x-transition:leave-start="opacity-100 translate-y-0"
                                x-transition:leave-end="opacity-0 translate-y-2"
                                class="size-4"
                            />
                            <span class="text-sm font-extrabold">{{ $idea->votes_count }}</span>
                            <span class="text-[10px] font-medium uppercase tracking-wide {{ $idea->voted ? 'text-indigo-500/80 dark:text-indigo-300/80' : 'text-slate-500' }}">{{ trans_choice('vote|votes', $idea->votes_count) }}</span>
                        </button>
                    </flux:tooltip>

                    {{-- Confirm unvote modal --}}
                    <flux:modal name="confirm-unvote-{{ $idea->id }}" class="max-w-lg" :dismissible="false" data-test="confirm-unvote-modal">
                        <div class="space-y-5">
                            <div>
                                <flux:heading size="lg">{{ __('Remove your vote?') }}</flux:heading>
                                <flux:text class="mt-2 text-sm text-slate-600 dark:text-slate-500">
                                    {{ __('You are removing your vote from this idea.') }}
                                </flux:text>
                            </div>
                            <div class="flex justify-end gap-2">
                                <flux:modal.close><flux:button variant="ghost" data-test="confirm-unvote-cancel">{{ __('Cancel') }}</flux:button></flux:modal.close>
                                <flux:button wire:click="toggleVote({{ $idea->id }})" variant="danger" data-test="confirm-unvote-yes">{{ __('Yes') }}</flux:button>
                            </div>
                        </div>
                    </flux:modal>

                    <div class="min-w-0 flex-1">
                        <div class="flex min-w-0 items-center gap-1.5">
                            <a
                                href="{{ route('ideas.show', ['idea' => $idea->slug]) }}"
                                wire:navigate
                                class="min-w-0"
                                data-test="idea-link"
                            >
                                <flux:heading size="lg" class="w-fit max-w-full truncate hover:underline">{{ $idea->title }}</flux:heading>
                            </a>

                            @if ($this->role?->isAtLeast(TeamRole::Manager) && $idea->internal_comments_count > 0)
                                <flux:tooltip :content="trans_choice(':count internal comment|:count internal comments', $idea->internal_comments_count, ['count' => $idea->internal_comments_count])">
                                    <flux:badge size="sm" icon="exclamation-triangle" class="bg-red-100! text-red-800! dark:bg-red-950! dark:text-red-400!">{{ __('Internal Comments') }}</flux:badge>
                                </flux:tooltip>
                            @endif
                        </div>

                        @php($authorName = $idea->is_anonymous ? __('Anonymous') : ($idea->submittedBy?->name ?? __('Unknown')))

                        <div class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-600 dark:text-slate-500">
                            <flux:tooltip :content="__('Idea submitted by :name', ['name' => $authorName])">
                                @if ($idea->submitted_by_user_id)
                                    <a href="{{ route('ideas.index', ['author' => $idea->submitted_by_user_id]) }}" wire:navigate class="flex items-center gap-1.5 hover:underline">
                                        <flux:avatar size="xs" :name="$authorName" color="auto" color:seed="{{ $idea->submitted_by_user_id }}" />
                                        <span>{{ $authorName }}</span>
                                    </a>
                                @else
                                    <div class="flex items-center gap-1.5">
                                        <flux:avatar size="xs" :name="$authorName" color="auto" color:seed="{{ $authorName }}" />
                                        <span>{{ $authorName }}</span>
                                    </div>
                                @endif
                            </flux:tooltip>

                            <span aria-hidden="true" class="text-base leading-none">·</span>

                            <flux:tooltip :content="__('Current status')">
                                <a href="{{ route('ideas.index', ['status' => [$idea->status]]) }}" wire:navigate class="hover:underline">
                                    <flux:badge :color="$meta['color']" size="sm" class="{{ $meta['class'] ?? '' }}">
                                        <span class="me-1 inline-block size-2 rounded-full {{ $meta['badge_dot'] }}"></span>{{ $meta['label'] }}
                                    </flux:badge>
                                </a>
                            </flux:tooltip>

                            @if ($idea->category)
                                <span aria-hidden="true" class="text-base leading-none">·</span>
                                <flux:tooltip :content="__('Category')">
                                    <flux:badge color="zinc" size="sm" variant="outline">{{ $idea->category->name }}</flux:badge>
                                </flux:tooltip>
                            @endif

                            @if ($idea->board)
                                <span aria-hidden="true" class="text-base leading-none">·</span>
                                <flux:tooltip :content="__('The board where the idea was submitted')">
                                    <a href="{{ route('ideas.index', ['board' => [$idea->board_id]]) }}" wire:navigate class="hover:underline">
                                        <flux:badge color="zinc" size="sm" variant="outline" icon="chalkboard">{{ $idea->board->name }}</flux:badge>
                                    </a>
                                </flux:tooltip>
                            @endif

                            <span aria-hidden="true" class="text-base leading-none">·</span>

                            <flux:tooltip :content="__('Total number of comments')">
                                <flux:badge color="zinc" size="sm" variant="outline" icon="chat-bubble-left" icon:variant="outline" class="text-gray-500! dark:text-gray-400!">
                                    {{ trans_choice(':count comment|:count comments', $idea->comments_count, ['count' => $idea->comments_count]) }}
                                </flux:badge>
                            </flux:tooltip>

                            <span aria-hidden="true" class="text-base leading-none">·</span>

                            <flux:tooltip :content="__('Submitted at :date', ['date' => $idea->created_at->format('M d, Y h:iA')])">
                                <span>{{ $idea->created_at->format('M j, Y') }}</span>
                            </flux:tooltip>
                        </div>
                    </div>
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
