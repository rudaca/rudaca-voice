<?php

use App\Enums\TeamRole;
use App\Models\Idea;
use App\Models\IdeaStatusHistory;
use App\Models\Team;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * Display metadata for each queue status (label + Flux badge color).
     *
     * @var array<string, array{label: string, color: string}>
     */
    public const STATUS_META = [
        'new' => ['label' => 'New', 'color' => 'zinc'],
        'under_review' => ['label' => 'Under Review', 'color' => 'amber'],
    ];

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
     * Base query for ideas awaiting a decision in the current team.
     */
    protected function queueQuery(): Builder
    {
        return Idea::query()
            ->where('team_id', $this->team->id)
            ->whereIn('status', self::QUEUE_STATUSES);
    }

    /**
     * The review queue, highest-voted first.
     *
     * @return LengthAwarePaginator<int, Idea>
     */
    #[Computed]
    public function ideas(): LengthAwarePaginator
    {
        return $this->queueQuery()
            ->when($this->filter !== 'all', fn (Builder $query) => $query->where('status', $this->filter))
            ->with(['board:id,name', 'submittedBy:id,name'])
            ->withCount(['votes', 'comments'])
            ->orderByDesc('votes_count')
            ->orderByDesc('id')
            ->paginate(15);
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
     * @return array{label: string, color: string}
     */
    public function statusMeta(string $status): array
    {
        return self::STATUS_META[$status] ?? ['label' => str($status)->headline()->value(), 'color' => 'zinc'];
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
        <flux:text class="text-zinc-500 dark:text-zinc-400">
            {{ __('New and under-review ideas waiting on a decision. Triage the highest-voted first.') }}
        </flux:text>
    </div>

    {{-- Stats --}}
    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" data-test="stat-awaiting">
            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Awaiting review') }}</flux:text>
            <div class="mt-1 text-3xl font-bold text-zinc-900 dark:text-white">{{ $this->stats['awaiting'] }}</div>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" data-test="stat-new-this-week">
            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('New this week') }}</flux:text>
            <div class="mt-1 text-3xl font-bold text-zinc-900 dark:text-white">{{ $this->stats['newThisWeek'] }}</div>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" data-test="stat-total-votes">
            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Total votes in queue') }}</flux:text>
            <div class="mt-1 text-3xl font-bold text-zinc-900 dark:text-white">{{ $this->stats['totalVotes'] }}</div>
        </div>
    </div>

    {{-- Queue table --}}
    <x-sticky-toolbar class="mt-6 flex items-center gap-2 py-3">
        <flux:radio.group wire:model.live="filter" variant="segmented" size="sm">
            <flux:radio value="all">{{ __('All') }}</flux:radio>
            <flux:radio value="new">{{ __('New') }}</flux:radio>
            <flux:radio value="under_review">{{ __('Under Review') }}</flux:radio>
        </flux:radio.group>
    </x-sticky-toolbar>

    <div class="mt-4">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Votes') }}</flux:table.column>
                <flux:table.column>{{ __('Idea') }}</flux:table.column>
                <flux:table.column>{{ __('Board') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column>{{ __('Decision') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($this->ideas as $idea)
                    @php($meta = $this->statusMeta($idea->status))
                    <flux:table.row :key="'queue-'.$idea->id" data-test="queue-row">
                        <flux:table.cell>
                            <div class="flex w-14 flex-col items-center gap-0.5 rounded-lg border border-zinc-200 py-1.5 dark:border-zinc-700">
                                <span class="text-base font-extrabold text-zinc-800 dark:text-zinc-100">{{ $idea->votes_count }}</span>
                                <flux:icon.chevron-up class="size-3 text-zinc-400" />
                            </div>
                        </flux:table.cell>

                        <flux:table.cell>
                            <a href="{{ route('ideas.show', ['idea' => $idea->slug]) }}" wire:navigate class="hover:underline">
                                <flux:heading size="sm">{{ $idea->title }}</flux:heading>
                            </a>
                            <div class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                                {{ $idea->is_anonymous ? __('Anonymous') : ($idea->submittedBy?->name ?? __('Unknown')) }}
                                &middot; {{ trans_choice(':count comment|:count comments', $idea->comments_count, ['count' => $idea->comments_count]) }}
                            </div>
                        </flux:table.cell>

                        <flux:table.cell class="text-zinc-600 dark:text-zinc-300">
                            {{ $idea->board?->name }}
                        </flux:table.cell>

                        <flux:table.cell>
                            <flux:badge :color="$meta['color']" size="sm">{{ $meta['label'] }}</flux:badge>
                        </flux:table.cell>

                        <flux:table.cell>
                            <div class="flex items-center gap-2">
                                <flux:button
                                    variant="ghost"
                                    size="sm"
                                    class="review-action-button border border-emerald-200 bg-emerald-50 text-emerald-700 hover:bg-emerald-100 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-400"
                                    wire:click="approve({{ $idea->id }})"
                                    data-test="approve-idea"
                                >
                                    {{ __('Approve') }}
                                </flux:button>

                                <flux:button
                                    variant="ghost"
                                    size="sm"
                                    class="review-action-button border border-red-200 bg-red-50 text-red-700 hover:bg-red-100 dark:border-red-900 dark:bg-red-950 dark:text-red-400"
                                    wire:click="decline({{ $idea->id }})"
                                    data-test="decline-idea"
                                >
                                    {{ __('Decline') }}
                                </flux:button>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5">
                            <div class="py-14 text-center" data-test="review-empty">
                                <flux:icon.check-circle class="mx-auto size-8 text-emerald-400 dark:text-emerald-500" />
                                <flux:heading class="mt-3">{{ __('Queue is clear') }}</flux:heading>
                                <flux:text class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ __('Nothing needs attention right now. Nice work. 🎉') }}</flux:text>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>

    @if ($this->ideas->hasPages())
        <div class="mt-6">
            {{ $this->ideas->links() }}
        </div>
    @endif
</section>
