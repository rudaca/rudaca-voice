<?php

use App\Enums\TeamRole;
use App\Models\IdeaComment;
use App\Models\IdeaStatusHistory;
use App\Models\IdeaVote;
use App\Models\Team;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component {
    /**
     * Which stat card set is showing for roles that can toggle between them.
     */
    public string $statsTab = 'for_you';

    /**
     * Which panel is showing in the boards/contributors card.
     */
    public string $boardsTab = 'boards';

    /**
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

    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    /**
     * Whether the current user may see private ideas (Manager and above).
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
     * Whether the current user can switch between the "For You" and "By Status"
     * stat card sets. Viewers only ever see the status breakdown.
     */
    #[Computed]
    public function canToggleStatsTab(): bool
    {
        return $this->role !== TeamRole::Viewer;
    }

    /**
     * The page heading, tailored to the current user's team role.
     */
    #[Computed]
    public function heading(): string
    {
        return match (true) {
            $this->role?->isAtLeast(TeamRole::Admin) => __('Organization Overview'),
            $this->role === TeamRole::Manager => __('Organization Ideas Overview'),
            default => __('Your Ideas Hub'),
        };
    }

    /**
     * Toggle the current user's vote on an idea belonging to their current team.
     */
    public function toggleVote(int $ideaId): void
    {
        abort_unless($this->canParticipate, 403);

        $idea = $this->team->ideas()
            ->visibleTo($this->role, Auth::id())
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

    /**
     * Compact summary stat cards for the current user + team.
     *
     * @return array<int, array{label: string, value: int, caption: string, dot: string, caption_bold?: bool}>
     */
    #[Computed]
    public function stats(): array
    {
        if (! $this->canToggleStatsTab || $this->statsTab === 'by_status') {
            return $this->byStatusStats();
        }

        return $this->forYouStats();
    }

    /**
     * Participation-focused stat cards: what the current user has submitted
     * and voted on, plus team-wide progress.
     *
     * @return array<int, array{label: string, value: int, caption: string, dot: string, caption_bold?: bool}>
     */
    private function forYouStats(): array
    {
        if ($this->role?->isAtLeast(TeamRole::Admin)) {
            return $this->adminStats();
        }

        if ($this->role === TeamRole::Manager) {
            return $this->managerStats();
        }

        $team = $this->team;
        $userId = Auth::id();

        return [
            [
                'label' => __('Your ideas'),
                'value' => $team->ideas()->where('submitted_by_user_id', $userId)->count(),
                'caption' => __('submitted by you'),
                'dot' => 'bg-indigo-500',
            ],
            [
                'label' => __('Votes cast'),
                'value' => IdeaVote::where('user_id', $userId)
                    ->whereHas('idea', fn ($query) => $query->where('team_id', $team->id))
                    ->count(),
                'caption' => __('ideas you backed'),
                'dot' => 'bg-teal-500',
            ],
            [
                'label' => __('In progress'),
                'value' => $team->ideas()->visibleTo($this->role, $userId)->where('status', 'in_progress')->count(),
                'caption' => __('moving forward'),
                'dot' => 'bg-violet-500',
            ],
            [
                'label' => __('Implemented'),
                'value' => $team->ideas()->visibleTo($this->role, $userId)->where('status', 'released')->count(),
                'caption' => __('shipped org-wide'),
                'dot' => 'bg-emerald-500',
            ],
        ];
    }

    /**
     * Team-oriented "For You" cards for Managers: the review queue awaiting
     * their decision, plus how the team's ideas are progressing overall.
     *
     * @return array<int, array{label: string, value: int, caption: string, dot: string, caption_bold?: bool}>
     */
    private function managerStats(): array
    {
        $team = $this->team;

        return [
            [
                'label' => __('Awaiting review'),
                'value' => $team->ideas()->whereIn('status', ['new', 'under_review'])->count(),
                'caption' => __('need a decision'),
                'dot' => 'bg-amber-500',
            ],
            [
                'label' => __('In progress'),
                'value' => $team->ideas()->where('status', 'in_progress')->count(),
                'caption' => __('being delivered'),
                'dot' => 'bg-violet-500',
            ],
            [
                'label' => __('Implemented'),
                'value' => IdeaStatusHistory::whereHas('idea', fn ($query) => $query->where('team_id', $team->id))
                    ->where('new_status', 'released')
                    ->where('created_at', '>=', now()->startOfQuarter())
                    ->count(),
                'caption' => __('this quarter'),
                'dot' => 'bg-emerald-500',
            ],
            [
                'label' => __('Total ideas'),
                'value' => $team->ideas()->count(),
                'caption' => __('across all boards'),
                'dot' => 'bg-slate-500',
            ],
        ];
    }

    /**
     * Organization-wide "For You" cards for Admins and Owners: headline
     * counts across the whole team, rather than personal participation.
     *
     * @return array<int, array{label: string, value: int, caption: string, dot: string, caption_bold?: bool}>
     */
    private function adminStats(): array
    {
        $team = $this->team;

        return [
            [
                'label' => __('Total ideas'),
                'value' => $team->ideas()->count(),
                'caption' => __('all time'),
                'dot' => 'bg-indigo-500',
            ],
            [
                'label' => __('Contributors'),
                'value' => $team->memberships()->count(),
                'caption' => __('in :team', ['team' => $team->name]),
                'dot' => 'bg-rose-500',
            ],
            [
                'label' => __('Awaiting review'),
                'value' => $team->ideas()->whereIn('status', ['new', 'under_review'])->count(),
                'caption' => __('need a decision'),
                'dot' => 'bg-amber-500',
            ],
            [
                'label' => __('Implemented'),
                'value' => $team->ideas()->where('status', 'released')->count(),
                'caption' => __('shipped'),
                'dot' => 'bg-emerald-500',
            ],
        ];
    }

    /**
     * Status-grouped stat cards: a team-wide total plus a breakdown across
     * the pipeline, active-work, and closed-out statuses. The only view
     * Viewers see; also available to other roles via the "By Status" tab.
     *
     * @return array<int, array{label: string, value: int, caption: string, dot: string, caption_bold?: bool}>
     */
    private function byStatusStats(): array
    {
        $counts = $this->team->ideas()
            ->visibleTo($this->role, Auth::id())
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $groupTotal = fn (array $statuses) => collect($statuses)->sum(fn ($status) => $counts->get($status, 0));

        $groupBreakdown = fn (array $statuses) => collect($statuses)
            ->map(fn ($status) => $this->statusMeta($status)['label'].' '.$counts->get($status, 0))
            ->implode(' · ');

        return [
            [
                'label' => __('Total ideas'),
                'value' => $counts->sum(),
                'caption' => __('Total for :team', ['team' => $this->team->name]),
                'dot' => 'bg-slate-500',
            ],
            [
                'label' => __('In the pipeline'),
                'value' => $groupTotal(['new', 'under_review', 'planned']),
                'caption' => $groupBreakdown(['new', 'under_review', 'planned']),
                'caption_bold' => true,
                'dot' => 'bg-indigo-500',
            ],
            [
                'label' => __('Active work'),
                'value' => $groupTotal(['in_progress']),
                'caption' => $groupBreakdown(['in_progress']),
                'caption_bold' => true,
                'dot' => 'bg-emerald-500',
            ],
            [
                'label' => __('Closed out'),
                'value' => $groupTotal(['released', 'not_doing', 'duplicate']),
                'caption' => $groupBreakdown(['released', 'not_doing', 'duplicate']),
                'caption_bold' => true,
                'dot' => 'bg-rose-500',
            ],
        ];
    }

    /**
     * Trending ideas for the current team, ranked by votes + comments weight.
     *
     * @return Collection<int, \App\Models\Idea>
     */
    #[Computed]
    public function trending(): Collection
    {
        return $this->team->ideas()
            ->visibleTo($this->role, Auth::id())
            ->with(['board:id,name', 'submittedBy:id,name'])
            ->withCount([
                'votes',
                'comments',
                'comments as internal_comments_count' => fn ($query) => $query->where('is_internal', true)->whereNull('hidden_at'),
            ])
            ->withExists(['votes as voted' => fn ($query) => $query->where('user_id', Auth::id())])
            ->orderByRaw('(votes_count + comments_count * 3) desc')
            ->orderByDesc('id')
            ->limit(10)
            ->get();
    }

    /**
     * The highest-voted ideas awaiting a review decision. Shown to Managers
     * in place of "Trending ideas", since it's their queue to work through.
     *
     * @return Collection<int, \App\Models\Idea>
     */
    #[Computed]
    public function queueTop(): Collection
    {
        return $this->team->ideas()
            ->whereIn('status', ['new', 'under_review'])
            ->with(['board:id,name', 'submittedBy:id,name'])
            ->withCount([
                'votes',
                'comments',
                'comments as internal_comments_count' => fn ($query) => $query->where('is_internal', true)->whereNull('hidden_at'),
            ])
            ->withExists(['votes as voted' => fn ($query) => $query->where('user_id', Auth::id())])
            ->orderByDesc('votes_count')
            ->orderByDesc('id')
            ->limit(10)
            ->get();
    }

    /**
     * The team's highest-voted ideas across every status. Shown to Admins
     * and Owners in place of "Trending ideas", as an org-wide pulse check.
     *
     * @return Collection<int, \App\Models\Idea>
     */
    #[Computed]
    public function highestVoted(): Collection
    {
        return $this->team->ideas()
            ->with(['board:id,name', 'submittedBy:id,name'])
            ->withCount([
                'votes',
                'comments',
                'comments as internal_comments_count' => fn ($query) => $query->where('is_internal', true)->whereNull('hidden_at'),
            ])
            ->withExists(['votes as voted' => fn ($query) => $query->where('user_id', Auth::id())])
            ->orderByDesc('votes_count')
            ->orderByDesc('id')
            ->limit(10)
            ->get();
    }

    /**
     * Active boards for the current team with their idea counts, ranked by
     * idea count (ties broken alphabetically). Shown in the "Top Boards" tab.
     *
     * @return Collection<int, \App\Models\IdeaBoard>
     */
    #[Computed]
    public function topBoards(): Collection
    {
        $team = $this->team;
        $role = $this->role;
        $userId = Auth::id();

        $boards = $team->boards()
            ->where('is_active', true)
            ->withCount([
                'ideas' => fn ($query) => $query->visibleTo($role, $userId),
                'comments',
            ])
            ->orderByDesc('ideas_count')
            ->orderBy('name')
            ->limit(10)
            ->get(['id', 'name', 'slug']);

        $ideaAuthorsByBoard = $team->ideas()
            ->visibleTo($role, $userId)
            ->whereNotNull('submitted_by_user_id')
            ->select('board_id', 'submitted_by_user_id')
            ->get()
            ->groupBy('board_id')
            ->map(fn ($ideas) => $ideas->pluck('submitted_by_user_id'));

        $commentersByBoard = IdeaComment::whereHas('idea', fn ($query) => $query->where('team_id', $team->id)->visibleTo($role, $userId))
            ->with('idea:id,board_id')
            ->select('idea_id', 'user_id')
            ->get()
            ->groupBy(fn ($comment) => $comment->idea->board_id)
            ->map(fn ($comments) => $comments->pluck('user_id'));

        $boards->each(function ($board) use ($ideaAuthorsByBoard, $commentersByBoard) {
            $board->contributors_count = $ideaAuthorsByBoard->get($board->id, collect())
                ->merge($commentersByBoard->get($board->id, collect()))
                ->unique()
                ->count();
        });

        return $boards;
    }

    /**
     * Team members ranked by contribution — ideas submitted, then comments
     * (ties broken alphabetically) — with a breakdown of boards participated
     * in, ideas submitted, and comments made. Shown in the "Top Contributors" tab.
     *
     * @return SupportCollection<int, array{user: \App\Models\User, role: TeamRole, boards: int, ideas: int, comments: int}>
     */
    #[Computed]
    public function topContributors(): SupportCollection
    {
        $team = $this->team;
        $role = $this->role;
        $userId = Auth::id();

        $ideaCounts = $team->ideas()
            ->visibleTo($role, $userId)
            ->selectRaw('submitted_by_user_id, count(*) as aggregate')
            ->groupBy('submitted_by_user_id')
            ->pluck('aggregate', 'submitted_by_user_id');

        $commentCounts = IdeaComment::whereHas('idea', fn ($query) => $query->where('team_id', $team->id)->visibleTo($role, $userId))
            ->selectRaw('user_id, count(*) as aggregate')
            ->groupBy('user_id')
            ->pluck('aggregate', 'user_id');

        $boardCounts = $team->ideas()
            ->visibleTo($role, $userId)
            ->select('submitted_by_user_id', 'board_id')
            ->distinct()
            ->get()
            ->groupBy('submitted_by_user_id')
            ->map->count();

        return $team->members()
            ->get(['users.id', 'users.name'])
            ->map(fn ($member) => [
                'user' => $member,
                'role' => $member->pivot->role,
                'boards' => $boardCounts->get($member->id, 0),
                'ideas' => $ideaCounts->get($member->id, 0),
                'comments' => $commentCounts->get($member->id, 0),
            ])
            ->filter(fn ($contributor) => $contributor['boards'] > 0 || $contributor['ideas'] > 0 || $contributor['comments'] > 0)
            ->sort(fn ($a, $b) => ($b['ideas'] <=> $a['ideas'])
                ?: ($b['comments'] <=> $a['comments'])
                ?: ($a['user']->name <=> $b['user']->name))
            ->values()
            ->take(10);
    }

    /**
     * @return array{label: string, color: string, class?: string}
     */
    public function statusMeta(string $status): array
    {
        return self::STATUS_META[$status] ?? ['label' => str($status)->headline()->value(), 'color' => 'zinc'];
    }
}; ?>

<div class="min-h-full dark:bg-zinc-800">
    <div class="mx-auto w-full px-6 py-8 lg:px-8">
        <livewire:pages::teams.pending-invitations-modal />

        {{-- Header --}}
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <flux:heading class="text-xl">{{ $this->heading }}</flux:heading>
                <flux:text class="text-sm text-slate-900 dark:text-slate-500">{{ __('Welcome back') }} <strong>{{  Auth::user()->name }}!</strong></flux:text>
            </div>

            @if ($this->canToggleStatsTab)
                <flux:radio.group wire:model.live="statsTab" variant="segmented" size="sm">
                    <flux:radio value="for_you">{{ __('For You') }}</flux:radio>
                    <flux:radio value="by_status">{{ __('By Status') }}</flux:radio>
                </flux:radio.group>
            @endif
        </div>

        {{-- Stat cards --}}
        <div class="mt-6 grid grid-cols-1 gap-3.5 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($this->stats as $stat)
                <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-xs dark:border-zinc-700 dark:bg-zinc-900" wire:key="stat-{{ $statsTab }}-{{ $loop->index }}">
                    <div class="flex items-center gap-2">
                        <span class="size-2.5 rounded-full {{ $stat['dot'] }}"></span>
                        <span class="text-xs font-semibold text-slate-600 dark:text-slate-500">{{ $stat['label'] }}</span>
                    </div>
                    <div
                        class="mt-2 text-3xl font-extrabold tracking-tight text-slate-900 tabular-nums dark:text-slate-200"
                        x-data
                        x-init="initStatCounter($el, {{ (int) $stat['value'] }})"
                        wire:ignore
                    >0</div>
                    <div class="mt-1 text-xs text-slate-500 dark:text-slate-600 {{ ($stat['caption_bold'] ?? false) ? 'font-bold' : '' }}">{{ $stat['caption'] }}</div>
                </div>
            @endforeach
        </div>

        {{-- Trending (left) + Boards (right) --}}
        <div class="mt-8 grid grid-cols-1 gap-6 lg:grid-cols-[1.6fr_1fr]">
            {{-- Trending ideas / Top of the queue / Highest voted --}}
            @php($panelMode = match (true) {
                $this->role?->isAtLeast(TeamRole::Admin) => 'admin',
                $this->role === TeamRole::Manager => 'manager',
                default => 'personal',
            })
            <div>
                <div class="mb-3 flex items-center justify-between">
                    <flux:heading size="lg">
                        {{ match ($panelMode) {
                            'admin' => __('Highest voted'),
                            'manager' => __('Top of the queue'),
                            default => __('Trending ideas'),
                        } }}
                    </flux:heading>
                    <flux:link
                        :href="$panelMode === 'manager' ? route('ideas.review') : route('ideas.index', ['sort' => 'top'])"
                        wire:navigate
                        variant="subtle"
                        class="text-sm"
                    >{{ __('View all →') }}</flux:link>
                </div>

                <div class="space-y-3">
                    @forelse (($panelMode === 'admin' ? $this->highestVoted : ($panelMode === 'manager' ? $this->queueTop : $this->trending)) as $idea)
                        @php($meta = $this->statusMeta($idea->status))
                        <div
                            class="flex cursor-pointer items-start gap-4 rounded-xl border border-zinc-200 bg-white p-4 shadow-xs transition hover:border-indigo-200 hover:bg-gray-50 hover:shadow-sm dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-indigo-900/60 dark:hover:bg-gray-800/40"
                            wire:key="trending-{{ $idea->id }}"
                        >
                            <flux:tooltip :content="$this->canParticipate ? ($idea->voted ? __('You voted this idea..') : __('Click to vote for this idea..')) : __('Viewers have read-only access.')">
                                <button
                                    type="button"
                                    @if (! $idea->voted) wire:click="toggleVote({{ $idea->id }})" @endif
                                    wire:loading.attr="disabled"
                                    @disabled(! $this->canParticipate)
                                    aria-pressed="{{ $idea->voted ? 'true' : 'false' }}"
                                    @class([
                                        'flex w-11 shrink-0 flex-col items-center justify-center gap-0.5 rounded-lg border py-1.5 transition',
                                        'cursor-not-allowed opacity-60' => ! $this->canParticipate,
                                        'cursor-pointer' => $this->canParticipate,
                                        'border-indigo-200 bg-indigo-50 text-indigo-600 dark:border-indigo-500/40 dark:bg-indigo-500/10 dark:text-indigo-300' => $idea->voted,
                                        'border-zinc-200 text-slate-500 hover:border-indigo-200 hover:text-indigo-600 dark:border-zinc-700 dark:hover:border-indigo-500/40' => ! $idea->voted,
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
                                    <span class="text-[9px] font-medium uppercase tracking-wide {{ $idea->voted ? 'text-indigo-500/80 dark:text-indigo-300/80' : 'text-slate-500' }}">{{ trans_choice('vote|votes', $idea->votes_count) }}</span>
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
                                    >
                                        <div class="w-fit max-w-full truncate font-semibold text-slate-900 hover:underline dark:text-slate-200">{{ $idea->title }}</div>
                                    </a>

                                    @if ($this->role?->isAtLeast(TeamRole::Manager) && $idea->internal_comments_count > 0)
                                        <flux:tooltip :content="trans_choice(':count internal comment|:count internal comments', $idea->internal_comments_count, ['count' => $idea->internal_comments_count])">
                                            <flux:badge size="sm" icon="exclamation-triangle" class="bg-red-100! text-red-800! dark:bg-red-950! dark:text-red-400!">{{ __('Internal Comments') }}</flux:badge>
                                        </flux:tooltip>
                                    @endif
                                </div>

                                @php($authorName = $idea->is_anonymous ? __('Anonymous') : ($idea->submittedBy?->name ?? __('Unknown')))

                                <div class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-600 dark:text-slate-500">
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
                        <div class="rounded-xl border border-dashed border-zinc-300 py-12 text-center dark:border-zinc-700">
                            <flux:icon.light-bulb class="mx-auto size-8 text-slate-400 dark:text-slate-700" />
                            <flux:text class="mt-2 text-sm text-slate-600 dark:text-slate-500">
                                {{ $panelMode === 'manager' ? __('Queue is clear — nothing needs a decision right now.') : __('No ideas yet — be the first to submit one.') }}
                            </flux:text>
                        </div>
                    @endforelse
                </div>
            </div>

            {{-- Top Boards / Top Contributor --}}
            <div>
                <div class="mb-3">
                    <flux:radio.group wire:model.live="boardsTab" variant="segmented" size="sm" class="w-fit">
                        <flux:radio value="boards">{{ __('Top Boards') }}</flux:radio>
                        <flux:radio value="contributors">{{ __('Top Contributors') }}</flux:radio>
                    </flux:radio.group>
                </div>

                @if ($boardsTab === 'boards')
                    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-700 dark:bg-zinc-900">
                        @forelse ($this->topBoards as $board)
                            <a
                                href="{{ route('ideas.index', ['board' => [$board->id]]) }}"
                                wire:navigate
                                class="flex items-center gap-3 border-b border-zinc-100 px-4 py-3 transition last:border-b-0 hover:bg-zinc-50 dark:border-zinc-800 dark:hover:bg-zinc-800/40"
                                wire:key="board-{{ $board->id }}"
                            >
                                <x-board-avatar :name="$board->name" :index="$loop->index" />
                                <div class="min-w-0 flex-1">
                                    <div class="truncate text-sm font-semibold text-slate-900 dark:text-slate-200">{{ $board->name }}</div>
                                    <div class="mt-1 flex flex-wrap items-center gap-x-1.5 gap-y-1 text-xs text-slate-500 dark:text-slate-600">
                                        <flux:tooltip :content="__('Ideas submitted')">
                                            <div class="flex items-center gap-1">
                                                <flux:icon.light-bulb class="size-3.5" />
                                                <span x-data x-init="initStatCounter($el, {{ (int) $board->ideas_count }})" wire:ignore>0</span>
                                            </div>
                                        </flux:tooltip>
                                        <span aria-hidden="true">·</span>
                                        <flux:tooltip :content="__('Total comments')">
                                            <div class="flex items-center gap-1">
                                                <flux:icon.chat-bubble-left class="size-3.5" />
                                                <span x-data x-init="initStatCounter($el, {{ (int) $board->comments_count }})" wire:ignore>0</span>
                                            </div>
                                        </flux:tooltip>
                                        <span aria-hidden="true">·</span>
                                        <flux:tooltip :content="trans_choice(':count total user contributed|:count total users contributed', $board->contributors_count, ['count' => $board->contributors_count])">
                                            <div class="flex items-center gap-1">
                                                <flux:icon.user-check class="size-3.5" />
                                                <span x-data x-init="initStatCounter($el, {{ (int) $board->contributors_count }})" wire:ignore>0</span>
                                            </div>
                                        </flux:tooltip>
                                    </div>
                                </div>
                                <flux:icon.chevron-right class="size-4 shrink-0 text-slate-400 dark:text-slate-700" />
                            </a>
                        @empty
                            <div class="px-4 py-10 text-center">
                                <flux:icon.chalkboard class="mx-auto size-8 text-slate-400 dark:text-slate-700" />
                                <flux:text class="mt-2 text-sm text-slate-600 dark:text-slate-500">{{ __('No boards yet.') }}</flux:text>
                            </div>
                        @endforelse
                    </div>
                @else
                    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-700 dark:bg-zinc-900">
                        @forelse ($this->topContributors as $contributor)
                            @php($member = $contributor['user'])
                            @php($medal = match ($loop->index) {
                                0 => ['label' => __('1st Place'), 'bg' => 'bg-gradient-to-br from-yellow-300 to-yellow-500', 'icon' => 'text-yellow-900'],
                                1 => ['label' => __('2nd Place'), 'bg' => 'bg-gradient-to-br from-slate-300 to-slate-400', 'icon' => 'text-slate-700'],
                                2 => ['label' => __('3rd Place'), 'bg' => 'bg-gradient-to-br from-orange-300 to-orange-500', 'icon' => 'text-orange-900'],
                                default => null,
                            })
                            <div
                                class="flex items-center gap-3 border-b border-zinc-100 px-4 py-3 last:border-b-0 dark:border-zinc-800"
                                wire:key="contributor-{{ $member->id }}"
                            >
                                <flux:avatar size="sm" :name="$member->name" color="auto" color:seed="{{ $member->id }}" />
                                <div class="min-w-0 flex-1">
                                    <div class="flex min-w-0 items-center gap-1.5">
                                        <span class="truncate text-sm font-semibold text-slate-900 dark:text-slate-200">{{ $member->name }}</span>
                                        <flux:badge size="sm" :color="$contributor['role']->badgeColor()">{{ $contributor['role']->label() }}</flux:badge>
                                    </div>
                                    <div class="mt-1 flex flex-wrap items-center gap-x-1.5 gap-y-1 text-xs text-slate-500 dark:text-slate-600">
                                        <flux:tooltip :content="__('Boards participated in')">
                                            <div class="flex items-center gap-1">
                                                <flux:icon.chalkboard class="size-3.5" />
                                                <span x-data x-init="initStatCounter($el, {{ (int) $contributor['boards'] }})" wire:ignore>0</span>
                                            </div>
                                        </flux:tooltip>
                                        <span aria-hidden="true">·</span>
                                        <flux:tooltip :content="__('Ideas submitted')">
                                            <div class="flex items-center gap-1">
                                                <flux:icon.light-bulb class="size-3.5" />
                                                <span x-data x-init="initStatCounter($el, {{ (int) $contributor['ideas'] }})" wire:ignore>0</span>
                                            </div>
                                        </flux:tooltip>
                                        <span aria-hidden="true">·</span>
                                        <flux:tooltip :content="__('Comments posted')">
                                            <div class="flex items-center gap-1">
                                                <flux:icon.chat-bubble-left class="size-3.5" />
                                                <span x-data x-init="initStatCounter($el, {{ (int) $contributor['comments'] }})" wire:ignore>0</span>
                                            </div>
                                        </flux:tooltip>
                                    </div>
                                </div>
                                @if ($medal)
                                    <flux:tooltip :content="$medal['label']">
                                        <span class="flex size-7 shrink-0 items-center justify-center rounded-full {{ $medal['bg'] }} shadow-sm">
                                            <flux:icon.trophy variant="outline" class="size-3.5 {{ $medal['icon'] }}" />
                                        </span>
                                    </flux:tooltip>
                                @endif
                            </div>
                        @empty
                            <div class="px-4 py-10 text-center">
                                <flux:icon.user-group class="mx-auto size-8 text-slate-400 dark:text-slate-700" />
                                <flux:text class="mt-2 text-sm text-slate-600 dark:text-slate-500">{{ __('No contributors yet.') }}</flux:text>
                            </div>
                        @endforelse
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
