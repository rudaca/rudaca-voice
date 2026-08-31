<?php

use App\Enums\TeamPermission;
use App\Models\Idea;
use App\Models\IdeaBoard;
use App\Models\IdeaStatusHistory;
use App\Models\Team;
use App\Models\User;
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
     * The user this idea is being submitted for, when a permitted user is
     * entering it on someone else's behalf. Null means "Myself".
     */
    public ?int $on_behalf_of_user_id = null;

    public string $on_behalf_of_user_name = '';

    public string $on_behalf_of_search = '';

    /**
     * Whether the board group and board were inherited from a specific board's context
     * (as opposed to a board group scoped view or general navigation). When true, the
     * board group and board fields are locked and cannot be changed by the user.
     */
    public bool $boardLocked = false;

    public string $lockedBoardGroupId = '';

    public string $lockedBoardId = '';

    /**
     * Preselect the board group, board, and/or category when arriving from a scoped
     * view, e.g. `ideas/create?board=5&category=Bug` (preselects the board, its group,
     * and the matching category) or `ideas/create?group=3` (preselects the group only).
     * Falls back to unset whenever the referenced board/group/category doesn't exist,
     * is inactive, or belongs to another team/board. A valid `board` takes precedence
     * over `group`; `category` only applies alongside a valid `board`, since category
     * names aren't unique across boards.
     *
     * When a specific board is inherited this way, the board group and board are locked
     * (see updatedBoardGroupId()/updatedBoardId() and save()) so the user cannot change
     * where the idea is submitted.
     */
    public function mount(): void
    {
        $boardId = request()->query('board');

        if (is_numeric($boardId)) {
            $board = $this->team->boards()->where('is_active', true)->find((int) $boardId);

            if ($board !== null) {
                $this->board_group_id = (string) $board->board_group_id;
                $this->board_id = (string) $board->id;

                $this->boardLocked = true;
                $this->lockedBoardGroupId = $this->board_group_id;
                $this->lockedBoardId = $this->board_id;

                $this->preselectCategory($board->id, request()->query('category'));

                return;
            }
        }

        $groupId = request()->query('group');

        if (is_numeric($groupId)) {
            $group = $this->team->boardGroups()->where('is_active', true)->find((int) $groupId);

            if ($group !== null) {
                $this->board_group_id = (string) $group->id;
            }
        }
    }

    /**
     * Preselect the category matching the given name within the given board.
     */
    private function preselectCategory(int $boardId, mixed $categoryName): void
    {
        if (! is_string($categoryName) || $categoryName === '') {
            return;
        }

        $category = $this->team->categories()
            ->where('is_active', true)
            ->where('board_id', $boardId)
            ->where('name', $categoryName)
            ->first();

        if ($category !== null) {
            $this->category_id = (string) $category->id;
        }
    }

    /**
     * Reset the chosen board and category when the group changes. When the board group
     * is locked, revert any change instead — this guards against both accidental edits
     * and a client attempting to change the value directly.
     */
    public function updatedBoardGroupId(): void
    {
        if ($this->boardLocked) {
            $this->board_group_id = $this->lockedBoardGroupId;

            return;
        }

        $this->board_id = '';
        $this->category_id = '';
    }

    /**
     * Reset the chosen category whenever the board changes (categories are board-specific).
     * When the board is locked, revert any change instead — see updatedBoardGroupId().
     */
    public function updatedBoardId(): void
    {
        if ($this->boardLocked) {
            $this->board_id = $this->lockedBoardId;

            return;
        }

        $this->category_id = '';
    }

    /**
     * The locked board, for display alongside its board group name. Null unless a
     * specific board's context was inherited.
     */
    #[Computed]
    public function lockedBoard(): ?IdeaBoard
    {
        if (! $this->boardLocked) {
            return null;
        }

        return $this->team->boards()->with('boardGroup')->find($this->board_id);
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
     * Whether the authenticated user may submit this idea on behalf of
     * another member of the current team.
     */
    #[Computed]
    public function canSubmitOnBehalf(): bool
    {
        return Auth::user()->hasTeamPermission($this->team, TeamPermission::SubmitIdeaOnBehalf);
    }

    /**
     * Active team members matching the current "on behalf of" search term,
     * excluding the authenticated user. Empty unless the user is permitted
     * to submit ideas on behalf of others and has typed a search term.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function onBehalfOfCandidates(): Collection
    {
        $search = trim($this->on_behalf_of_search);

        if (! $this->canSubmitOnBehalf || $search === '') {
            return new Collection;
        }

        return $this->team->members()
            ->where('users.id', '!=', Auth::id())
            ->where('users.is_active', true)
            ->where(fn ($query) => $query
                ->where('users.name', 'like', "%{$search}%")
                ->orWhere('users.email', 'like', "%{$search}%"))
            ->orderBy('users.name')
            ->limit(10)
            ->get(['users.id', 'users.name', 'users.email']);
    }

    /**
     * Select the team member this idea is being submitted on behalf of.
     */
    public function selectOnBehalfOfUser(int $userId): void
    {
        abort_unless($this->canSubmitOnBehalf, 403);

        $user = $this->team->members()
            ->where('users.id', $userId)
            ->where('users.is_active', true)
            ->firstOrFail();

        $this->on_behalf_of_user_id = $user->id;
        $this->on_behalf_of_user_name = $user->name;
        $this->on_behalf_of_search = '';
    }

    /**
     * Revert the "submit on behalf of" selection back to Myself.
     */
    public function clearOnBehalfOfSelection(): void
    {
        $this->reset('on_behalf_of_user_id', 'on_behalf_of_user_name');
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
            'on_behalf_of_user_id' => [
                'nullable',
                'integer',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === null) {
                        return;
                    }

                    if (! $this->canSubmitOnBehalf) {
                        $fail(__('You are not authorized to submit an idea on behalf of another user.'));

                        return;
                    }

                    $isEligible = $this->team->members()
                        ->where('users.id', $value)
                        ->where('users.is_active', true)
                        ->exists();

                    if (! $isEligible) {
                        $fail(__('Select an active member of your organization.'));
                    }
                },
            ],
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
        if ($this->boardLocked) {
            $this->board_group_id = $this->lockedBoardGroupId;
            $this->board_id = $this->lockedBoardId;
        }

        $validated = $this->validate();

        $team = $this->team;
        $board = IdeaBoard::whereKey($validated['board_id'])->where('team_id', $team->id)->firstOrFail();

        $enteredByUserId = Auth::id();
        $submittedByUserId = $validated['on_behalf_of_user_id'] ?? $enteredByUserId;

        $idea = Idea::create([
            'team_id' => $team->id,
            'board_group_id' => $board->board_group_id,
            'board_id' => $board->id,
            'category_id' => $validated['category_id'],
            'submitted_by_user_id' => $submittedByUserId,
            'entered_by_user_id' => $enteredByUserId,
            'title' => $validated['title'],
            'slug' => $this->uniqueSlug($validated['title'], $team->id),
            'description' => $validated['description'],
            'status' => 'new',
            'is_anonymous' => $team->allowsAnonymousIdeas() && $this->is_anonymous,
            'is_private' => $this->is_private,
        ]);

        IdeaStatusHistory::create([
            'idea_id' => $idea->id,
            'changed_by_user_id' => $enteredByUserId,
            'old_status' => 'new',
            'new_status' => 'new',
            'note' => $submittedByUserId !== $enteredByUserId
                ? __('Entered by :entered on behalf of :submitted.', [
                    'entered' => Auth::user()->name,
                    'submitted' => User::whereKey($submittedByUserId)->value('name'),
                ])
                : null,
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

<section class="mx-auto w-full container px-3 py-7 sm:px-6 lg:px-8">
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

            @if ($this->canSubmitOnBehalf)
                <div class="space-y-2" data-test="idea-on-behalf-of">
                    <flux:label>{{ __('Submit on behalf of') }}</flux:label>
                    <flux:text class="text-sm text-slate-600 dark:text-slate-500">
                        {{ __("Enter someone else's idea while your name is kept as the person who logged it.") }}
                    </flux:text>

                    @if ($on_behalf_of_user_id)
                        <div class="flex items-center justify-between rounded-lg border border-zinc-200 bg-zinc-50 p-3 dark:border-zinc-700 dark:bg-zinc-800" data-test="on-behalf-selected">
                            <div class="flex items-center gap-3">
                                <flux:avatar :name="$on_behalf_of_user_name" size="xs" />
                                <span class="font-medium text-slate-900 dark:text-slate-200">{{ $on_behalf_of_user_name }}</span>
                            </div>
                            <flux:button wire:click="clearOnBehalfOfSelection" variant="ghost" size="sm" data-test="change-on-behalf">
                                {{ __('Change') }}
                            </flux:button>
                        </div>
                    @else
                        <div class="relative">
                            <flux:input
                                wire:model.live.debounce.300ms="on_behalf_of_search"
                                :placeholder="__('Leave blank to submit as yourself, or search for a colleague...')"
                                data-test="on-behalf-search-input"
                            />
                            <flux:error name="on_behalf_of_user_id" />

                            @if (trim($on_behalf_of_search) !== '')
                                <div class="mt-1 max-h-56 space-y-1 overflow-y-auto rounded-lg border border-zinc-200 p-1 dark:border-zinc-700" data-test="on-behalf-results">
                                    @forelse ($this->onBehalfOfCandidates as $candidate)
                                        <button
                                            type="button"
                                            wire:click="selectOnBehalfOfUser({{ $candidate->id }})"
                                            wire:key="on-behalf-candidate-{{ $candidate->id }}"
                                            class="flex w-full items-center gap-3 rounded-md p-2 text-start hover:bg-zinc-100 dark:hover:bg-zinc-800"
                                            data-test="on-behalf-option"
                                        >
                                            <flux:avatar :name="$candidate->name" size="xs" />
                                            <div class="min-w-0">
                                                <div class="truncate font-medium text-slate-900 dark:text-slate-200">{{ $candidate->name }}</div>
                                                <div class="truncate text-sm text-slate-600 dark:text-slate-500">{{ $candidate->email }}</div>
                                            </div>
                                        </button>
                                    @empty
                                        <div class="p-2 text-sm text-slate-600 dark:text-slate-500">{{ __('No matching members found.') }}</div>
                                    @endforelse
                                </div>
                            @endif
                        </div>
                    @endif
                </div>

                <flux:separator variant="subtle" />
            @endif

            @if ($this->boardLocked)
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2" data-test="idea-board-context">
                    <div>
                        <flux:label>{{ __('Board group') }}</flux:label>
                        <div class="mt-1.5 flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-800 dark:border-slate-700 dark:bg-slate-800/50 dark:text-slate-300" data-test="idea-board-context-group">
                            <flux:icon.rectangle-group class="size-4 shrink-0 text-slate-500 dark:text-slate-400" />
                            {{ $this->lockedBoard?->boardGroup?->name }}
                        </div>
                    </div>

                    <div>
                        <flux:label>{{ __('Board') }}</flux:label>
                        <div class="mt-1.5 flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-800 dark:border-slate-700 dark:bg-slate-800/50 dark:text-slate-300" data-test="idea-board-context-board">
                            <flux:icon.squares-2x2 class="size-4 shrink-0 text-slate-500 dark:text-slate-400" />
                            {{ $this->lockedBoard?->name }}
                        </div>
                    </div>
                </div>

                <flux:select
                    wire:model="category_id"
                    :label="__('Category')"
                    :placeholder="__('Choose a category')"
                    required
                    data-test="idea-category"
                >
                    @foreach ($this->categories as $category)
                        <flux:select.option value="{{ $category->id }}">{{ $category->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @else
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
            @endif

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
