<?php

use App\Enums\TeamRole;
use App\Models\IdeaComment;
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
     * Summary stat cards for the current team.
     *
     * @return array<int, array{label: string, value: int, dot: string}>
     */
    #[Computed]
    public function stats(): array
    {
        $team = $this->team;

        return [
            ['label' => __('Total ideas'), 'value' => $team->ideas()->count(), 'dot' => 'bg-indigo-500'],
            ['label' => __('New'), 'value' => $team->ideas()->where('status', 'new')->count(), 'dot' => 'bg-zinc-400'],
            ['label' => __('Under review'), 'value' => $team->ideas()->where('status', 'under_review')->count(), 'dot' => 'bg-amber-500'],
            ['label' => __('Planned / In progress'), 'value' => $team->ideas()->whereIn('status', ['planned', 'in_progress'])->count(), 'dot' => 'bg-blue-500'],
            ['label' => __('Released'), 'value' => $team->ideas()->where('status', 'released')->count(), 'dot' => 'bg-green-500'],
            ['label' => __('Total votes'), 'value' => IdeaVote::whereHas('idea', fn ($query) => $query->where('team_id', $team->id))->count(), 'dot' => 'bg-indigo-500'],
            ['label' => __('Total comments'), 'value' => IdeaComment::whereHas('idea', fn ($query) => $query->where('team_id', $team->id))->count(), 'dot' => 'bg-zinc-400'],
        ];
    }

    /**
     * Latest ideas awaiting triage (new / under review).
     *
     * @return Collection<int, \App\Models\Idea>
     */
    #[Computed]
    public function needsReview(): Collection
    {
        return $this->team->ideas()
            ->whereIn('status', ['new', 'under_review'])
            ->withCount(['votes', 'comments'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get();
    }

    /**
     * Top ideas by vote count.
     *
     * @return Collection<int, \App\Models\Idea>
     */
    #[Computed]
    public function topVoted(): Collection
    {
        return $this->team->ideas()
            ->withCount('votes')
            ->orderByDesc('votes_count')
            ->orderByDesc('id')
            ->limit(5)
            ->get();
    }

    /**
     * Most recently updated ideas.
     *
     * @return Collection<int, \App\Models\Idea>
     */
    #[Computed]
    public function recentlyUpdated(): Collection
    {
        return $this->team->ideas()
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get();
    }

    /**
     * @return array{label: string, color: string}
     */
    public function statusMeta(string $status): array
    {
        return self::STATUS_META[$status] ?? ['label' => str($status)->headline()->value(), 'color' => 'zinc'];
    }
}; ?>

<section class="mx-auto w-full max-w-[1080px] px-6 py-7 lg:px-8">
    <livewire:pages::teams.pending-invitations-modal />

    {{-- Header + quick actions --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="flex flex-col gap-1">
            <flux:heading size="xl">{{ __('Welcome back, :name', ['name' => str(auth()->user()->name)->before(' ')]) }}</flux:heading>
            <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __(':team idea portal', ['team' => $this->team->name]) }}</flux:text>
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
    <div class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
        @foreach ($this->stats as $stat)
            <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" wire:key="stat-{{ $loop->index }}">
                <div class="flex items-center gap-2">
                    <span class="size-2.5 rounded-full {{ $stat['dot'] }}"></span>
                    <span class="text-xs font-semibold text-zinc-500 dark:text-zinc-400">{{ $stat['label'] }}</span>
                </div>
                <div class="mt-2 text-3xl font-extrabold tracking-tight text-zinc-900 dark:text-zinc-100">{{ $stat['value'] }}</div>
            </div>
        @endforeach
    </div>

    {{-- Needs review + Top voted --}}
    <div class="mt-6 grid grid-cols-1 gap-5 lg:grid-cols-2">
        {{-- Needs review --}}
        <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-center justify-between">
                <flux:heading size="sm">{{ __('Needs review') }}</flux:heading>
                @if ($this->canReview)
                    <flux:link :href="route('ideas.review')" wire:navigate variant="subtle" class="text-sm">{{ __('View queue') }}</flux:link>
                @endif
            </div>

            <div class="mt-3 space-y-2">
                @forelse ($this->needsReview as $idea)
                    @php($meta = $this->statusMeta($idea->status))
                    <a href="{{ route('ideas.show', ['idea' => $idea->slug]) }}" wire:navigate class="flex items-center justify-between gap-3 rounded-lg border border-zinc-100 p-3 transition hover:border-indigo-200 dark:border-zinc-800 dark:hover:border-indigo-900/60" wire:key="review-{{ $idea->id }}">
                        <div class="min-w-0">
                            <div class="truncate text-sm font-medium text-zinc-800 dark:text-zinc-100">{{ $idea->title }}</div>
                            <div class="mt-0.5 text-xs text-zinc-400">{{ trans_choice(':count vote|:count votes', $idea->votes_count, ['count' => $idea->votes_count]) }} · {{ trans_choice(':count comment|:count comments', $idea->comments_count, ['count' => $idea->comments_count]) }}</div>
                        </div>
                        <flux:badge :color="$meta['color']" size="sm">{{ $meta['label'] }}</flux:badge>
                    </a>
                @empty
                    <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Nothing awaiting review. 🎉') }}</flux:text>
                @endforelse
            </div>
        </div>

        {{-- Top voted --}}
        <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-center justify-between">
                <flux:heading size="sm">{{ __('Top voted ideas') }}</flux:heading>
                <flux:link :href="route('ideas.index', ['sort' => 'top'])" wire:navigate variant="subtle" class="text-sm">{{ __('View all') }}</flux:link>
            </div>

            <div class="mt-3 space-y-2">
                @forelse ($this->topVoted as $idea)
                    <a href="{{ route('ideas.show', ['idea' => $idea->slug]) }}" wire:navigate class="flex items-center justify-between gap-3 rounded-lg border border-zinc-100 p-3 transition hover:border-indigo-200 dark:border-zinc-800 dark:hover:border-indigo-900/60" wire:key="top-{{ $idea->id }}">
                        <div class="truncate text-sm font-medium text-zinc-800 dark:text-zinc-100">{{ $idea->title }}</div>
                        <div class="flex shrink-0 items-center gap-1 text-sm font-semibold text-indigo-600 dark:text-indigo-400">
                            <flux:icon.chevron-up class="size-4" />
                            {{ $idea->votes_count }}
                        </div>
                    </a>
                @empty
                    <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('No ideas yet.') }}</flux:text>
                @endforelse
            </div>
        </div>
    </div>

    {{-- Recently updated --}}
    <div class="mt-5 rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:heading size="sm">{{ __('Recently updated') }}</flux:heading>

        <div class="mt-3 space-y-2">
            @forelse ($this->recentlyUpdated as $idea)
                @php($meta = $this->statusMeta($idea->status))
                <a href="{{ route('ideas.show', ['idea' => $idea->slug]) }}" wire:navigate class="flex items-center justify-between gap-3 rounded-lg border border-zinc-100 p-3 transition hover:border-indigo-200 dark:border-zinc-800 dark:hover:border-indigo-900/60" wire:key="recent-{{ $idea->id }}">
                    <div class="min-w-0 flex items-center gap-2">
                        <flux:badge :color="$meta['color']" size="sm">{{ $meta['label'] }}</flux:badge>
                        <span class="truncate text-sm font-medium text-zinc-800 dark:text-zinc-100">{{ $idea->title }}</span>
                    </div>
                    <span class="shrink-0 text-xs text-zinc-400">{{ $idea->updated_at->diffForHumans() }}</span>
                </a>
            @empty
                <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('No ideas yet.') }}</flux:text>
            @endforelse
        </div>
    </div>
</section>
