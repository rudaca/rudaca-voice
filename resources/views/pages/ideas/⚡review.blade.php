<?php

use App\Models\Idea;
use App\Models\Team;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Review ideas')] class extends Component {
    use WithPagination;

    /**
     * Display metadata for each idea status (label + Flux badge color).
     *
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
     * Statuses that make up the review queue by default (need attention).
     *
     * @var array<int, string>
     */
    public const ACTIONABLE = ['new', 'under_review', 'planned', 'in_progress'];

    /** @var array<string, string> */
    public const PRIORITY_OPTIONS = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High'];

    /** @var array<string, string> */
    public const IMPACT_OPTIONS = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High'];

    /** @var array<string, string> */
    public const EFFORT_OPTIONS = ['small' => 'Small', 'medium' => 'Medium', 'large' => 'Large'];

    #[Url(as: 'sort')]
    public string $sort = 'top';

    #[Url(as: 'status')]
    public string $status = '';

    #[Url(as: 'group')]
    public string $group = '';

    #[Url(as: 'board')]
    public string $board = '';

    #[Url(as: 'priority')]
    public string $priority = '';

    #[Url(as: 'impact')]
    public string $impact = '';

    #[Url(as: 'effort')]
    public string $effort = '';

    /**
     * Reset pagination whenever a filter changes.
     */
    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'group', 'board', 'priority', 'impact', 'effort'], true)) {
            $this->resetPage();
        }
    }

    /**
     * Change the active sort order.
     */
    public function sortBy(string $sort): void
    {
        $this->sort = in_array($sort, ['top', 'newest', 'updated'], true) ? $sort : 'top';
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
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * The filtered, sorted, paginated review queue for the current team.
     *
     * @return LengthAwarePaginator<int, Idea>
     */
    #[Computed]
    public function ideas(): LengthAwarePaginator
    {
        return Idea::query()
            ->where('team_id', $this->team->id)
            ->with(['boardGroup:id,name', 'board:id,name', 'category:id,name', 'submittedBy:id,name'])
            ->withCount(['votes', 'comments'])
            ->when($this->group !== '', fn ($query) => $query->where('board_group_id', $this->group))
            ->when(
                $this->status !== '' && array_key_exists($this->status, self::STATUS_META),
                fn ($query) => $query->where('status', $this->status),
                fn ($query) => $query->whereIn('status', self::ACTIONABLE),
            )
            ->when($this->board !== '', fn ($query) => $query->where('board_id', $this->board))
            ->when($this->priority !== '', fn ($query) => $query->where('priority', $this->priority))
            ->when($this->impact !== '', fn ($query) => $query->where('impact', $this->impact))
            ->when($this->effort !== '', fn ($query) => $query->where('effort', $this->effort))
            ->when(
                $this->sort === 'top',
                fn ($query) => $query->orderByDesc('votes_count')->orderByDesc('id'),
                fn ($query) => $query->when(
                    $this->sort === 'updated',
                    fn ($inner) => $inner->orderByDesc('updated_at')->orderByDesc('id'),
                    fn ($inner) => $inner->orderByDesc('created_at')->orderByDesc('id'),
                ),
            )
            ->paginate(15);
    }

    /**
     * Get the display metadata for a status value.
     *
     * @return array{label: string, color: string}
     */
    public function statusMeta(string $status): array
    {
        return self::STATUS_META[$status] ?? ['label' => str($status)->headline()->value(), 'color' => 'zinc'];
    }

    public function priorityColor(string $value): string
    {
        return match ($value) {
            'high' => 'red',
            'medium' => 'amber',
            default => 'zinc',
        };
    }

    public function impactColor(string $value): string
    {
        return match ($value) {
            'high' => 'green',
            'medium' => 'blue',
            default => 'zinc',
        };
    }

    public function effortColor(string $value): string
    {
        return match ($value) {
            'large' => 'red',
            'medium' => 'amber',
            default => 'green',
        };
    }
}; ?>

<section class="mx-auto w-full max-w-[1080px] px-6 py-7 lg:px-8">
    {{-- Header --}}
    <div class="flex flex-col gap-1">
        <flux:heading size="xl">{{ __('Review ideas') }}</flux:heading>
        <flux:text class="text-zinc-500 dark:text-zinc-400">
            {{ trans_choice(':count idea needs attention|:count ideas need attention', $this->ideas->total(), ['count' => $this->ideas->total()]) }}
        </flux:text>
    </div>

    {{-- Controls: sort (left) + filters (right) --}}
    <div class="mt-6 flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">
        <div class="inline-flex rounded-lg bg-zinc-100 p-0.5 dark:bg-zinc-800" role="group" aria-label="{{ __('Sort ideas') }}">
            @foreach (['top' => __('Top voted'), 'newest' => __('Newest'), 'updated' => __('Recently updated')] as $value => $label)
                <button
                    type="button"
                    wire:click="sortBy('{{ $value }}')"
                    @class([
                        'rounded-md px-3 py-1.5 text-sm font-medium transition',
                        'bg-white text-zinc-900 shadow-sm dark:bg-zinc-700 dark:text-white' => $sort === $value,
                        'text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200' => $sort !== $value,
                    ])
                    data-test="sort-{{ $value }}"
                >
                    {{ $label }}
                </button>
            @endforeach
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <flux:select wire:model.live="status" size="sm" class="w-auto min-w-36" data-test="filter-status">
                <flux:select.option value="">{{ __('Needs attention') }}</flux:select.option>
                @foreach (self::ACTIONABLE as $value)
                    <flux:select.option value="{{ $value }}">{{ self::STATUS_META[$value]['label'] }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="group" size="sm" class="w-auto min-w-36" data-test="filter-group">
                <flux:select.option value="">{{ __('All groups') }}</flux:select.option>
                @foreach ($this->boardGroups as $boardGroup)
                    <flux:select.option value="{{ $boardGroup->id }}">{{ $boardGroup->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="board" size="sm" class="w-auto min-w-36" data-test="filter-board">
                <flux:select.option value="">{{ __('All boards') }}</flux:select.option>
                @foreach ($this->boards as $board)
                    <flux:select.option value="{{ $board->id }}">{{ $board->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="priority" size="sm" class="w-auto min-w-32" data-test="filter-priority">
                <flux:select.option value="">{{ __('Any priority') }}</flux:select.option>
                @foreach (self::PRIORITY_OPTIONS as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="impact" size="sm" class="w-auto min-w-32" data-test="filter-impact">
                <flux:select.option value="">{{ __('Any impact') }}</flux:select.option>
                @foreach (self::IMPACT_OPTIONS as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="effort" size="sm" class="w-auto min-w-32" data-test="filter-effort">
                <flux:select.option value="">{{ __('Any effort') }}</flux:select.option>
                @foreach (self::EFFORT_OPTIONS as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
    </div>

    {{-- Review queue --}}
    <div class="mt-5 space-y-3">
        @forelse ($this->ideas as $idea)
            @php($meta = $this->statusMeta($idea->status))
            <a
                href="{{ route('ideas.show', ['idea' => $idea->slug]) }}"
                wire:navigate
                class="flex gap-4 rounded-xl border border-zinc-200 bg-white p-4 transition hover:border-indigo-200 hover:shadow-sm dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-indigo-900/60"
                wire:key="review-{{ $idea->id }}"
                data-test="review-row"
            >
                {{-- Vote / comment stats --}}
                <div class="flex w-14 shrink-0 flex-col items-center gap-2">
                    <div class="flex flex-col items-center leading-tight">
                        <span class="text-base font-semibold text-zinc-800 dark:text-zinc-100">{{ $idea->votes_count }}</span>
                        <span class="text-[10px] font-medium uppercase tracking-wide text-zinc-400">{{ trans_choice('vote|votes', $idea->votes_count) }}</span>
                    </div>
                    <div class="flex flex-col items-center leading-tight">
                        <span class="text-sm font-semibold text-zinc-600 dark:text-zinc-300">{{ $idea->comments_count }}</span>
                        <span class="text-[10px] font-medium uppercase tracking-wide text-zinc-400">{{ __('replies') }}</span>
                    </div>
                </div>

                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <flux:badge :color="$meta['color']" size="sm">{{ $meta['label'] }}</flux:badge>
                        <flux:badge :color="$this->priorityColor($idea->priority)" size="sm" variant="outline">
                            {{ __('Priority') }}: {{ self::PRIORITY_OPTIONS[$idea->priority] ?? ucfirst($idea->priority) }}
                        </flux:badge>
                        <flux:badge :color="$this->impactColor($idea->impact)" size="sm" variant="outline">
                            {{ __('Impact') }}: {{ self::IMPACT_OPTIONS[$idea->impact] ?? ucfirst($idea->impact) }}
                        </flux:badge>
                        <flux:badge :color="$this->effortColor($idea->effort)" size="sm" variant="outline">
                            {{ __('Effort') }}: {{ self::EFFORT_OPTIONS[$idea->effort] ?? ucfirst($idea->effort) }}
                        </flux:badge>
                    </div>

                    <flux:heading size="lg" class="mt-2 truncate">{{ $idea->title }}</flux:heading>

                    @php($metaPieces = array_values(array_filter([
                        $idea->boardGroup?->name,
                        $idea->board?->name,
                        $idea->category?->name,
                        trans_choice(':count comment|:count comments', $idea->comments_count, ['count' => $idea->comments_count]),
                        __('Submitted :date', ['date' => $idea->created_at->format('M j, Y')]),
                    ], fn ($piece) => filled($piece))))

                    <div class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                        {{ implode(' · ', $metaPieces) }}
                    </div>
                </div>
            </a>
        @empty
            <div class="rounded-xl border border-dashed border-zinc-300 py-14 text-center dark:border-zinc-700" data-test="review-empty">
                <flux:icon.check-circle class="mx-auto size-8 text-emerald-400 dark:text-emerald-500" />
                <flux:heading class="mt-3">{{ __('Queue is clear') }}</flux:heading>
                <flux:text class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ __('Nothing needs attention right now. Nice work. 🎉') }}</flux:text>
            </div>
        @endforelse
    </div>

    @if ($this->ideas->hasPages())
        <div class="mt-6">
            {{ $this->ideas->links() }}
        </div>
    @endif
</section>
