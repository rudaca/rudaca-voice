<?php

use App\Models\Idea;
use App\Models\IdeaBoard;
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

    public string $board_id = '';

    public string $category_id = '';

    public bool $is_anonymous = false;

    public bool $is_private = false;

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
     * Active boards for the current team.
     *
     * @return Collection<int, IdeaBoard>
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
            'board_id' => ['required', Rule::exists('idea_boards', 'id')->where('team_id', $teamId)],
            'category_id' => [
                'required',
                Rule::exists('idea_categories', 'id')
                    ->where('team_id', $teamId)
                    ->where('board_id', $this->board_id),
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
            'is_anonymous' => $this->is_anonymous,
            'is_private' => $this->is_private,
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

<section class="mx-auto w-full max-w-[680px] px-6 py-7 lg:px-8">
    <flux:link :href="route('ideas.index')" wire:navigate variant="subtle" class="inline-flex items-center gap-1 text-sm">
        <flux:icon.arrow-left class="size-4" />
        {{ __('Back to all ideas') }}
    </flux:link>

    <div class="mt-5">
        <flux:heading size="xl">{{ __('Submit an idea') }}</flux:heading>
        <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">
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

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <flux:select wire:model.live="board_id" :label="__('Board')" :placeholder="__('Choose a board')" required data-test="idea-board">
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
                data-test="idea-description"
            />

            <div class="space-y-3">
                <flux:checkbox
                    wire:model="is_anonymous"
                    :label="__('Submit anonymously')"
                    :description="__('Your name won\'t be shown to other employees.')"
                    data-test="idea-anonymous"
                />

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
