<?php

use App\Models\Idea;
use App\Models\IdeaBoard;
use App\Models\IdeaStatusHistory;
use App\Models\Team;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Submit idea')] class extends Component {
    public string $title = '';

    public string $description = '';

    public string $board_group_id = '';

    public string $board_id = '';

    public string $category_id = '';

    public bool $is_anonymous = false;

    public bool $is_private = false;

    /**
     * Reset the chosen board and category when the group changes.
     */
    public function updatedBoardGroupId(): void
    {
        $this->board_id = '';
        $this->category_id = '';
    }

    /**
     * Reset the chosen category whenever the board changes (categories are board-specific).
     */
    public function updatedBoardId(): void
    {
        $this->category_id = '';
    }

    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    /**
     * Whether the current team allows submitting ideas anonymously.
     */
    #[Computed]
    public function allowsAnonymousIdeas(): bool
    {
        return $this->team->allowsAnonymousIdeas();
    }

    /**
     * Active board groups for the current team.
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
     * Active boards within the selected board group.
     *
     * @return Collection<int, IdeaBoard>
     */
    #[Computed]
    public function boards(): Collection
    {
        if ($this->board_group_id === '') {
            return new Collection;
        }

        return $this->team->boards()
            ->where('is_active', true)
            ->where('board_group_id', $this->board_group_id)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * Active categories for the selected board.
     *
     * @return Collection<int, \App\Models\IdeaCategory>
     */
    #[Computed]
    public function categories(): Collection
    {
        if ($this->board_id === '') {
            return new Collection;
        }

        return $this->team->categories()
            ->where('is_active', true)
            ->where('board_id', $this->board_id)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * Validation rules for the submission.
     *
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $teamId = $this->team->id;

        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'board_group_id' => [
                'required',
                Rule::exists('idea_board_groups', 'id')->where('team_id', $teamId)->where('is_active', true),
            ],
            'board_id' => [
                'required',
                Rule::exists('idea_boards', 'id')
                    ->where('team_id', $teamId)
                    ->where('board_group_id', $this->board_group_id)
                    ->where('is_active', true),
            ],
            'category_id' => [
                'required',
                Rule::exists('idea_categories', 'id')
                    ->where('team_id', $teamId)
                    ->where('board_id', $this->board_id)
                    ->where('is_active', true),
            ],
            'is_anonymous' => ['boolean'],
            'is_private' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'board_group_id' => __('board group'),
            'board_id' => __('board'),
            'category_id' => __('category'),
        ];
    }

    /**
     * Create the idea and redirect to its detail page.
     */
    public function save(): void
    {
        $validated = $this->validate();

        $team = $this->team;
        $board = IdeaBoard::whereKey($validated['board_id'])->where('team_id', $team->id)->firstOrFail();

        $idea = Idea::create([
            'team_id' => $team->id,
            'board_group_id' => $board->board_group_id,
            'board_id' => $board->id,
            'category_id' => $validated['category_id'],
            'submitted_by_user_id' => Auth::id(),
            'title' => $validated['title'],
            'slug' => $this->uniqueSlug($validated['title'], $team->id),
            'description' => $validated['description'],
            'status' => 'new',
            'is_anonymous' => $team->allowsAnonymousIdeas() && $this->is_anonymous,
            'is_private' => $this->is_private,
        ]);

        IdeaStatusHistory::create([
            'idea_id' => $idea->id,
            'changed_by_user_id' => Auth::id(),
            'old_status' => 'new',
            'new_status' => 'new',
        ]);

        Flux::toast(variant: 'success', text: __('Idea submitted.'));

        $this->redirectRoute('ideas.show', ['idea' => $idea->slug], navigate: true);
    }

    /**
     * Generate a slug that is unique within the team (accounts for soft-deleted ideas).
     */
    private function uniqueSlug(string $title, int $teamId): string
    {
        $base = Str::slug($title) ?: 'idea';

        $existing = Idea::withTrashed()
            ->where('team_id', $teamId)
            ->where(fn ($query) => $query->where('slug', $base)->orWhere('slug', 'like', $base.'-%'))
            ->pluck('slug');

        if ($existing->isEmpty()) {
            return $base;
        }

        $maxSuffix = $existing
            ->map(function (string $slug) use ($base): ?int {
                if ($slug === $base) {
                    return 0;
                }

                return preg_match('/^'.preg_quote($base, '/').'-(\d+)$/', $slug, $matches)
                    ? (int) $matches[1]
                    : null;
            })
            ->filter(fn (?int $suffix) => $suffix !== null)
            ->max() ?? 0;

        return $base.'-'.($maxSuffix + 1);
    }
}; ?>

@push('breadcrumbs')
    <x-breadcrumbs :items="[
        ['label' => __('All Ideas'), 'href' => route('ideas.index')],
        ['label' => __('Submit Idea'), 'href' => null],
    ]" />
@endpush

<section class="mx-auto w-full container px-6 py-7 lg:px-8">
    <flux:link as="button" x-data x-on:click="window.history.back()" variant="subtle" class="inline-flex items-center gap-1 text-sm">
        <flux:icon.arrow-left class="size-4" />
        {{ __('Back') }}
    </flux:link>

    <div class="mt-5">
        <flux:heading size="xl">{{ __('Submit an idea') }}</flux:heading>
        <flux:text class="mt-1 text-slate-600 dark:text-slate-500">
            {{ __('Good ideas are specific: describe the problem, who it affects, and the improvement you\'d like to see. Anything from process fixes to automation is welcome.') }}
        </flux:text>
    </div>

    <flux:card class="mt-6">
        <form wire:submit="save" class="space-y-6">
            <flux:input
                wire:model="title"
                :label="__('Title')"
                type="text"
                required
                autofocus
                maxlength="255"
                :placeholder="__('Summarize your idea in one line')"
                data-test="idea-title"
            />

            <flux:select wire:model.live="board_group_id" :label="__('Board group')" :placeholder="__('Choose a board group')" required data-test="idea-board-group">
                @foreach ($this->boardGroups as $group)
                    <flux:select.option value="{{ $group->id }}">{{ $group->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <flux:select
                    wire:model.live="board_id"
                    :label="__('Board')"
                    :placeholder="$this->board_group_id === '' ? __('Select a board group first') : __('Choose a board')"
                    :disabled="$this->board_group_id === ''"
                    required
                    data-test="idea-board"
                >
                    @foreach ($this->boards as $board)
                        <flux:select.option value="{{ $board->id }}">{{ $board->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select
                    wire:model="category_id"
                    :label="__('Category')"
                    :placeholder="$this->board_id === '' ? __('Select a board first') : __('Choose a category')"
                    :disabled="$this->board_id === ''"
                    required
                    data-test="idea-category"
                >
                    @foreach ($this->categories as $category)
                        <flux:select.option value="{{ $category->id }}">{{ $category->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <flux:textarea
                wire:model="description"
                :label="__('Description')"
                rows="6"
                required
                :placeholder="__('What is the problem, and how would your idea improve things?')"
                :description="__('Include who it affects and the outcome you\'d expect. The more context, the easier it is to prioritize.')"
                data-test="idea-description"
            />

            <flux:separator variant="subtle" />

            <div class="space-y-3">
                <flux:text class="text-sm font-medium text-slate-800 dark:text-slate-400">{{ __('Visibility') }}</flux:text>

                @if ($this->allowsAnonymousIdeas)
                    <flux:checkbox
                        wire:model="is_anonymous"
                        :label="__('Submit anonymously')"
                        :description="__('Your name won\'t be shown to other employees.')"
                        data-test="idea-anonymous"
                    />
                @endif

                <flux:checkbox
                    wire:model="is_private"
                    :label="__('Mark as private')"
                    :description="__('Only managers and admins will be able to see this idea.')"
                    data-test="idea-private"
                />
            </div>

            <div class="flex items-center justify-end gap-3">
                <flux:button :href="route('ideas.index')" wire:navigate variant="ghost">
                    {{ __('Cancel') }}
                </flux:button>

                <flux:button variant="primary" type="submit" data-test="submit-idea-button">
                    {{ __('Submit idea') }}
                </flux:button>
            </div>
        </form>
    </flux:card>
</section>
