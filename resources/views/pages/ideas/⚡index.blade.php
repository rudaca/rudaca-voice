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

new #[Title('Ideas')] class extends Component {
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

    #[Url(as: 'sort')]
    public string $sort = 'newest';

    #[Url(as: 'status')]
    public string $status = '';

    #[Url(as: 'board')]
    public string $board = '';

    #[Url(as: 'category')]
    public string $category = '';

    /**
     * Reset pagination whenever a filter changes.
     */
    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'board', 'category'], true)) {
            $this->resetPage();
        }
    }

    /**
     * Change the active sort order.
     */
    public function sortBy(string $sort): void
    {
        $this->sort = in_array($sort, ['newest', 'top'], true) ? $sort : 'newest';
        $this->resetPage();
    }

    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
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
     * Active categories for the current team, used for the filter dropdown.
     *
     * @return Collection<int, \App\Models\IdeaCategory>
     */
    #[Computed]
    public function categories(): Collection
    {
        return $this->team->categories()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * The filtered, sorted, paginated ideas for the current team.
     *
     * @return LengthAwarePaginator<int, Idea>
     */
    #[Computed]
    public function ideas(): LengthAwarePaginator
    {
        return Idea::query()
            ->where('team_id', $this->team->id)
            ->with(['board:id,name', 'category:id,name', 'submittedBy:id,name'])
            ->withCount(['votes', 'comments'])
            ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
            ->when($this->board !== '', fn ($query) => $query->where('board_id', $this->board))
            ->when($this->category !== '', fn ($query) => $query->where('category_id', $this->category))
            ->when(
                $this->sort === 'top',
                fn ($query) => $query->orderByDesc('votes_count')->orderByDesc('created_at')->orderByDesc('id'),
                fn ($query) => $query->orderByDesc('created_at')->orderByDesc('id'),
            )
            ->paginate(10);
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
}; ?>

<section class="mx-auto w-full max-w-[1080px] px-6 py-7 lg:px-8">
    <div>
        {{-- Header --}}
        <div class="flex flex-col gap-1">
            <flux:heading size="xl">{{ __('All Ideas') }}</flux:heading>
            <flux:text class="text-zinc-500 dark:text-zinc-400">
                {{ __(':ideas ideas across :boards boards', [
                    'ideas' => $this->ideas->total(),
                    'boards' => $this->boards->count(),
                ]) }}
            </flux:text>
        </div>

        {{-- Controls: sort (left) + filters (right) --}}
        <div class="mt-6 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div class="inline-flex rounded-lg bg-zinc-100 p-0.5 dark:bg-zinc-800" role="group" aria-label="{{ __('Sort ideas') }}">
                @foreach (['top' => __('Top voted'), 'newest' => __('Newest')] as $value => $label)
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
                <flux:select wire:model.live="status" size="sm" class="w-auto min-w-40" data-test="filter-status">
                    <flux:select.option value="">{{ __('All statuses') }}</flux:select.option>
                    @foreach (self::STATUS_META as $value => $meta)
                        <flux:select.option value="{{ $value }}">{{ $meta['label'] }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model.live="board" size="sm" class="w-auto min-w-40" data-test="filter-board">
                    <flux:select.option value="">{{ __('All boards') }}</flux:select.option>
                    @foreach ($this->boards as $board)
                        <flux:select.option value="{{ $board->id }}">{{ $board->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model.live="category" size="sm" class="w-auto min-w-40" data-test="filter-category">
                    <flux:select.option value="">{{ __('All categories') }}</flux:select.option>
                    @foreach ($this->categories as $category)
                        <flux:select.option value="{{ $category->id }}">{{ $category->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        </div>

        {{-- Ideas list --}}
        <div class="mt-5 space-y-3">
            @forelse ($this->ideas as $idea)
                @php($meta = $this->statusMeta($idea->status))
                <a
                    href="{{ route('ideas.show', ['idea' => $idea->slug]) }}"
                    wire:navigate
                    class="flex gap-4 rounded-xl border border-zinc-200 bg-white p-4 transition hover:border-indigo-200 hover:shadow-sm dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-indigo-900/60"
                    wire:key="idea-{{ $idea->id }}"
                    data-test="idea-row"
                >
                    {{-- Vote count (display only — voting not built yet) --}}
                    <div class="flex w-14 shrink-0 flex-col items-center justify-center gap-0.5 rounded-lg border border-zinc-200 py-2 dark:border-zinc-700">
                        <flux:icon.chevron-up class="size-4 text-zinc-400" />
                        <span class="text-sm font-bold text-zinc-900 dark:text-zinc-100">{{ $idea->votes_count }}</span>
                    </div>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <flux:badge :color="$meta['color']" size="sm">{{ $meta['label'] }}</flux:badge>
                            @if ($idea->category)
                                <flux:badge color="zinc" size="sm" variant="outline">{{ $idea->category->name }}</flux:badge>
                            @endif
                        </div>

                        <flux:heading size="lg" class="mt-2 truncate">{{ $idea->title }}</flux:heading>

                        <flux:text class="mt-1 line-clamp-2 text-sm text-zinc-500 dark:text-zinc-400">
                            {{ \Illuminate\Support\Str::limit(strip_tags($idea->description), 130) }}
                        </flux:text>

                        <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-zinc-500 dark:text-zinc-400">
                            <span>{{ $idea->is_anonymous ? __('Anonymous') : $idea->submittedBy?->name }}</span>
                            @if ($idea->board)
                                <span class="inline-flex items-center gap-1">
                                    <flux:icon.rectangle-group class="size-3.5" />
                                    {{ $idea->board->name }}
                                </span>
                            @endif
                            <span class="inline-flex items-center gap-1">
                                <flux:icon.chat-bubble-oval-left class="size-3.5" />
                                {{ $idea->comments_count }}
                            </span>
                            <span>{{ $idea->created_at->format('M j, Y') }}</span>
                        </div>
                    </div>
                </a>
            @empty
                <div class="rounded-xl border border-dashed border-zinc-300 py-14 text-center dark:border-zinc-700" data-test="ideas-empty">
                    <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __('No ideas match these filters.') }}</flux:text>
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
