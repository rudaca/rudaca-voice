<?php

use App\Enums\TeamRole;
use App\Models\IdeaComment;
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

new #[Title('Moderate comments')] class extends Component {
    use WithPagination;

    /**
     * Display metadata for each idea status (label + Flux badge color).
     *
     * `dotColor` is only needed when `class` overrides the badge's rendered
     * color, so that <x-status-dot> can follow what the badge actually looks
     * like rather than the nominal `color`.
     *
     * @var array<string, array{label: string, color: string, class?: string, dotColor?: string}>
     */
    public const STATUS_META = [
        'new' => ['label' => 'New', 'color' => 'zinc'],
        'approved' => ['label' => 'Approved', 'color' => 'amber'],
        'planned' => ['label' => 'Planned', 'color' => 'blue'],
        'in_progress' => ['label' => 'In Progress', 'color' => 'indigo'],
        'released' => ['label' => 'Completed', 'color' => 'green'],
        'not_doing' => ['label' => 'Declined', 'color' => 'red'],
        'duplicate' => ['label' => 'Duplicate', 'color' => 'rose', 'class' => 'bg-red-100! text-red-700! dark:bg-red-900/40! dark:text-red-300!', 'dotColor' => 'red'],
    ];

    /**
     * The moderation-state tabs, mapped to the stats() key each one mirrors.
     * Drives both the segmented control and the stat cards, so the two can't
     * drift out of step.
     *
     * @var array<string, string>
     */
    public const FILTER_TABS = [
        'all' => 'total',
        'visible' => 'underReview',
        'hidden' => 'flagged',
        'deleted' => 'deleted',
    ];

    #[Url(as: 'filter')]
    public string $filter = 'all';

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
    #[Url(as: 'status')]
    public array $status = [];

    #[Url(as: 'q')]
    public string $search = '';

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

    #[Url(as: 'from')]
    public string $dateFrom = '';

    #[Url(as: 'to')]
    public string $dateTo = '';

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

        if (in_array($property, ['filter', 'group', 'board', 'status', 'search', 'category', 'author', 'dateFrom', 'dateTo'], true)) {
            $this->resetPage();
        }
    }

    /**
     * Switch to a moderation-state tab. Backs the stat cards, which double as
     * a second way into the same tabs; the segmented control uses wire:model.
     */
    public function selectFilter(string $filter): void
    {
        $this->filter = array_key_exists($filter, self::FILTER_TABS) ? $filter : 'all';
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
     * Clear the status filter.
     */
    public function clearStatusFilter(): void
    {
        $this->status = [];
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
            || $this->status !== []
            || $this->search !== ''
            || $this->hasSecondRowFilters;
    }

    /**
     * Clear every filter control back to its default.
     */
    public function clearFilters(): void
    {
        $this->reset(['filter', 'group', 'board', 'status', 'search', 'category', 'author', 'dateFrom', 'dateTo']);
        $this->resetPage();
    }

    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
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
     * Users who have written at least one comment on the team's ideas, used for the
     * author filter dropdown.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function authors(): Collection
    {
        return User::query()
            ->whereIn('id', IdeaComment::query()
                ->whereHas('idea', fn ($query) => $query->where('team_id', $this->team->id))
                ->distinct()
                ->pluck('user_id'))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * Whether the current user may hide or restore comments (admin and above).
     */
    #[Computed]
    public function canModerate(): bool
    {
        return Auth::user()->teamRole($this->team)?->isAtLeast(TeamRole::Admin) ?? false;
    }

    /**
     * Whether the current user may permanently delete a soft-deleted comment (owner only).
     */
    #[Computed]
    public function canPermanentlyDelete(): bool
    {
        return Auth::user()->teamRole($this->team)?->isAtLeast(TeamRole::Owner) ?? false;
    }

    /**
     * Narrow a comment query down to the team and to what the toolbar filters
     * currently select. Shared by the listing and the stat cards so both always
     * agree. The moderation-state tabs are deliberately left out: the stat cards
     * are the per-state breakdown, so narrowing them by the active tab would
     * zero out the other three.
     */
    protected function applyFilters(Builder $query): Builder
    {
        return $query
            ->whereHas('idea', function ($query) {
                $query->where('team_id', $this->team->id)
                    ->when($this->group !== '', fn ($query) => $query->where('board_group_id', $this->group))
                    ->when($this->board !== [], fn ($query) => $query->whereIn('board_id', $this->board))
                    ->when($this->status !== [], fn ($query) => $query->whereIn('status', $this->status))
                    ->when($this->category !== [], fn ($query) => $query->whereHas('category', fn ($query) => $query->whereIn('name', $this->category)));
            })
            ->when($this->author !== [], fn (Builder $query) => $query->whereIn('user_id', $this->author))
            ->when(trim($this->search) !== '', fn (Builder $query) => $query->where('body', 'like', $this->likeTerm()))
            ->when($this->dateFrom !== '', fn (Builder $query) => $query->whereDate('created_at', '>=', $this->dateFrom))
            ->when($this->dateTo !== '', fn (Builder $query) => $query->whereDate('created_at', '<=', $this->dateTo));
    }

    /**
     * Comments across the team's ideas, newest first. The "deleted" filter shows only
     * soft-deleted comments; "visible" shows only unflagged ones; "hidden" shows only
     * flagged ones; the other filters exclude soft-deleted comments, as normal.
     *
     * @return LengthAwarePaginator<int, IdeaComment>
     */
    #[Computed]
    public function comments(): LengthAwarePaginator
    {
        $query = IdeaComment::query()
            ->when($this->filter === 'deleted', fn ($query) => $query->onlyTrashed());

        return $this->applyFilters($query)
            ->when($this->filter === 'visible', fn ($query) => $query->whereNull('hidden_at'))
            ->when($this->filter === 'hidden', fn ($query) => $query->whereNotNull('hidden_at'))
            ->with(['user:id,name', 'hiddenBy:id,name', 'idea:id,title,slug,status,board_id', 'idea.board:id,name'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(15);
    }

    /**
     * Summary stats shown above the comments table: counts by moderation state.
     * Every figure reflects the current filter selection, so narrowing the
     * toolbar narrows the cards too.
     *
     * @return array{total: int, underReview: int, flagged: int, deleted: int}
     */
    #[Computed]
    public function stats(): array
    {
        $filtered = fn () => $this->applyFilters(IdeaComment::query());

        return [
            'total' => $filtered()->withTrashed()->count(),
            'underReview' => $filtered()->whereNull('hidden_at')->count(),
            'flagged' => $filtered()->whereNotNull('hidden_at')->count(),
            'deleted' => $filtered()->onlyTrashed()->count(),
        ];
    }

    /**
     * Get the display metadata for a status value.
     *
     * @return array{label: string, color: string, class?: string, dotColor?: string}
     */
    public function statusMeta(string $status): array
    {
        return self::STATUS_META[$status] ?? ['label' => str($status)->headline()->value(), 'color' => 'zinc'];
    }

    /**
     * Flag a comment, replacing it with a moderation notice in its idea's thread.
     * This never deletes the comment; the original body is retained and restored on unflag.
     */
    public function hideComment(int $commentId): void
    {
        abort_unless($this->canModerate, 403);

        $comment = $this->commentQuery()->whereKey($commentId)->firstOrFail();
        $comment->hide(Auth::id());

        Flux::toast(variant: 'success', text: __('Comment flagged.'));
    }

    /**
     * Unflag a previously flagged comment, restoring it in the idea's thread.
     */
    public function unhideComment(int $commentId): void
    {
        abort_unless($this->canModerate, 403);

        $comment = $this->commentQuery()->whereKey($commentId)->firstOrFail();
        $comment->unhide();

        Flux::toast(variant: 'success', text: __('Comment unflagged.'));
    }

    /**
     * Soft-delete a comment (admin and above). Reversible: it moves to the "Deleted"
     * filter and can only be permanently removed by an owner, via permanentlyDeleteComment().
     */
    public function deleteComment(int $commentId): void
    {
        abort_unless($this->canModerate, 403);

        $comment = $this->commentQuery()->whereKey($commentId)->firstOrFail();
        $comment->delete();

        Flux::toast(variant: 'success', text: __('Comment deleted.'));
    }

    /**
     * Restore a soft-deleted comment back to the idea's thread.
     */
    public function restoreComment(int $commentId): void
    {
        abort_unless($this->canModerate, 403);

        $comment = IdeaComment::onlyTrashed()
            ->whereHas('idea', fn ($query) => $query->where('team_id', $this->team->id))
            ->whereKey($commentId)
            ->firstOrFail();

        $comment->restore();

        Flux::toast(variant: 'success', text: __('Comment restored.'));
    }

    /**
     * Permanently delete a soft-deleted comment. Unlike flagging or the regular
     * delete, this cannot be undone.
     */
    public function permanentlyDeleteComment(int $commentId): void
    {
        abort_unless($this->canPermanentlyDelete, 403);

        $comment = IdeaComment::onlyTrashed()
            ->whereHas('idea', fn ($query) => $query->where('team_id', $this->team->id))
            ->whereKey($commentId)
            ->firstOrFail();

        $comment->forceDelete();

        Flux::toast(variant: 'success', text: __('Comment permanently deleted.'));
    }

    /**
     * Base query for comments belonging to the current team, regardless of hidden state.
     */
    protected function commentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return IdeaComment::query()
            ->whereHas('idea', fn ($query) => $query->where('team_id', $this->team->id));
    }
}; ?>

@push('breadcrumbs')
    <x-breadcrumbs :items="[
        ['label' => __('Moderate Comments'), 'href' => null],
    ]" />
@endpush

<section class="mx-auto container px-6 pb-7 lg:px-8">
    <div class="flex flex-col gap-1">
        <flux:heading size="xl">{{ __('Moderate comments') }}</flux:heading>
        <flux:text class="text-slate-600 dark:text-slate-500">
            {{ __('Flag comments that violate guidelines. Flagged comments are replaced with a moderation notice in the idea thread and can be unflagged at any time.') }}
        </flux:text>
    </div>

    {{--
        Stats. Every figure tracks the toolbar filters, and the numbers are
        <x-rolling-number> odometers so a changed figure rolls into place rather
        than snapping to the new value.

        Each card mirrors one moderation-state tab, so the card for the active
        tab is highlighted and the whole card is a second way to select that
        tab. The click target is a stretched overlay button rather than the card
        itself: it keeps the card's <div> content valid inside a button and
        gives the tab a real focusable control.

        The active tab deliberately does not narrow these figures -- the cards
        are the per-state breakdown, so filtering them by the selected state
        would zero out the other three. See applyFilters().
    --}}
    @php($statCards = [
        'all' => ['label' => __('All comments'), 'key' => 'total', 'name' => 'total', 'test' => 'stat-total', 'figureClass' => 'text-slate-900 dark:text-white'],
        'visible' => ['label' => __('Visible'), 'key' => 'underReview', 'name' => 'under-review', 'test' => 'stat-under-review', 'figureClass' => 'text-slate-900 dark:text-white'],
        'hidden' => ['label' => __('Flagged'), 'key' => 'flagged', 'name' => 'flagged', 'test' => 'stat-flagged', 'figureClass' => 'text-red-600 dark:text-red-400'],
        'deleted' => ['label' => __('Deleted'), 'key' => 'deleted', 'name' => 'deleted', 'test' => 'stat-deleted', 'figureClass' => 'text-slate-900 dark:text-white'],
    ])

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($statCards as $tab => $card)
            @php($isActiveTab = $filter === $tab)

            <div
                @class([
                    'relative rounded-xl border bg-white p-4 transition-colors dark:bg-zinc-900',
                    'border-indigo-500 dark:border-indigo-400' => $isActiveTab,
                    'border-zinc-200 hover:border-indigo-200 dark:border-zinc-700 dark:hover:border-indigo-500/40' => ! $isActiveTab,
                ])
                data-test="{{ $card['test'] }}"
                @if ($isActiveTab) data-active-tab @endif
            >
                @if ($isActiveTab)
                    {{--
                        The highlight is its own element, keyed by tab, so switching tabs
                        removes one and inserts another instead of morphing classes onto a
                        surviving node -- which is what lets its CSS animation replay on
                        every selection. Same trick as <x-rolling-number>'s digit columns.
                    --}}
                    <span
                        class="stat-card-active-ring pointer-events-none absolute -inset-px rounded-xl ring-1 ring-indigo-500 dark:ring-indigo-400"
                        wire:key="stat-active-ring-{{ $tab }}"
                        aria-hidden="true"
                    ></span>
                @endif

                <button
                    type="button"
                    wire:click="selectFilter('{{ $tab }}')"
                    aria-pressed="{{ $isActiveTab ? 'true' : 'false' }}"
                    class="absolute inset-0 z-10 cursor-pointer rounded-xl focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-500"
                    data-test="{{ $card['test'] }}-select"
                >
                    <span class="sr-only">{{ __('Show :label', ['label' => $card['label']]) }}</span>
                </button>

                <flux:text class="text-sm text-slate-600 dark:text-slate-500">{{ $card['label'] }}</flux:text>
                <x-rolling-number :name="$card['name']" :value="$this->stats[$card['key']]" class="mt-1 text-3xl font-bold {{ $card['figureClass'] }}" />
            </div>
        @endforeach
    </div>

    {{--
        Controls: filter tabs + search (row 1 left), board/status filters + collapse toggle (row 1 right).
        Category, author and the date range live in a collapsible second row that's tucked
        away by default. Both rows stick below the app header while the list scrolls.
    --}}
    <x-sticky-toolbar class="mt-6 py-2.5">
        <div class="flex flex-col gap-1.5" x-data="{ expanded: @js($this->hasSecondRowFilters) }">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div class="flex flex-wrap items-center gap-3">
                    <flux:radio.group wire:model.live="filter" variant="segmented" size="sm">
                        <flux:radio value="all">{{ __('All') }}</flux:radio>
                        <flux:radio value="visible">{{ __('Visible') }}</flux:radio>
                        <flux:radio value="hidden">{{ __('Flagged') }}</flux:radio>
                        <flux:radio value="deleted">{{ __('Deleted') }}</flux:radio>
                    </flux:radio.group>

                    <flux:input
                        wire:model.live.debounce.300ms="search"
                        icon="magnifying-glass"
                        icon:variant="outline"
                        size="sm"
                        clearable
                        placeholder="{{ __('Search comments') }}"
                        data-test="filter-search"
                        @class([
                            'w-full sm:w-64 md:w-80',
                            'border-gray-800! font-semibold! dark:border-gray-400!' => trim($search) !== '',
                        ])
                    />

                    @php($selectedItemClasses = 'data-checked:font-semibold [&[data-checked]_[data-flux-menu-item-icon]]:text-indigo-500!')

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
                        <flux:button size="sm" icon:trailing="chevron-down" icon-trailing:variant="outline" data-test="filter-board-trigger" @class([
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
                                icon:variant="outline"
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
                        <flux:button size="sm" icon:trailing="chevron-down" icon-trailing:variant="outline" data-test="filter-status-trigger" @class([
                            'w-auto',
                            'border-gray-800! font-semibold! dark:border-gray-400!' => $status !== [],
                        ])>
                            {{ __('Idea Status') }}
                            @if ($status !== [])
                                <flux:badge size="sm" color="zinc">{{ count($status) }}</flux:badge>
                            @endif
                        </flux:button>

                        <flux:menu class="w-56">
                            <flux:menu.item
                                keep-open
                                wire:click="clearStatusFilter"
                                icon:trailing="{{ $status === [] ? 'check' : '' }}"
                                icon:variant="outline"
                                class="{{ $status === [] ? 'font-semibold' : '' }}"
                                data-test="filter-status-all"
                            >
                                {{ __('All Status') }}
                            </flux:menu.item>
                            <flux:menu.separator />

                            <flux:menu.checkbox.group wire:model.live="status">
                                @php($statusGroups = [
                                    ['new', 'approved', 'planned', 'in_progress', 'released'],
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
                                            <x-status-dot :color="$meta['dotColor'] ?? $meta['color']" class="me-2" />{{ $meta['label'] }}
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
                            icon:variant="outline"
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
                            aria-controls="moderate-comments-more-filters"
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
                id="moderate-comments-more-filters"
                class="grid transition-[grid-template-rows] duration-200 ease-out"
                :class="expanded ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]'"
            >
                <div class="overflow-hidden">
                    <div class="flex flex-wrap items-center gap-2 pt-2">
                        <flux:dropdown position="bottom" align="start">
                            <flux:button size="sm" icon:trailing="chevron-down" icon-trailing:variant="outline" data-test="filter-category-trigger" @class([
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
                                    icon:variant="outline"
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
                            <flux:button size="sm" icon:trailing="chevron-down" icon-trailing:variant="outline" data-test="filter-author-trigger" @class([
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
                                    icon:variant="outline"
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
                                <flux:text class="shrink-0 text-xs text-slate-700 dark:text-slate-500">{{ __('Created From') }}</flux:text>
                                <flux:input type="date" wire:model.live="dateFrom" size="sm" data-test="filter-date-from" @class([
                                    'w-auto',
                                    'border-gray-800! font-semibold! dark:border-gray-400!' => $dateFrom !== '',
                                ]) />
                            </div>

                            <div class="flex items-center gap-1.5">
                                <flux:text class="shrink-0 text-xs text-slate-700 dark:text-slate-500">{{ __('To') }}</flux:text>
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

    <div class="mt-4">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Comment') }}</flux:table.column>
                <flux:table.column>{{ __('Idea') }}</flux:table.column>
                <flux:table.column>{{ __('Author') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column>{{ __('Action') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($this->comments as $comment)
                    <flux:table.row :key="'comment-'.$comment->id" data-test="moderate-comment-row">
                        <flux:table.cell class="max-w-sm">
                            <flux:modal.trigger name="view-comment-{{ $comment->id }}">
                                <button type="button" class="cursor-pointer text-left text-sm text-slate-800 hover:underline dark:text-slate-400" data-test="view-comment">
                                    {{ str($comment->body)->limit(30) }}
                                </button>
                            </flux:modal.trigger>
                            <div class="mt-1 flex items-center gap-1 text-xs text-slate-700">
                                <flux:icon.clock class="size-3.5" />
                                {{ $comment->created_at->forUser()->format('M d, Y h:i A') }}
                            </div>
                        </flux:table.cell>

                        <flux:table.cell>
                            <a href="{{ route('ideas.show', ['idea' => $comment->idea->slug]) }}" wire:navigate class="text-sm hover:underline">
                                {{ $comment->idea->title }}
                            </a>
                            @php($ideaMeta = $this->statusMeta($comment->idea->status))
                            <div class="mt-1 flex flex-wrap items-center gap-1">
                                <flux:badge :color="$ideaMeta['color']" size="sm" class="{{ $ideaMeta['class'] ?? '' }}">
                                    <x-status-dot :color="$ideaMeta['dotColor'] ?? $ideaMeta['color']" size="size-1.5" class="me-1" />{{ $ideaMeta['label'] }}
                                </flux:badge>
                                @if ($comment->idea->board)
                                    <flux:badge color="zinc" size="sm" variant="outline" icon="chalkboard" icon:variant="outline">{{ $comment->idea->board->name }}</flux:badge>
                                @endif
                            </div>
                        </flux:table.cell>

                        <flux:table.cell class="text-slate-700 dark:text-slate-400">
                            {{ $comment->user?->name ?? __('Unknown') }}
                        </flux:table.cell>

                        <flux:table.cell>
                            @if ($comment->trashed())
                                <flux:badge size="sm" icon="trash" icon:variant="outline" class="bg-red-100! text-red-500! dark:bg-red-950! dark:text-red-500!">{{ __('Deleted') }}</flux:badge>
                            @elseif ($comment->isHidden())
                                <flux:badge color="red" size="sm" icon="flag" icon:variant="outline">{{ __('Flagged') }}</flux:badge>
                                @if ($comment->hiddenBy)
                                    <div class="mt-1 text-xs text-slate-700">{{ __('by :name', ['name' => $comment->hiddenBy->name]) }}</div>
                                @endif
                            @else
                                <flux:badge color="green" size="sm" variant="outline">{{ __('Visible') }}</flux:badge>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell>
                            @if ($comment->trashed())
                                <flux:dropdown position="bottom" align="end">
                                    <flux:button
                                        size="sm"
                                        variant="ghost"
                                        icon="ellipsis-vertical"
                                        icon:variant="outline"
                                        icon:trailing="chevron-down"
                                        icon-trailing:variant="outline"
                                        icon-trailing:class="transition-transform duration-200 group-data-open:rotate-180"
                                        class="group"
                                        :square="false"
                                        data-test="deleted-comment-actions-trigger"
                                    />

                                    <flux:menu>
                                        <flux:menu.item
                                            href="{{ route('ideas.show', ['idea' => $comment->idea->slug]) }}"
                                            wire:navigate
                                            icon="light-bulb"
                                            icon:variant="outline"
                                            data-test="view-idea"
                                        >
                                            {{ __('View Idea') }}
                                        </flux:menu.item>
                                        <flux:menu.separator />

                                        <flux:menu.item
                                            wire:click="restoreComment({{ $comment->id }})"
                                            icon="arrow-uturn-left"
                                            icon:variant="outline"
                                            data-test="restore-comment"
                                        >
                                            {{ __('Restore') }}
                                        </flux:menu.item>

                                        @if ($this->canPermanentlyDelete)
                                            <flux:modal.trigger name="confirm-permanent-delete-{{ $comment->id }}">
                                                <flux:menu.item
                                                    icon="trash"
                                                    icon:variant="outline"
                                                    class="text-red-600! hover:text-red-700! dark:text-red-400! dark:hover:text-red-300! data-flux-menu-item-icon:text-red-600! dark:data-flux-menu-item-icon:text-red-400!"
                                                    data-test="permanently-delete-comment"
                                                >
                                                    {{ __('Delete Permanently') }}
                                                </flux:menu.item>
                                            </flux:modal.trigger>
                                        @endif
                                    </flux:menu>
                                </flux:dropdown>

                                @if ($this->canPermanentlyDelete)
                                    <flux:modal name="confirm-permanent-delete-{{ $comment->id }}" class="max-w-lg" :dismissible="false" data-test="confirm-permanent-delete-modal">
                                        <div class="space-y-5">
                                            <div>
                                                <flux:heading size="lg">{{ __('Permanently delete this comment?') }}</flux:heading>
                                                <flux:text class="mt-2 text-sm text-slate-600 dark:text-slate-500">
                                                    {{ __('This will permanently delete this comment. This cannot be undone.') }}
                                                </flux:text>
                                            </div>
                                            <div class="flex justify-end gap-2">
                                                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                                                <flux:button wire:click="permanentlyDeleteComment({{ $comment->id }})" variant="danger" data-test="confirm-permanent-delete">
                                                    {{ __('Delete Permanently') }}
                                                </flux:button>
                                            </div>
                                        </div>
                                    </flux:modal>
                                @endif
                            @else
                                <flux:dropdown position="bottom" align="end">
                                    <flux:button
                                        size="sm"
                                        variant="ghost"
                                        icon="ellipsis-vertical"
                                        icon:variant="outline"
                                        icon:trailing="chevron-down"
                                        icon-trailing:variant="outline"
                                        icon-trailing:class="transition-transform duration-200 group-data-open:rotate-180"
                                        class="group"
                                        :square="false"
                                        data-test="comment-actions-trigger"
                                    />

                                    <flux:menu>
                                        <flux:menu.item
                                            href="{{ route('ideas.show', ['idea' => $comment->idea->slug]) }}"
                                            wire:navigate
                                            icon="light-bulb"
                                            icon:variant="outline"
                                            data-test="view-idea"
                                        >
                                            {{ __('View Idea') }}
                                        </flux:menu.item>
                                        <flux:menu.separator />

                                        @if ($comment->isHidden())
                                            <flux:menu.item
                                                wire:click="unhideComment({{ $comment->id }})"
                                                icon="flag-slash"
                                                icon:variant="outline"
                                                class="text-red-600! hover:text-red-700! dark:text-red-400! dark:hover:text-red-300! data-flux-menu-item-icon:text-red-600! dark:data-flux-menu-item-icon:text-red-400!"
                                                data-test="unhide-comment"
                                            >
                                                {{ __('Unflag') }}
                                            </flux:menu.item>
                                        @else
                                            <flux:menu.item
                                                wire:click="hideComment({{ $comment->id }})"
                                                icon="flag"
                                                icon:variant="outline"
                                                class="text-red-600! hover:text-red-700! dark:text-red-400! dark:hover:text-red-300! data-flux-menu-item-icon:text-red-600! dark:data-flux-menu-item-icon:text-red-400!"
                                                data-test="hide-comment"
                                            >
                                                {{ __('Flag') }}
                                            </flux:menu.item>
                                        @endif

                                        <flux:menu.item
                                            wire:click="deleteComment({{ $comment->id }})"
                                            wire:confirm="{{ __('Delete this comment?') }}"
                                            icon="trash"
                                            icon:variant="outline"
                                            class="text-red-600! hover:text-red-700! dark:text-red-400! dark:hover:text-red-300! data-flux-menu-item-icon:text-red-600! dark:data-flux-menu-item-icon:text-red-400!"
                                            data-test="delete-comment"
                                        >
                                            {{ __('Delete') }}
                                        </flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>

                    <flux:modal name="view-comment-{{ $comment->id }}" class="w-full min-w-[min(90vw,28rem)] max-w-xl" data-test="view-comment-modal">
                        <div class="flex min-h-[min(60vh,28rem)] flex-col space-y-5">
                            <div>
                                <flux:heading size="lg">{{ __('Comment') }}</flux:heading>
                                <flux:text class="mt-1 flex items-center gap-1 text-xs text-slate-700">
                                    {{ $comment->user?->name ?? __('Unknown') }}
                                    <span aria-hidden="true">·</span>
                                    <flux:icon.clock class="size-3.5" />
                                    {{ $comment->created_at->forUser()->format('M d, Y h:i A') }}
                                </flux:text>
                            </div>

                            <flux:text class="flex-1 whitespace-pre-line text-sm text-slate-800 dark:text-slate-400">{{ $comment->body }}</flux:text>

                            <div class="flex flex-wrap justify-end gap-2">
                                <flux:modal.close>
                                    <flux:button variant="ghost" size="sm">{{ __('Close') }}</flux:button>
                                </flux:modal.close>

                                @if ($comment->trashed())
                                    <flux:button
                                        wire:click="restoreComment({{ $comment->id }})"
                                        variant="ghost"
                                        size="sm"
                                        icon="arrow-uturn-left"
                                        icon:variant="outline"
                                        data-test="restore-comment-modal"
                                    >
                                        {{ __('Restore') }}
                                    </flux:button>

                                    @if ($this->canPermanentlyDelete)
                                        <flux:modal.trigger name="confirm-permanent-delete-{{ $comment->id }}">
                                            <flux:button variant="danger" size="sm" icon="trash" icon:variant="outline" data-test="permanently-delete-comment-modal">
                                                {{ __('Delete Permanently') }}
                                            </flux:button>
                                        </flux:modal.trigger>
                                    @endif
                                @else
                                    @if ($comment->isHidden())
                                        <flux:button
                                            wire:click="unhideComment({{ $comment->id }})"
                                            variant="ghost"
                                            size="sm"
                                            icon="flag-slash"
                                            icon:variant="outline"
                                            class="text-red-600! hover:text-red-700! dark:text-red-400!"
                                            data-test="unhide-comment-modal"
                                        >
                                            {{ __('Unflag') }}
                                        </flux:button>
                                    @else
                                        <flux:button
                                            wire:click="hideComment({{ $comment->id }})"
                                            variant="ghost"
                                            size="sm"
                                            icon="flag"
                                            icon:variant="outline"
                                            class="text-red-600! hover:text-red-700! dark:text-red-400!"
                                            data-test="hide-comment-modal"
                                        >
                                            {{ __('Flag') }}
                                        </flux:button>
                                    @endif

                                    <flux:button
                                        wire:click="deleteComment({{ $comment->id }})"
                                        wire:confirm="{{ __('Delete this comment?') }}"
                                        variant="ghost"
                                        size="sm"
                                        icon="trash"
                                        icon:variant="outline"
                                        class="text-red-600! hover:text-red-700! dark:text-red-400!"
                                        data-test="delete-comment-modal"
                                    >
                                        {{ __('Delete') }}
                                    </flux:button>
                                @endif
                            </div>
                        </div>
                    </flux:modal>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5">
                            <div class="py-14 text-center" data-test="moderate-comments-empty">
                                <flux:icon.chat-bubble-left-right class="mx-auto size-8 text-slate-400 dark:text-slate-700" />
                                <flux:heading class="mt-3">{{ __('No comments to show') }}</flux:heading>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>

    @if ($this->comments->hasPages())
        <div class="mt-6">
            {{ $this->comments->links() }}
        </div>
    @endif
</section>
