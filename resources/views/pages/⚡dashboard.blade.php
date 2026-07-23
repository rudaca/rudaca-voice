<?php

use App\Enums\TeamRole;
use App\Models\IdeaVote;
use App\Models\Team;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component {
    /**
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
        } else {
            IdeaVote::firstOrCreate([
                'idea_id' => $idea->id,
                'user_id' => Auth::id(),
            ]);
        }
    }

    /**
     * Compact summary stat cards for the current user + team.
     *
     * @return array<int, array{label: string, value: int, caption: string, dot: string}>
     */
    #[Computed]
    public function stats(): array
    {
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
     * Trending ideas for the current team, ranked by votes + comments weight.
     *
     * @return Collection<int, \App\Models\Idea>
     */
    #[Computed]
    public function trending(): Collection
    {
        return $this->team->ideas()
            ->visibleTo($this->role, Auth::id())
            ->with('board:id,name')
            ->withCount(['votes', 'comments'])
            ->withExists(['votes as voted' => fn ($query) => $query->where('user_id', Auth::id())])
            ->orderByRaw('(votes_count + comments_count * 3) desc')
            ->orderByDesc('id')
            ->limit(5)
            ->get();
    }

    /**
     * Active boards for the current team with their idea counts.
     *
     * @return Collection<int, \App\Models\IdeaBoard>
     */
    #[Computed]
    public function boards(): Collection
    {
        return $this->team->boards()
            ->where('is_active', true)
            ->withCount(['ideas' => fn ($query) => $query->visibleTo($this->role, Auth::id())])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);
    }

    /**
     * @return array{label: string, color: string, class?: string}
     */
    public function statusMeta(string $status): array
    {
        return self::STATUS_META[$status] ?? ['label' => str($status)->headline()->value(), 'color' => 'zinc'];
    }
}; ?>

<div class="min-h-full dark:bg-zinc-950">
    <div class="mx-auto w-full px-6 py-8 lg:px-8">
        <livewire:pages::teams.pending-invitations-modal />

        {{-- Header --}}
        <div>
            <flux:heading class="text-xl">{{ $this->heading }}</flux:heading>
            <flux:text class="text-sm text-slate-900 dark:text-slate-500">{{ __('Welcome back') }} <strong>{{  Auth::user()->name }}!</strong></flux:text>

        </div>

        {{-- Stat cards --}}
        <div class="mt-6 grid grid-cols-1 gap-3.5 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($this->stats as $stat)
                <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-xs dark:border-zinc-700 dark:bg-zinc-900" wire:key="stat-{{ $loop->index }}">
                    <div class="flex items-center gap-2">
                        <span class="size-2.5 rounded-full {{ $stat['dot'] }}"></span>
                        <span class="text-xs font-semibold text-slate-600 dark:text-slate-500">{{ $stat['label'] }}</span>
                    </div>
                    <div class="mt-2 text-3xl font-extrabold tracking-tight text-slate-900 tabular-nums dark:text-slate-200">{{ $stat['value'] }}</div>
                    <div class="mt-1 text-xs text-slate-500 dark:text-slate-600">{{ $stat['caption'] }}</div>
                </div>
            @endforeach
        </div>

        {{-- Trending (left) + Boards (right) --}}
        <div class="mt-8 grid grid-cols-1 gap-6 lg:grid-cols-[1.6fr_1fr]">
            {{-- Trending ideas --}}
            <div>
                <div class="mb-3 flex items-center justify-between">
                    <flux:heading size="lg">{{ __('Trending ideas') }}</flux:heading>
                    <flux:link :href="route('ideas.index', ['sort' => 'top'])" wire:navigate variant="subtle" class="text-sm">{{ __('View all →') }}</flux:link>
                </div>

                <div class="space-y-3">
                    @forelse ($this->trending as $idea)
                        @php($meta = $this->statusMeta($idea->status))
                        <div
                            class="flex items-start gap-4 rounded-xl border border-zinc-200 bg-white p-4 shadow-xs transition hover:border-indigo-200 hover:shadow-sm dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-indigo-900/60"
                            wire:key="trending-{{ $idea->id }}"
                        >
                            <flux:tooltip :content="$this->canParticipate ? ($idea->voted ? __('You voted this idea..') : __('Click to vote for this idea..')) : __('Viewers have read-only access.')">
                                <button
                                    type="button"
                                    wire:click="toggleVote({{ $idea->id }})"
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
                                >
                                    <flux:icon.chevron-up class="size-4" />
                                    <span class="text-sm font-extrabold">{{ $idea->votes_count }}</span>
                                </button>
                            </flux:tooltip>

                            <a
                                href="{{ route('ideas.show', ['idea' => $idea->slug]) }}"
                                wire:navigate
                                class="min-w-0 flex-1"
                            >
                                <div class="truncate font-semibold text-slate-900 dark:text-slate-200">{{ $idea->title }}</div>
                                <div class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-600 dark:text-slate-500">
                                    <flux:badge :color="$meta['color']" size="sm" class="{{ $meta['class'] ?? '' }}">{{ $meta['label'] }}</flux:badge>
                                    @if ($idea->board)
                                        <span>{{ $idea->board->name }}</span>
                                    @endif
                                    <span aria-hidden="true">·</span>
                                    <span>{{ trans_choice(':count comment|:count comments', $idea->comments_count, ['count' => $idea->comments_count]) }}</span>
                                </div>
                            </a>
                        </div>
                    @empty
                        <div class="rounded-xl border border-dashed border-zinc-300 py-12 text-center dark:border-zinc-700">
                            <flux:icon.light-bulb class="mx-auto size-8 text-slate-400 dark:text-slate-700" />
                            <flux:text class="mt-2 text-sm text-slate-600 dark:text-slate-500">{{ __('No ideas yet — be the first to submit one.') }}</flux:text>
                        </div>
                    @endforelse
                </div>
            </div>

            {{-- Boards --}}
            <div>
                <flux:heading size="lg" class="mb-3">{{ __('Boards') }}</flux:heading>

                <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-700 dark:bg-zinc-900">
                    @forelse ($this->boards as $board)
                        <a
                            href="{{ route('ideas.index', ['board' => $board->id]) }}"
                            wire:navigate
                            class="flex items-center gap-3 border-b border-zinc-100 px-4 py-3 transition last:border-b-0 hover:bg-zinc-50 dark:border-zinc-800 dark:hover:bg-zinc-800/40"
                            wire:key="board-{{ $board->id }}"
                        >
                            <x-board-avatar :name="$board->name" :index="$loop->index" />
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-sm font-semibold text-slate-900 dark:text-slate-200">{{ $board->name }}</div>
                                <div class="text-xs text-slate-500 dark:text-slate-600">{{ trans_choice(':count idea|:count ideas', $board->ideas_count, ['count' => $board->ideas_count]) }}</div>
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
            </div>
        </div>
    </div>
</div>
