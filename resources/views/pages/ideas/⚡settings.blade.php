<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Rules\TeamName;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Organization Settings')] class extends Component {
    /** @var array<string, string> */
    public const VISIBILITY_OPTIONS = [
        'internal' => 'Internal',
        'external' => 'External',
    ];

    #[Url(as: 'tab')]
    public string $tab = 'boards';

    /**
     * When arriving via the header's "New" menu, opens the matching creation
     * modal on load: "board", "group", "category", or "member".
     */
    #[Url(as: 'new')]
    public ?string $new = null;

    // --- Boards list filter ---
    public string $boardGroupFilter = '';

    // --- Board group form ---
    public ?int $groupId = null;

    public string $groupName = '';

    public string $groupSlug = '';

    /**
     * The last slug we auto-generated from the name, so we know whether the
     * slug field still tracks the name or the user has since typed their own.
     */
    public string $groupAutoSlug = '';

    public string $groupDescription = '';

    public bool $groupIsActive = true;

    // --- Board form ---
    public ?int $boardId = null;

    public string $boardName = '';

    public string $boardSlug = '';

    public string $boardDescription = '';

    public ?string $boardGroupId = null;

    public string $boardVisibility = 'internal';

    public string $boardIsActive = '1';

    // --- Category form ---
    public ?int $categoryId = null;

    public string $categoryName = '';

    public string $categorySlug = '';

    public string $categoryDescription = '';

    public string $categoryBoardId = '';

    public bool $categoryIsActive = true;

    // --- Quick add category (Categories tab) ---
    public string $quickCategoryName = '';

    public string $quickCategoryBoardId = '';

    // --- Organization settings form (Settings tab) ---
    public string $orgTeamName = '';

    public bool $orgAllowAnonymousIdeas = true;

    // --- New member form (Members tab) ---
    public string $memberSearch = '';

    public ?int $memberUserId = null;

    public string $memberUserName = '';

    public string $memberRole = 'employee';

    // --- Revoke member access (Members tab) ---
    public ?int $removeMemberId = null;

    public string $removeMemberName = '';

    public function mount(): void
    {
        $this->orgTeamName = $this->team->name;
        $this->orgAllowAnonymousIdeas = $this->team->allowsAnonymousIdeas();

        match ($this->new) {
            'board' => $this->newBoard(),
            'group' => $this->newBoardGroup(),
            'category' => $this->newCategory(),
            'member' => $this->newMember(),
            default => null,
        };
    }

    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    /**
     * @return Collection<int, \App\Models\IdeaBoardGroup>
     */
    #[Computed]
    public function boardGroups(): Collection
    {
        return $this->team->boardGroups()
            ->withCount('boards')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, \App\Models\IdeaBoard>
     */
    #[Computed]
    public function boards(): Collection
    {
        return $this->team->boards()
            ->with('boardGroup:id,name')
            ->withCount('ideas')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * Boards shown on the Boards tab, narrowed to the selected group filter.
     *
     * @return Collection<int, \App\Models\IdeaBoard>
     */
    #[Computed]
    public function filteredBoards(): Collection
    {
        return $this->team->boards()
            ->with('boardGroup:id,name')
            ->withCount('ideas')
            ->when($this->boardGroupFilter !== '', fn ($query) => $query->where('board_group_id', $this->boardGroupFilter))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, \App\Models\IdeaCategory>
     */
    #[Computed]
    public function categories(): Collection
    {
        return $this->team->categories()
            ->with('board:id,name')
            ->withCount('ideas')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, \App\Models\User>
     */
    #[Computed]
    public function members(): Collection
    {
        return $this->team->members()->orderBy('name')->get();
    }

    #[Computed]
    public function canAddMember(): bool
    {
        return Gate::allows('addMember', $this->team);
    }

    #[Computed]
    public function canRemoveMember(): bool
    {
        return Gate::allows('removeMember', $this->team);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    #[Computed]
    public function availableRoles(): array
    {
        return TeamRole::assignable();
    }

    /**
     * Users not already on this team, matching the current search term —
     * shown as pickable results in the "New member" modal.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function searchableUsers(): Collection
    {
        $search = trim($this->memberSearch);

        if ($search === '') {
            return new Collection;
        }

        return User::query()
            ->whereNotIn('id', $this->team->members()->select('users.id'))
            ->where(fn ($query) => $query
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%"))
            ->orderBy('name')
            ->limit(10)
            ->get();
    }

    /**
     * Board groups selectable when assigning a board: active groups, plus the
     * board's currently-assigned group when editing (even if it is inactive).
     *
     * @return Collection<int, \App\Models\IdeaBoardGroup>
     */
    #[Computed]
    public function assignableBoardGroups(): Collection
    {
        $groups = $this->team->boardGroups()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        if ($this->boardGroupId && ! $groups->contains('id', (int) $this->boardGroupId)) {
            $current = $this->team->boardGroups()->find($this->boardGroupId, ['id', 'name']);

            if ($current) {
                $groups->push($current);
            }
        }

        return $groups;
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'groupName' => __('name'),
            'groupSlug' => __('slug'),
            'boardName' => __('name'),
            'boardSlug' => __('slug'),
            'boardGroupId' => __('board group'),
            'categoryName' => __('name'),
            'categorySlug' => __('slug'),
            'categoryBoardId' => __('board'),
            'quickCategoryName' => __('name'),
            'quickCategoryBoardId' => __('board'),
            'orgTeamName' => __('organization name'),
            'memberUserId' => __('user'),
        ];
    }

    // ----- Organization settings -----

    public function saveTeamSettings(): void
    {
        $validated = $this->validate([
            'orgTeamName' => ['required', 'string', 'max:255', new TeamName],
            'orgAllowAnonymousIdeas' => ['boolean'],
        ]);

        $team = $this->team;

        $team->update([
            'name' => $validated['orgTeamName'],
            'allow_anonymous_ideas' => $this->orgAllowAnonymousIdeas,
        ]);

        Flux::toast(variant: 'success', text: __('Organization settings saved.'));

        $this->redirectRoute('ideas.settings', ['current_team' => $team->fresh()->slug, 'tab' => 'settings'], navigate: true);
    }

    // ----- Board groups -----

    public function newBoardGroup(): void
    {
        $this->reset('groupId', 'groupName', 'groupSlug', 'groupAutoSlug', 'groupDescription', 'groupIsActive');
        $this->resetValidation();
        $this->dispatch('modal-show', name: 'board-group');
    }

    public function editBoardGroup(int $id): void
    {
        $group = $this->team->boardGroups()->findOrFail($id);

        $this->groupId = $group->id;
        $this->groupName = $group->name;
        $this->groupSlug = $group->slug;
        $this->groupAutoSlug = Str::slug($group->name);
        $this->groupDescription = $group->description ?? '';
        $this->groupIsActive = $group->is_active;

        $this->resetValidation();
        $this->dispatch('modal-show', name: 'board-group');
    }

    /**
     * Keep the slug field in sync as the admin types the group name, as long
     * as they haven't since typed a custom slug of their own.
     */
    public function updatedGroupName(): void
    {
        if ($this->groupSlug === '' || $this->groupSlug === $this->groupAutoSlug) {
            $this->groupAutoSlug = Str::slug($this->groupName);
            $this->groupSlug = $this->groupAutoSlug;
        }
    }

    public function saveBoardGroup(): void
    {
        $teamId = $this->team->id;
        $this->groupSlug = Str::slug($this->groupSlug !== '' ? $this->groupSlug : $this->groupName);

        $validated = $this->validate([
            'groupName' => ['required', 'string', 'max:255'],
            'groupSlug' => ['required', 'string', 'max:255', Rule::unique('idea_board_groups', 'slug')->where('team_id', $teamId)->ignore($this->groupId)],
            'groupDescription' => ['nullable', 'string', 'max:1000'],
            'groupIsActive' => ['boolean'],
        ]);

        $attributes = [
            'name' => $validated['groupName'],
            'slug' => $validated['groupSlug'],
            'description' => $validated['groupDescription'] !== '' ? $validated['groupDescription'] : null,
            'is_active' => $this->groupIsActive,
        ];

        if ($this->groupId) {
            $this->team->boardGroups()->findOrFail($this->groupId)->update($attributes);
        } else {
            $this->team->boardGroups()->create($attributes + [
                'created_by_user_id' => Auth::id(),
                'sort_order' => (int) $this->team->boardGroups()->max('sort_order') + 1,
            ]);
        }

        unset($this->boardGroups);
        $this->dispatch('modal-close', name: 'board-group');
        Flux::toast(variant: 'success', text: __('Board group saved.'));
    }

    public function toggleBoardGroup(int $id): void
    {
        $group = $this->team->boardGroups()->findOrFail($id);
        $group->update(['is_active' => ! $group->is_active]);
        unset($this->boardGroups);
    }

    // ----- Boards -----

    public function newBoard(): void
    {
        $this->reset('boardId', 'boardName', 'boardSlug', 'boardDescription', 'boardGroupId', 'boardVisibility', 'boardIsActive');
        $this->resetValidation();
        $this->dispatch('modal-show', name: 'board');
    }

    public function editBoard(int $id): void
    {
        $board = $this->team->boards()->findOrFail($id);

        $this->boardId = $board->id;
        $this->boardName = $board->name;
        $this->boardSlug = $board->slug;
        $this->boardDescription = $board->description ?? '';
        $this->boardGroupId = $board->board_group_id ? (string) $board->board_group_id : null;
        $this->boardVisibility = $board->visibility;
        $this->boardIsActive = $board->is_active ? '1' : '0';

        $this->resetValidation();
        $this->dispatch('modal-show', name: 'board');
    }

    /**
     * Keep the read-only slug preview in sync as the admin types the board name.
     */
    public function updatedBoardName(): void
    {
        $this->boardSlug = $this->nextAvailableBoardSlug($this->boardName, $this->boardId);
    }

    /**
     * Slugify $name and append a numeric suffix until it is unique among the team's boards.
     */
    private function nextAvailableBoardSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'board';
        $slug = $base;
        $suffix = 1;

        while ($this->team->boards()->where('slug', $slug)->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))->exists()) {
            $suffix++;
            $slug = "{$base}-{$suffix}";
        }

        return $slug;
    }

    public function saveBoard(): void
    {
        $teamId = $this->team->id;
        // The slug field is read-only in the UI, but recompute it here too rather than
        // trusting whatever the client sent — it's the only way to guarantee uniqueness
        // even if a request tampers with the value or arrives out of order with the
        // live "updatedBoardName" recompute.
        $this->boardSlug = $this->nextAvailableBoardSlug($this->boardName, $this->boardId);
        $this->boardGroupId = $this->boardGroupId !== '' ? $this->boardGroupId : null;

        $validated = $this->validate([
            'boardName' => ['required', 'string', 'max:255'],
            'boardSlug' => ['required', 'string', 'max:255', Rule::unique('idea_boards', 'slug')->where('team_id', $teamId)->ignore($this->boardId)],
            'boardDescription' => ['nullable', 'string', 'max:1000'],
            'boardGroupId' => ['nullable', Rule::exists('idea_board_groups', 'id')->where('team_id', $teamId)],
            'boardVisibility' => ['required', Rule::in(array_keys(self::VISIBILITY_OPTIONS))],
            'boardIsActive' => ['required', Rule::in(['1', '0'])],
        ]);

        $attributes = [
            'name' => $validated['boardName'],
            'slug' => $validated['boardSlug'],
            'description' => $validated['boardDescription'] !== '' ? $validated['boardDescription'] : null,
            'board_group_id' => $validated['boardGroupId'] ?: null,
            'visibility' => $validated['boardVisibility'],
            'is_active' => $this->boardIsActive === '1',
        ];

        if ($this->boardId) {
            $this->team->boards()->findOrFail($this->boardId)->update($attributes);
        } else {
            $this->team->boards()->create($attributes + [
                'created_by_user_id' => Auth::id(),
                'sort_order' => (int) $this->team->boards()->max('sort_order') + 1,
            ]);
        }

        unset($this->boards, $this->filteredBoards);
        $this->dispatch('modal-close', name: 'board');
        Flux::toast(variant: 'success', text: __('Board saved.'));
    }

    public function toggleBoard(int $id): void
    {
        $board = $this->team->boards()->findOrFail($id);
        $board->update(['is_active' => ! $board->is_active]);
        unset($this->boards, $this->filteredBoards);
    }

    // ----- Categories -----

    public function newCategory(): void
    {
        $this->reset('categoryId', 'categoryName', 'categorySlug', 'categoryDescription', 'categoryBoardId', 'categoryIsActive');
        $this->resetValidation();
        $this->dispatch('modal-show', name: 'category');
    }

    public function editCategory(int $id): void
    {
        $category = $this->team->categories()->findOrFail($id);

        $this->categoryId = $category->id;
        $this->categoryName = $category->name;
        $this->categorySlug = $category->slug;
        $this->categoryDescription = $category->description ?? '';
        $this->categoryBoardId = (string) $category->board_id;
        $this->categoryIsActive = $category->is_active;

        $this->resetValidation();
        $this->dispatch('modal-show', name: 'category');
    }

    public function saveCategory(): void
    {
        $teamId = $this->team->id;
        $this->categorySlug = Str::slug($this->categorySlug !== '' ? $this->categorySlug : $this->categoryName);

        $validated = $this->validate([
            'categoryName' => ['required', 'string', 'max:255'],
            'categoryBoardId' => ['required', Rule::exists('idea_boards', 'id')->where('team_id', $teamId)],
            'categorySlug' => ['required', 'string', 'max:255', Rule::unique('idea_categories', 'slug')->where('board_id', $this->categoryBoardId)->ignore($this->categoryId)],
            'categoryDescription' => ['nullable', 'string', 'max:1000'],
            'categoryIsActive' => ['boolean'],
        ]);

        $attributes = [
            'board_id' => $validated['categoryBoardId'],
            'name' => $validated['categoryName'],
            'slug' => $validated['categorySlug'],
            'description' => $validated['categoryDescription'] !== '' ? $validated['categoryDescription'] : null,
            'is_active' => $this->categoryIsActive,
        ];

        if ($this->categoryId) {
            $this->team->categories()->findOrFail($this->categoryId)->update($attributes);
        } else {
            $this->team->categories()->create($attributes + [
                'sort_order' => (int) $this->team->categories()->max('sort_order') + 1,
            ]);
        }

        unset($this->categories);
        $this->dispatch('modal-close', name: 'category');
        Flux::toast(variant: 'success', text: __('Category saved.'));
    }

    public function toggleCategory(int $id): void
    {
        $category = $this->team->categories()->findOrFail($id);
        $category->update(['is_active' => ! $category->is_active]);
        unset($this->categories);
    }

    public function quickAddCategory(): void
    {
        $teamId = $this->team->id;

        $validated = $this->validate([
            'quickCategoryName' => ['required', 'string', 'max:255'],
            'quickCategoryBoardId' => ['required', Rule::exists('idea_boards', 'id')->where('team_id', $teamId)],
        ]);

        $baseSlug = Str::slug($validated['quickCategoryName']);
        $slug = $baseSlug;
        $suffix = 1;

        while ($this->team->categories()->where('board_id', $validated['quickCategoryBoardId'])->where('slug', $slug)->exists()) {
            $suffix++;
            $slug = "{$baseSlug}-{$suffix}";
        }

        $this->team->categories()->create([
            'board_id' => $validated['quickCategoryBoardId'],
            'name' => $validated['quickCategoryName'],
            'slug' => $slug,
            'is_active' => true,
            'sort_order' => (int) $this->team->categories()->max('sort_order') + 1,
        ]);

        $this->quickCategoryName = '';

        unset($this->categories);
        Flux::toast(variant: 'success', text: __('Category saved.'));
    }

    // ----- Members -----

    public function newMember(): void
    {
        Gate::authorize('addMember', $this->team);

        $this->reset('memberSearch', 'memberUserId', 'memberUserName', 'memberRole');
        $this->resetValidation();
        $this->dispatch('modal-show', name: 'member');
    }

    public function selectMember(int $userId): void
    {
        $user = User::findOrFail($userId);

        $this->memberUserId = $user->id;
        $this->memberUserName = $user->name;
        $this->memberSearch = '';
    }

    public function clearSelectedMember(): void
    {
        $this->reset('memberUserId', 'memberUserName');
    }

    public function saveMember(): void
    {
        Gate::authorize('addMember', $this->team);

        $validated = $this->validate([
            'memberUserId' => ['required', 'integer', Rule::exists('users', 'id')],
            'memberRole' => ['required', 'string', Rule::enum(TeamRole::class)],
        ]);

        if ($this->team->members()->where('users.id', $validated['memberUserId'])->exists()) {
            $this->addError('memberUserId', __('This user is already a contributor of the organization.'));

            return;
        }

        $this->team->members()->attach($validated['memberUserId'], [
            'role' => TeamRole::from($validated['memberRole']),
        ]);

        unset($this->members);
        $this->dispatch('modal-close', name: 'member');
        Flux::toast(variant: 'success', text: __('Contributor added.'));
    }

    public function confirmRemoveMember(int $userId): void
    {
        Gate::authorize('removeMember', $this->team);

        $member = $this->team->members()->findOrFail($userId);

        $this->removeMemberId = $member->id;
        $this->removeMemberName = $member->name;
        $this->dispatch('modal-show', name: 'revoke-member-access');
    }

    /**
     * Revoke a member's access to the organization. Their ideas and comments
     * are left untouched — only the team membership is removed.
     */
    public function removeMember(): void
    {
        Gate::authorize('removeMember', $this->team);

        $this->team->memberships()->where('user_id', $this->removeMemberId)->delete();

        $user = User::find($this->removeMemberId);

        if ($user && $user->isCurrentTeam($this->team)) {
            $user->switchTeam($user->personalTeam());
        }

        unset($this->members);
        $this->dispatch('modal-close', name: 'revoke-member-access');
        Flux::toast(variant: 'success', text: __('Access revoked.'));
    }
}; ?>

@push('breadcrumbs')
    <x-breadcrumbs :items="[
        ['label' => __('Organization Settings'), 'href' => null],
    ]" />
@endpush

<section class="mx-auto w-full  px-6 pb-7 lg:px-8">
    <div class="flex flex-col gap-1">
        <flux:heading size="xl">{{ __('Organization Settings') }}</flux:heading>
        <flux:text class="text-slate-600 dark:text-slate-500">{{ __('Manage boards, groups, categories, contributors and settings for :team.', ['team' => $this->team->name]) }}</flux:text>
    </div>

    {{-- Tabs --}}
    <x-sticky-toolbar class="mt-6">
        <nav
            class="relative -mb-px flex gap-6 border-b border-zinc-200 dark:border-zinc-700"
            data-tab="{{ $tab }}"
            x-data="{
                tab: null,
                indicator: { left: 0, width: 0 },
                updateIndicator() {
                    let el = this.$refs['tab-' + this.tab];
                    if (el) {
                        this.indicator = { left: el.offsetLeft, width: el.offsetWidth };
                    }
                },
            }"
            x-init="tab = $el.dataset.tab; updateIndicator()"
            x-effect="tab; updateIndicator()"
        >
            <div
                class="absolute bottom-0 h-0.5 rounded-full bg-indigo-500 transition-all duration-300 ease-out"
                :style="`transform: translateX(${indicator.left}px); width: ${indicator.width}px`"
            ></div>

            @foreach ([
                'boards' => __('Boards'),
                'groups' => __('Groups'),
                'categories' => __('Categories'),
                'members' => __('Contributors'),
                // 'integrations' => __('Integrations'), // hidden — replaced by the Settings tab
                'settings' => __('Settings'),
            ] as $key => $label)
                <button
                    type="button"
                    x-ref="tab-{{ $key }}"
                    x-on:click="tab = '{{ $key }}'"
                    wire:click="$set('tab', '{{ $key }}')"
                    @class([
                        'px-1 py-3 text-sm font-medium transition-colors',
                        'text-indigo-600 dark:text-indigo-400' => $tab === $key,
                        'text-slate-600 hover:text-slate-900 dark:text-slate-500 dark:hover:text-slate-300' => $tab !== $key,
                    ])
                    data-test="tab-{{ $key }}"
                >
                    {{ $label }}
                </button>
            @endforeach
        </nav>
    </x-sticky-toolbar>

    <flux:text class="mt-4 text-sm text-slate-600 dark:text-slate-500">
        @switch($tab)
            @case('groups'){{ __('Groups organize related boards together.') }}@break
            @case('categories'){{ __('Categories classify ideas within a board.') }}@break
            @case('members'){{ __('People with access to this organization.') }}@break
            {{-- @case('integrations'){{ __('Connect external tools to your idea workflow.') }}@break --}}
            @case('settings'){{ __('Manage your organization\'s name and idea submission preferences.') }}@break
            @default{{ __('Boards are where employees submit ideas. Assign each board to a group.') }}
        @endswitch
    </flux:text>

    {{-- Boards --}}
    @if ($tab === 'boards')
        <div class="mt-5">
            <div class="flex items-center justify-between gap-2">
                <select
                    wire:model.live="boardGroupFilter"
                    class="rounded-lg border border-gray-200 bg-white py-1.5 ps-3 pe-8 text-sm text-slate-800 focus:border-indigo-500 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-900 dark:text-slate-400"
                    data-test="board-group-filter"
                >
                    <option value="">{{ __('All Groups') }}</option>
                    @foreach ($this->boardGroups as $group)
                        <option value="{{ $group->id }}">{{ $group->name }}</option>
                    @endforeach
                </select>

                <flux:button wire:click="newBoard" variant="primary" icon="plus" size="sm" data-test="new-board">{{ __('New board') }}</flux:button>
            </div>
            <div class="mt-4 space-y-2">
                @forelse ($this->filteredBoards as $board)
                    <div class="flex items-center justify-between gap-4 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" wire:key="board-{{ $board->id }}" data-test="board-row">
                        <div class="flex min-w-0 items-center gap-3">
                            <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-indigo-50 text-sm font-semibold text-indigo-600 dark:bg-indigo-500/10 dark:text-indigo-300">{{ strtoupper(mb_substr($board->name, 0, 1)) }}</span>
                            <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-bold text-slate-900 dark:text-slate-200">{{ $board->name }}</span>
                                <flux:badge color="zinc" size="sm" variant="outline">{{ self::VISIBILITY_OPTIONS[$board->visibility] ?? ucfirst($board->visibility) }}</flux:badge>
                                @unless ($board->is_active)
                                    <flux:badge color="zinc" size="sm">{{ __('Inactive') }}</flux:badge>
                                @endunless
                            </div>
                            <flux:text class="flex flex-wrap items-center gap-2 text-sm text-slate-600 dark:text-slate-500">
                                <span class="font-semibold">{{ trans_choice(':count idea|:count ideas', $board->ideas_count, ['count' => $board->ideas_count]) }}</span>
                                <flux:separator vertical class="bg-indigo-700! dark:bg-indigo-200!" />
                                <flux:badge size="sm" class="bg-gray-100! text-gray-700! dark:bg-zinc-700! dark:text-zinc-200!">{{ $board->boardGroup?->name ?? __('No group') }}</flux:badge>
                                @if ($board->description)
                                    <flux:separator vertical class="bg-indigo-700! dark:bg-indigo-200!" />
                                    <span>{{ $board->description }}</span>
                                @endif
                            </flux:text>
                            </div>
                        </div>
                        <div class="flex shrink-0 items-center gap-1">
                            <flux:button
                                wire:click="toggleBoard({{ $board->id }})"
                                variant="outline"
                                size="sm"
                                data-test="toggle-board"
                                :class="$board->is_active
                                    ? 'text-rose-700! border-rose-700! dark:text-rose-400! dark:border-rose-800!'
                                    : 'text-teal-700! border-teal-700! dark:text-teal-400! dark:border-teal-800!'"
                            >
                                {{ $board->is_active ? __('Deactivate') : __('Activate') }}
                            </flux:button>
                            <flux:button wire:click="editBoard({{ $board->id }})" variant="ghost" size="sm" icon="pencil" data-test="edit-board" />
                        </div>
                    </div>
                @empty
                    <div class="rounded-lg border border-dashed border-zinc-300 py-10 text-center dark:border-zinc-700">
                        <flux:icon.chalkboard class="mx-auto size-8 text-slate-400 dark:text-slate-700" />
                        <flux:text class="mt-2 text-slate-600 dark:text-slate-500">{{ __('No boards yet.') }}</flux:text>
                    </div>
                @endforelse
            </div>
        </div>
    @endif

    {{-- Groups --}}
    @if ($tab === 'groups')
        <div class="mt-5">
            <div class="flex items-center justify-end gap-2">
                <flux:button wire:click="newBoardGroup" variant="primary" icon="plus" size="sm" data-test="new-group">{{ __('New group') }}</flux:button>
            </div>
            <div class="mt-4 space-y-2">
                @forelse ($this->boardGroups as $group)
                    <div class="flex items-center justify-between gap-4 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" wire:key="group-{{ $group->id }}" data-test="group-row">
                        <div class="flex min-w-0 items-center gap-3">
                            <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-indigo-50 text-sm font-semibold text-indigo-600 dark:bg-indigo-500/10 dark:text-indigo-300">{{ strtoupper(mb_substr($group->name, 0, 1)) }}</span>
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="font-bold text-slate-900 dark:text-slate-200">{{ $group->name }}</span>
                                    @unless ($group->is_active)
                                        <flux:badge color="zinc" size="sm">{{ __('Inactive') }}</flux:badge>
                                    @endunless
                                </div>
                                <flux:text class="flex flex-wrap items-center gap-2 text-sm text-slate-600 dark:text-slate-500">
                                    @php
                                        $groupSubheadingSegments = array_filter([
                                            trans_choice(':count board|:count boards', $group->boards_count, ['count' => $group->boards_count]),
                                            $group->description,
                                        ]);
                                    @endphp
                                    @foreach ($groupSubheadingSegments as $segment)
                                        @if ($loop->first)<span class="font-semibold">{{ $segment }}</span>@else<span>{{ $segment }}</span>@endif
                                        @unless ($loop->last)<flux:separator vertical class="bg-indigo-700! dark:bg-indigo-200!" />@endunless
                                    @endforeach
                                </flux:text>
                            </div>
                        </div>
                        <div class="flex shrink-0 items-center gap-1">
                            <flux:button
                                wire:click="toggleBoardGroup({{ $group->id }})"
                                variant="outline"
                                size="sm"
                                data-test="toggle-group"
                                :class="$group->is_active
                                    ? 'text-rose-700! border-rose-700! dark:text-rose-400! dark:border-rose-800!'
                                    : 'text-teal-700! border-teal-700! dark:text-teal-400! dark:border-teal-800!'"
                            >
                                {{ $group->is_active ? __('Deactivate') : __('Activate') }}
                            </flux:button>
                            <flux:button wire:click="editBoardGroup({{ $group->id }})" variant="ghost" size="sm" icon="pencil" data-test="edit-group" />
                        </div>
                    </div>
                @empty
                    <div class="rounded-lg border border-dashed border-zinc-300 py-10 text-center dark:border-zinc-700">
                        <flux:icon.chalkboard class="mx-auto size-8 text-slate-400 dark:text-slate-700" />
                        <flux:text class="mt-2 text-slate-600 dark:text-slate-500">{{ __('No board groups yet.') }}</flux:text>
                    </div>
                @endforelse
            </div>
        </div>
    @endif

    {{-- Categories --}}
    @if ($tab === 'categories')
        @php
            $__categoryDotColors = ['bg-indigo-500', 'bg-emerald-500', 'bg-teal-500', 'bg-violet-500', 'bg-pink-500', 'bg-amber-500'];
        @endphp
        <div class="mt-5">
            <div class="flex flex-wrap gap-2">
                @forelse ($this->categories as $category)
                    <button
                        type="button"
                        wire:click="editCategory({{ $category->id }})"
                        wire:key="category-{{ $category->id }}"
                        data-test="category-row"
                        @class([
                            'flex items-center gap-2 rounded-full border border-zinc-200 bg-white px-3 py-1.5 text-sm transition hover:border-zinc-300 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-zinc-600',
                            'opacity-50' => ! $category->is_active,
                        ])
                    >
                        <span class="size-2 rounded-full {{ $__categoryDotColors[$category->id % count($__categoryDotColors)] }}"></span>
                        <span class="font-bold text-slate-900 dark:text-slate-200">{{ $category->name }}</span>
                        <flux:badge color="zinc" size="sm" variant="outline">{{ $category->ideas_count }}</flux:badge>
                    </button>
                @empty
                    <flux:text class="text-slate-600 dark:text-slate-500">{{ __('No categories yet.') }}</flux:text>
                @endforelse
            </div>

            <div class="mt-4 flex items-center gap-2">
                <flux:select wire:model="quickCategoryBoardId" class="w-44" :placeholder="__('Board')" size="sm" data-test="quick-category-board">
                    @foreach ($this->boards as $board)
                        <flux:select.option value="{{ $board->id }}">{{ $board->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="quickCategoryName" :placeholder="__('New category...')" class="max-w-xs" size="sm" data-test="quick-category-name" />
                <flux:button wire:click="quickAddCategory" variant="primary" size="sm" data-test="quick-add-category">{{ __('Add') }}</flux:button>
            </div>
        </div>
    @endif

    {{-- Members --}}
    @if ($tab === 'members')
        <div class="mt-5 space-y-4">
        @if ($this->canAddMember)
            <div class="flex items-center justify-end gap-2">
                <flux:button wire:click="newMember" variant="primary" icon="plus" size="sm" data-test="new-member">{{ __('New contributor') }}</flux:button>
            </div>
        @endif
        <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
            <table class="w-full text-sm">
                <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                    <tr class="text-xs font-semibold tracking-wide text-slate-600 uppercase dark:text-slate-500">
                        <th class="px-4 py-2.5 text-start">{{ __('Contributor') }}</th>
                        <th class="px-4 py-2.5 text-start">{{ __('Email') }}</th>
                        <th class="px-4 py-2.5 text-start">{{ __('Role') }}</th>
                        @if ($this->canRemoveMember)
                            <th class="px-4 py-2.5 text-end">{{ __('Actions') }}</th>
                        @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach ($this->members as $member)
                        <tr wire:key="member-{{ $member->id }}" data-test="member-row">
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-3">
                                    <flux:avatar :name="$member->name" size="xs" color="auto" color:seed="{{ $member->id }}" />
                                    <span class="font-bold text-slate-900 dark:text-slate-200">{{ $member->name }}</span>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-slate-600 dark:text-slate-500">{{ $member->email }}</td>
                            <td class="px-4 py-3">
                                <flux:badge size="sm" :color="$member->pivot->role->badgeColor()">
                                    {{ $member->pivot->role === \App\Enums\TeamRole::Owner ? __('Owner') : $member->pivot->role->label() }}
                                </flux:badge>
                            </td>
                            @if ($this->canRemoveMember)
                                <td class="px-4 py-3 text-end">
                                    @if ($member->pivot->role !== \App\Enums\TeamRole::Owner)
                                        <flux:tooltip content="{{ __('Revoke Access') }}">
                                            <flux:button
                                                wire:click="confirmRemoveMember({{ $member->id }})"
                                                variant="ghost"
                                                size="sm"
                                                icon="x-mark"
                                                class="text-red-600! hover:text-red-700!"
                                                data-test="revoke-member-access"
                                            />
                                        </flux:tooltip>
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        </div>
    @endif

    {{--
        Integrations — hidden for now, replaced by the Settings tab below.
    @if ($tab === 'integrations')
        <div class="mt-5 rounded-lg border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    <flux:icon.code-bracket-square class="size-8 text-slate-900 dark:text-slate-300" />
                    <div>
                        <flux:heading>{{ __('GitHub') }}</flux:heading>
                        <flux:text class="text-sm text-slate-600 dark:text-slate-500">{{ __('Turn approved ideas into tracked issues.') }}</flux:text>
                    </div>
                </div>
                <flux:badge color="green" size="sm">{{ __('Connected') }}</flux:badge>
            </div>

            <div class="mt-5 space-y-1">
                <flux:text class="text-sm text-slate-600 dark:text-slate-500">{{ __('Default repository') }}</flux:text>
                <flux:input value="rudaca/rudaca-voice" disabled data-test="integration-repo" />
            </div>

            <div class="mt-5 flex items-center justify-between gap-4">
                <div>
                    <flux:text class="font-medium text-slate-900 dark:text-slate-200">{{ __('Auto-create issues on approval') }}</flux:text>
                    <flux:text class="text-sm text-slate-600 dark:text-slate-500">{{ __('Draft an issue whenever an idea is approved for development.') }}</flux:text>
                </div>
                <flux:switch :checked="false" disabled data-test="integration-auto-create" />
            </div>
        </div>
    @endif
    --}}

    {{-- Settings --}}
    @if ($tab === 'settings')
        <div class="mt-5 max-w-lg">
            <form wire:submit="saveTeamSettings" class="space-y-6">
                <flux:input
                    wire:model="orgTeamName"
                    :label="__('Organization name')"
                    required
                    data-test="org-team-name-input"
                />

                <flux:checkbox
                    wire:model="orgAllowAnonymousIdeas"
                    :label="__('Allow anonymous posting of ideas')"
                    :description="__('When disabled, employees won\'t see the option to submit ideas anonymously.')"
                    data-test="org-allow-anonymous-ideas"
                />

                <flux:button variant="primary" type="submit" data-test="org-settings-save-button">
                    {{ __('Save') }}
                </flux:button>
            </form>
        </div>
    @endif

    {{-- Board group modal --}}
    <flux:modal name="board-group" class="w-full max-w-xl" :dismissible="false" data-test="group-modal">
        <form wire:submit="saveBoardGroup" class="space-y-5">
            <flux:heading size="lg">{{ $groupId ? __('Edit board group') : __('New board group') }}</flux:heading>
            <flux:input wire:model.live="groupName" :label="__('Name')" required data-test="group-name-input" />
            <flux:input wire:model="groupSlug" :label="__('Slug')" :description="__('Automatically generated from the name — feel free to customize it.')" data-test="group-slug-input" />
            <flux:textarea wire:model="groupDescription" :label="__('Description')" rows="2" />
            <flux:checkbox wire:model="groupIsActive" :label="__('Active')" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="save-group">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Board modal --}}
    <flux:modal name="board" class="w-full max-w-xl" scroll="body" :dismissible="false" data-test="board-modal">
        <form wire:submit="saveBoard" class="space-y-5">
            <flux:heading size="lg">{{ $boardId ? __('Edit board') : __('New board') }}</flux:heading>
            <flux:input wire:model.live="boardName" :label="__('Name')" required data-test="board-name-input" />
            <flux:select wire:model="boardGroupId" :label="__('Board group')" :placeholder="__('No group')" data-test="board-group-select">
                @foreach ($this->assignableBoardGroups as $group)
                    <flux:select.option value="{{ $group->id }}">{{ $group->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input wire:model="boardSlug" :label="__('Slug')" :description="__('Automatically generated from the name.')" readonly data-test="board-slug-input" />
            <flux:textarea wire:model="boardDescription" :label="__('Description')" rows="2" :placeholder="__('Optional')" data-test="board-description-input" />
            <div class="grid grid-cols-2 gap-4">
                <flux:radio.group wire:model="boardVisibility" :label="__('Visibility')" data-test="board-visibility-radio">
                    @foreach (self::VISIBILITY_OPTIONS as $value => $label)
                        <flux:radio value="{{ $value }}" label="{{ $label }}" />
                    @endforeach
                </flux:radio.group>
                <flux:radio.group wire:model="boardIsActive" :label="__('Active')" data-test="board-active-radio">
                    <flux:radio value="1" label="{{ __('Yes') }}" />
                    <flux:radio value="0" label="{{ __('No') }}" />
                </flux:radio.group>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="save-board">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Category modal --}}
    <flux:modal name="category" class="max-w-lg" :dismissible="false" data-test="category-modal">
        <form wire:submit="saveCategory" class="space-y-5">
            <flux:heading size="lg">{{ $categoryId ? __('Edit category') : __('New category') }}</flux:heading>
            <flux:input wire:model="categoryName" :label="__('Name')" required />
            <flux:input wire:model="categorySlug" :label="__('Slug')" :description="__('Leave blank to generate from the name.')" />
            <flux:textarea wire:model="categoryDescription" :label="__('Description')" rows="2" />
            <flux:select wire:model="categoryBoardId" :label="__('Board')" :placeholder="__('Choose a board')" required>
                @foreach ($this->boards as $board)
                    <flux:select.option value="{{ $board->id }}">{{ $board->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:checkbox wire:model="categoryIsActive" :label="__('Active')" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="save-category">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- New member modal --}}
    @if ($this->canAddMember)
        <flux:modal name="member" class="w-full max-w-2xl" :dismissible="false" data-test="member-modal">
            <form wire:submit="saveMember" class="space-y-5">
                <flux:heading size="lg">{{ __('New contributor') }}</flux:heading>

                @if ($memberUserId)
                    <div class="flex items-center justify-between rounded-lg border border-zinc-200 bg-zinc-50 p-3 dark:border-zinc-700 dark:bg-zinc-800">
                        <div class="flex items-center gap-3">
                            <flux:avatar :name="$memberUserName" size="xs" color="auto" color:seed="{{ $memberUserId }}" />
                            <span class="font-medium text-slate-900 dark:text-slate-200">{{ $memberUserName }}</span>
                        </div>
                        <flux:button wire:click="clearSelectedMember" variant="ghost" size="sm" data-test="change-member">{{ __('Change') }}</flux:button>
                    </div>
                @else
                    <div class="space-y-2">
                        <flux:input
                            wire:model.live.debounce.300ms="memberSearch"
                            :label="__('Search users')"
                            :placeholder="__('Search by name or email...')"
                            data-test="member-search-input"
                        />
                        <flux:error name="memberUserId" />

                        @if (trim($memberSearch) !== '')
                            <div class="max-h-56 space-y-1 overflow-y-auto rounded-lg border border-zinc-200 p-1 dark:border-zinc-700">
                                @forelse ($this->searchableUsers as $user)
                                    <button
                                        type="button"
                                        wire:click="selectMember({{ $user->id }})"
                                        wire:key="searchable-user-{{ $user->id }}"
                                        class="flex w-full items-center gap-3 rounded-md p-2 text-start hover:bg-zinc-100 dark:hover:bg-zinc-800"
                                        data-test="searchable-user-option"
                                    >
                                        <flux:avatar :name="$user->name" size="xs" color="auto" color:seed="{{ $user->id }}" />
                                        <div class="min-w-0">
                                            <div class="truncate font-medium text-slate-900 dark:text-slate-200">{{ $user->name }}</div>
                                            <div class="truncate text-sm text-slate-600 dark:text-slate-500">{{ $user->email }}</div>
                                        </div>
                                    </button>
                                @empty
                                    <div class="p-2 text-sm text-slate-600 dark:text-slate-500">{{ __('No matching users found.') }}</div>
                                @endforelse
                            </div>
                        @endif
                    </div>
                @endif

                <flux:select wire:model="memberRole" :label="__('Role')" data-test="member-role-select">
                    @foreach ($this->availableRoles as $role)
                        <flux:select.option value="{{ $role['value'] }}">{{ $role['label'] }}</flux:select.option>
                    @endforeach
                </flux:select>

                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button variant="primary" type="submit" data-test="save-member">{{ __('Add contributor') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif

    {{-- Revoke member access modal --}}
    @if ($this->canRemoveMember)
        <flux:modal name="revoke-member-access" :dismissible="false" class="max-w-lg" data-test="revoke-member-modal">
            <form wire:submit="removeMember" class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Revoke access') }}</flux:heading>
                    <flux:subheading>
                        {{ __('Are you sure you want to revoke :name\'s access to this organization?', ['name' => $removeMemberName]) }}
                    </flux:subheading>
                </div>

                <flux:callout variant="warning" icon="exclamation-triangle" data-test="revoke-member-warning">
                    <flux:callout.text>
                        {{ __('The ideas and comments made by this user will remain.') }}
                    </flux:callout.text>
                </flux:callout>

                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button variant="danger" type="submit" data-test="revoke-member-confirm">{{ __('Revoke access') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</section>
