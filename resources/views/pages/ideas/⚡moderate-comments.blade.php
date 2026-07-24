<?php

use App\Enums\TeamRole;
use App\Models\IdeaComment;
use App\Models\Team;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
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
     * Reset pagination whenever a filter changes.
     */
    public function updated(string $property): void
    {
        $property = explode('.', $property)[0];

        // Changing the group narrows the board list, so drop board selections it no longer contains.
        if ($property === 'group') {
            $this->board = [];
        }

        if (in_array($property, ['filter', 'group', 'board', 'status', 'search'], true)) {
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
     * Clear the status filter.
     */
    public function clearStatusFilter(): void
    {
        $this->status = [];
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
     * Whether any filter control differs from its default, used to show/hide the "Clear" button.
     */
    #[Computed]
    public function hasActiveFilters(): bool
    {
        return $this->filter !== 'all'
            || $this->group !== ''
            || $this->board !== []
            || $this->status !== []
            || $this->search !== '';
    }

    /**
     * Clear every filter control back to its default.
     */
    public function clearFilters(): void
    {
        $this->reset(['filter', 'group', 'board', 'status', 'search']);
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
     * Comments across the team's ideas, newest first. The "deleted" filter shows only
     * soft-deleted comments; the other filters exclude them, as normal.
     *
     * @return LengthAwarePaginator<int, IdeaComment>
     */
    #[Computed]
    public function comments(): LengthAwarePaginator
    {
        return IdeaComment::query()
            ->when($this->filter === 'deleted', fn ($query) => $query->onlyTrashed())
            ->whereHas('idea', function ($query) {
                $query->where('team_id', $this->team->id)
                    ->when($this->group !== '', fn ($query) => $query->where('board_group_id', $this->group))
                    ->when($this->board !== [], fn ($query) => $query->whereIn('board_id', $this->board))
                    ->when($this->status !== [], fn ($query) => $query->whereIn('status', $this->status));
            })
            ->when($this->filter === 'hidden', fn ($query) => $query->whereNotNull('hidden_at'))
            ->when(trim($this->search) !== '', fn ($query) => $query->where('body', 'like', $this->likeTerm()))
            ->with(['user:id,name', 'hiddenBy:id,name', 'idea:id,title,slug,status,board_id', 'idea.board:id,name'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(15);
    }

    /**
     * Summary stats shown above the comments table: counts by moderation state,
     * scoped to the team but ignoring the toolbar filters.
     *
     * @return array{total: int, underReview: int, flagged: int, deleted: int}
     */
    #[Computed]
    public function stats(): array
    {
        $teamComments = fn () => IdeaComment::query()
            ->whereHas('idea', fn ($query) => $query->where('team_id', $this->team->id));

        return [
            'total' => $teamComments()->withTrashed()->count(),
            'underReview' => $teamComments()->whereNull('hidden_at')->count(),
            'flagged' => $teamComments()->whereNotNull('hidden_at')->count(),
            'deleted' => $teamComments()->onlyTrashed()->count(),
        ];
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

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" data-test="stat-total">
            <flux:text class="text-sm text-slate-600 dark:text-slate-500">{{ __('All comments') }}</flux:text>
            <div class="mt-1 text-3xl font-bold text-slate-900 dark:text-white">{{ $this->stats['total'] }}</div>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" data-test="stat-under-review">
            <flux:text class="text-sm text-slate-600 dark:text-slate-500">{{ __('Under review') }}</flux:text>
            <div class="mt-1 text-3xl font-bold text-slate-900 dark:text-white">{{ $this->stats['underReview'] }}</div>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" data-test="stat-flagged">
            <flux:text class="text-sm text-slate-600 dark:text-slate-500">{{ __('Flagged') }}</flux:text>
            <div class="mt-1 text-3xl font-bold text-red-600 dark:text-red-400">{{ $this->stats['flagged'] }}</div>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" data-test="stat-deleted">
            <flux:text class="text-sm text-slate-600 dark:text-slate-500">{{ __('Deleted') }}</flux:text>
            <div class="mt-1 text-3xl font-bold text-slate-900 dark:text-white">{{ $this->stats['deleted'] }}</div>
        </div>
    </div>

    <x-sticky-toolbar class="mt-6 flex flex-wrap items-center gap-2 py-3">
        <flux:radio.group wire:model.live="filter" variant="segmented" size="sm">
            <flux:radio value="all">{{ __('All') }}</flux:radio>
            <flux:radio value="hidden">{{ __('Flagged') }}</flux:radio>
            <flux:radio value="deleted">{{ __('Deleted') }}</flux:radio>
        </flux:radio.group>

        <flux:input
            wire:model.live.debounce.300ms="search"
            icon="magnifying-glass"
            size="sm"
            clearable
            placeholder="{{ __('Search comments') }}"
            data-test="filter-search"
            @class([
                'w-full sm:w-64 md:w-80',
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

        @if ($this->hasActiveFilters)
            <flux:button
                wire:click="clearFilters"
                variant="outline"
                size="sm"
                icon="x-mark"
                class="border-red-500! text-red-500! hover:bg-red-50! dark:hover:bg-red-500/10!"
                data-test="clear-filters"
            >
                {{ __('Clear') }}
            </flux:button>
        @endif
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
                            <div class="mt-1 flex items-center gap-1 text-xs text-slate-500">
                                <flux:icon.clock class="size-3.5" />
                                {{ $comment->created_at->format('M d, Y h:i A') }}
                            </div>
                        </flux:table.cell>

                        <flux:table.cell>
                            <a href="{{ route('ideas.show', ['idea' => $comment->idea->slug]) }}" wire:navigate class="text-sm hover:underline">
                                {{ $comment->idea->title }}
                            </a>
                            @php($ideaMeta = $this->statusMeta($comment->idea->status))
                            <div class="mt-1 flex flex-wrap items-center gap-1">
                                <flux:badge :color="$ideaMeta['color']" size="sm" class="{{ $ideaMeta['class'] ?? '' }}">
                                    <span class="me-1 inline-block size-1.5 rounded-full {{ $ideaMeta['badge_dot'] }}"></span>{{ $ideaMeta['label'] }}
                                </flux:badge>
                                @if ($comment->idea->board)
                                    <flux:badge color="zinc" size="sm" variant="outline" icon="chalkboard">{{ $comment->idea->board->name }}</flux:badge>
                                @endif
                            </div>
                        </flux:table.cell>

                        <flux:table.cell class="text-slate-700 dark:text-slate-400">
                            {{ $comment->user?->name ?? __('Unknown') }}
                        </flux:table.cell>

                        <flux:table.cell>
                            @if ($comment->trashed())
                                <flux:badge size="sm" icon="trash" class="bg-red-100! text-red-500! dark:bg-red-950! dark:text-red-500!">{{ __('Deleted') }}</flux:badge>
                            @elseif ($comment->isHidden())
                                <flux:badge color="red" size="sm" icon="flag">{{ __('Flagged') }}</flux:badge>
                                @if ($comment->hiddenBy)
                                    <div class="mt-1 text-xs text-slate-500">{{ __('by :name', ['name' => $comment->hiddenBy->name]) }}</div>
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
                                        icon:trailing="chevron-down"
                                        icon-trailing:class="transition-transform duration-200 group-data-open:rotate-180"
                                        class="group"
                                        :square="false"
                                        data-test="deleted-comment-actions-trigger"
                                    />

                                    <flux:menu>
                                        <flux:menu.item
                                            wire:click="restoreComment({{ $comment->id }})"
                                            icon="arrow-uturn-left"
                                            data-test="restore-comment"
                                        >
                                            {{ __('Restore') }}
                                        </flux:menu.item>

                                        @if ($this->canPermanentlyDelete)
                                            <flux:modal.trigger name="confirm-permanent-delete-{{ $comment->id }}">
                                                <flux:menu.item
                                                    icon="trash"
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
                                        icon:trailing="chevron-down"
                                        icon-trailing:class="transition-transform duration-200 group-data-open:rotate-180"
                                        class="group"
                                        :square="false"
                                        data-test="comment-actions-trigger"
                                    />

                                    <flux:menu>
                                        @if ($comment->isHidden())
                                            <flux:menu.item
                                                wire:click="unhideComment({{ $comment->id }})"
                                                icon="flag-slash"
                                                class="text-red-600! hover:text-red-700! dark:text-red-400! dark:hover:text-red-300! data-flux-menu-item-icon:text-red-600! dark:data-flux-menu-item-icon:text-red-400!"
                                                data-test="unhide-comment"
                                            >
                                                {{ __('Unflag') }}
                                            </flux:menu.item>
                                        @else
                                            <flux:menu.item
                                                wire:click="hideComment({{ $comment->id }})"
                                                icon="flag"
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
                                <flux:text class="mt-1 flex items-center gap-1 text-xs text-slate-500">
                                    {{ $comment->user?->name ?? __('Unknown') }}
                                    <span aria-hidden="true">·</span>
                                    <flux:icon.clock class="size-3.5" />
                                    {{ $comment->created_at->format('M d, Y h:i A') }}
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
                                        data-test="restore-comment-modal"
                                    >
                                        {{ __('Restore') }}
                                    </flux:button>

                                    @if ($this->canPermanentlyDelete)
                                        <flux:modal.trigger name="confirm-permanent-delete-{{ $comment->id }}">
                                            <flux:button variant="danger" size="sm" icon="trash" data-test="permanently-delete-comment-modal">
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
