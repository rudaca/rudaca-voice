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
     * @var array<string, array{label: string, color: string}>
     */
    public const STATUS_META = [
        'new' => ['label' => 'New', 'color' => 'zinc'],
        'under_review' => ['label' => 'Under Review', 'color' => 'amber'],
        'planned' => ['label' => 'Planned', 'color' => 'blue'],
        'in_progress' => ['label' => 'In Progress', 'color' => 'indigo'],
        'released' => ['label' => 'Implemented', 'color' => 'green'],
        'not_doing' => ['label' => 'Declined', 'color' => 'red'],
        'duplicate' => ['label' => 'Duplicate', 'color' => 'zinc'],
    ];

    /**
     * Soft colored tile classes for board icons, cycled by index.
     *
     * @var array<int, string>
     */
    public const BOARD_TILES = [
        'bg-indigo-50 text-indigo-600 dark:bg-indigo-500/15 dark:text-indigo-300',
        'bg-sky-50 text-sky-600 dark:bg-sky-500/15 dark:text-sky-300',
        'bg-violet-50 text-violet-600 dark:bg-violet-500/15 dark:text-violet-300',
        'bg-teal-50 text-teal-600 dark:bg-teal-500/15 dark:text-teal-300',
        'bg-amber-50 text-amber-600 dark:bg-amber-500/15 dark:text-amber-300',
        'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-300',
        'bg-pink-50 text-pink-600 dark:bg-pink-500/15 dark:text-pink-300',
        'bg-purple-50 text-purple-600 dark:bg-purple-500/15 dark:text-purple-300',
    ];

    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    /**
     * Whether the current user can access the review queue (manager and above).
     */
    #[Computed]
    public function canReview(): bool
    {
        return Auth::user()->teamRole($this->team)?->isAtLeast(TeamRole::Manager) ?? false;
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
                'value' => $team->ideas()->where('status', 'in_progress')->count(),
                'caption' => __('moving forward'),
                'dot' => 'bg-violet-500',
            ],
            [
                'label' => __('Implemented'),
                'value' => $team->ideas()->where('status', 'released')->count(),
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
            ->with('board:id,name')
            ->withCount(['votes', 'comments'])
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
            ->withCount('ideas')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);
    }

    /**
     * @return array{label: string, color: string}
     */
    public function statusMeta(string $status): array
    {
        return self::STATUS_META[$status] ?? ['label' => str($status)->headline()->value(), 'color' => 'zinc'];
    }
}; ?>

<div class="min-h-full bg-zinc-50 dark:bg-zinc-950">
    <div class="mx-auto w-full max-w-[1120px] px-6 py-8 lg:px-8">
        <livewire:pages::teams.pending-invitations-modal />

        {{-- Header --}}
        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Welcome back, :name', ['name' => str(auth()->user()->name)->before(' ')]) }}</flux:text>
                <flux:heading size="xl" class="mt-0.5 text-2xl font-extrabold tracking-tight">{{ __('Your ideas hub') }}</flux:heading>
            </div>

            <div class="flex flex-wrap gap-2">
                <flux:button :href="route('ideas.create')" wire:navigate variant="primary" icon="plus">{{ __('Submit idea') }}</flux:button>
                <flux:button :href="route('ideas.index')" wire:navigate variant="filled" icon="light-bulb">{{ __('All ideas') }}</flux:button>
                @if ($this->canReview)
                    <flux:button :href="route('ideas.review')" wire:navigate variant="filled" icon="clipboard-document-check">{{ __('Review ideas') }}</flux:button>
                @endif
            </div>
        </div>

        {{-- Stat cards --}}
        <div class="mt-6 grid grid-cols-1 gap-3.5 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($this->stats as $stat)
                <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-xs dark:border-zinc-700 dark:bg-zinc-900" wire:key="stat-{{ $loop->index }}">
                    <div class="flex items-center gap-2">
                        <span class="size-2.5 rounded-full {{ $stat['dot'] }}"></span>
                        <span class="text-xs font-semibold text-zinc-500 dark:text-zinc-400">{{ $stat['label'] }}</span>
                    </div>
                    <div class="mt-2 text-3xl font-extrabold tracking-tight text-zinc-900 tabular-nums dark:text-zinc-100">{{ $stat['value'] }}</div>
                    <div class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">{{ $stat['caption'] }}</div>
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
                        <a
                            href="{{ route('ideas.show', ['idea' => $idea->slug]) }}"
                            wire:navigate
                            class="flex items-start gap-4 rounded-xl border border-zinc-200 bg-white p-4 shadow-xs transition hover:border-indigo-200 hover:shadow-sm dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-indigo-900/60"
                            wire:key="trending-{{ $idea->id }}"
                        >
                            <div class="flex w-11 shrink-0 flex-col items-center justify-center gap-0.5 rounded-lg border border-zinc-200 py-1.5 dark:border-zinc-700">
                                <flux:icon.chevron-up class="size-4 text-zinc-400" />
                                <span class="text-sm font-bold text-zinc-800 dark:text-zinc-100">{{ $idea->votes_count }}</span>
                            </div>

                            <div class="min-w-0 flex-1">
                                <div class="truncate font-semibold text-zinc-900 dark:text-zinc-100">{{ $idea->title }}</div>
                                <div class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-zinc-500 dark:text-zinc-400">
                                    <flux:badge :color="$meta['color']" size="sm">{{ $meta['label'] }}</flux:badge>
                                    @if ($idea->board)
                                        <span>{{ $idea->board->name }}</span>
                                    @endif
                                    <span aria-hidden="true">·</span>
                                    <span>{{ trans_choice(':count comment|:count comments', $idea->comments_count, ['count' => $idea->comments_count]) }}</span>
                                </div>
                            </div>
                        </a>
                    @empty
                        <div class="rounded-xl border border-dashed border-zinc-300 py-12 text-center dark:border-zinc-700">
                            <flux:icon.light-bulb class="mx-auto size-8 text-zinc-300 dark:text-zinc-600" />
                            <flux:text class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ __('No ideas yet — be the first to submit one.') }}</flux:text>
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
                            <span class="flex size-9 shrink-0 items-center justify-center rounded-lg text-sm font-semibold {{ self::BOARD_TILES[$loop->index % count(self::BOARD_TILES)] }}">
                                {{ strtoupper(mb_substr($board->name, 0, 1)) }}
                            </span>
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-sm font-semibold text-zinc-800 dark:text-zinc-100">{{ $board->name }}</div>
                                <div class="text-xs text-zinc-400 dark:text-zinc-500">{{ trans_choice(':count idea|:count ideas', $board->ideas_count, ['count' => $board->ideas_count]) }}</div>
                            </div>
                            <flux:icon.chevron-right class="size-4 shrink-0 text-zinc-300 dark:text-zinc-600" />
                        </a>
                    @empty
                        <div class="px-4 py-10 text-center">
                            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('No boards yet.') }}</flux:text>
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>
