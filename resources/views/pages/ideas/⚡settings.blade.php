<?php

use App\Models\Team;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Boards & Categories')] class extends Component {
    /** @var array<string, string> */
    public const VISIBILITY_OPTIONS = [
        'public' => 'Public',
        'internal' => 'Internal',
        'restricted' => 'Restricted',
        'private' => 'Private',
    ];

    #[Url(as: 'tab')]
    public string $tab = 'groups';

    // --- Board group form ---
    public ?int $groupId = null;

    public string $groupName = '';

    public string $groupSlug = '';

    public string $groupDescription = '';

    public bool $groupIsActive = true;

    // --- Board form ---
    public ?int $boardId = null;

    public string $boardName = '';

    public string $boardSlug = '';

    public string $boardDescription = '';

    public ?string $boardGroupId = null;

    public string $boardVisibility = 'internal';

    public bool $boardIsActive = true;

    // --- Category form ---
    public ?int $categoryId = null;

    public string $categoryName = '';

    public string $categorySlug = '';

    public string $categoryDescription = '';

    public string $categoryBoardId = '';

    public bool $categoryIsActive = true;

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
        ];
    }

    // ----- Board groups -----

    public function newBoardGroup(): void
    {
        $this->reset('groupId', 'groupName', 'groupSlug', 'groupDescription', 'groupIsActive');
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'board-group');
    }

    public function editBoardGroup(int $id): void
    {
        $group = $this->team->boardGroups()->findOrFail($id);

        $this->groupId = $group->id;
        $this->groupName = $group->name;
        $this->groupSlug = $group->slug;
        $this->groupDescription = $group->description ?? '';
        $this->groupIsActive = $group->is_active;

        $this->resetValidation();
        $this->dispatch('open-modal', name: 'board-group');
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
        $this->dispatch('close-modal', name: 'board-group');
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
        $this->dispatch('open-modal', name: 'board');
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
        $this->boardIsActive = $board->is_active;

        $this->resetValidation();
        $this->dispatch('open-modal', name: 'board');
    }

    public function saveBoard(): void
    {
        $teamId = $this->team->id;
        $this->boardSlug = Str::slug($this->boardSlug !== '' ? $this->boardSlug : $this->boardName);
        $this->boardGroupId = $this->boardGroupId !== '' ? $this->boardGroupId : null;

        $validated = $this->validate([
            'boardName' => ['required', 'string', 'max:255'],
            'boardSlug' => ['required', 'string', 'max:255', Rule::unique('idea_boards', 'slug')->where('team_id', $teamId)->ignore($this->boardId)],
            'boardDescription' => ['nullable', 'string', 'max:1000'],
            'boardGroupId' => ['nullable', Rule::exists('idea_board_groups', 'id')->where('team_id', $teamId)],
            'boardVisibility' => ['required', Rule::in(array_keys(self::VISIBILITY_OPTIONS))],
            'boardIsActive' => ['boolean'],
        ]);

        $attributes = [
            'name' => $validated['boardName'],
            'slug' => $validated['boardSlug'],
            'description' => $validated['boardDescription'] !== '' ? $validated['boardDescription'] : null,
            'board_group_id' => $validated['boardGroupId'] ?: null,
            'visibility' => $validated['boardVisibility'],
            'is_active' => $this->boardIsActive,
        ];

        if ($this->boardId) {
            $this->team->boards()->findOrFail($this->boardId)->update($attributes);
        } else {
            $this->team->boards()->create($attributes + [
                'created_by_user_id' => Auth::id(),
                'sort_order' => (int) $this->team->boards()->max('sort_order') + 1,
            ]);
        }

        unset($this->boards);
        $this->dispatch('close-modal', name: 'board');
        Flux::toast(variant: 'success', text: __('Board saved.'));
    }

    public function toggleBoard(int $id): void
    {
        $board = $this->team->boards()->findOrFail($id);
        $board->update(['is_active' => ! $board->is_active]);
        unset($this->boards);
    }

    // ----- Categories -----

    public function newCategory(): void
    {
        $this->reset('categoryId', 'categoryName', 'categorySlug', 'categoryDescription', 'categoryBoardId', 'categoryIsActive');
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'category');
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
        $this->dispatch('open-modal', name: 'category');
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
        $this->dispatch('close-modal', name: 'category');
        Flux::toast(variant: 'success', text: __('Category saved.'));
    }

    public function toggleCategory(int $id): void
    {
        $category = $this->team->categories()->findOrFail($id);
        $category->update(['is_active' => ! $category->is_active]);
        unset($this->categories);
    }
}; ?>

@push('breadcrumbs')
    <x-breadcrumbs :items="[
        ['label' => __('Boards & Categories'), 'href' => null],
    ]" />
@endpush

<section class="mx-auto w-full  px-6 pb-7 lg:px-8">
    <div class="flex flex-col gap-1">
        <flux:heading size="xl">{{ __('Boards & Categories') }}</flux:heading>
        <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __('Organize where employees submit ideas.') }}</flux:text>
    </div>

    {{-- Tabs --}}
    <div class="mt-6 border-b border-zinc-200 dark:border-zinc-700">
        <nav
            class="relative -mb-px flex gap-6"
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

            @foreach (['groups' => __('Board Groups'), 'boards' => __('Boards'), 'categories' => __('Categories')] as $key => $label)
                <button
                    type="button"
                    x-ref="tab-{{ $key }}"
                    x-on:click="tab = '{{ $key }}'"
                    wire:click="$set('tab', '{{ $key }}')"
                    @class([
                        'px-1 py-3 text-sm font-medium transition-colors',
                        'text-indigo-600 dark:text-indigo-400' => $tab === $key,
                        'text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200' => $tab !== $key,
                    ])
                    data-test="tab-{{ $key }}"
                >
                    {{ $label }}
                </button>
            @endforeach
        </nav>
    </div>

    <flux:text class="mt-4 text-sm text-zinc-500 dark:text-zinc-400">
        @switch($tab)
            @case('boards'){{ __('Boards are where employees submit ideas. Assign each board to a group.') }}@break
            @case('categories'){{ __('Categories classify ideas within a board.') }}@break
            @default{{ __('Groups are the top-level areas that organize your boards.') }}
        @endswitch
    </flux:text>

    {{-- Board Groups --}}
    @if ($tab === 'groups')
        <div class="mt-5">
            <div class="flex items-center justify-end">
                <flux:button wire:click="newBoardGroup" variant="primary" icon="plus" size="sm" data-test="new-group">{{ __('New group') }}</flux:button>
            </div>
            <div class="mt-4 space-y-2">
                @forelse ($this->boardGroups as $group)
                    <div class="flex items-center justify-between gap-4 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" wire:key="group-{{ $group->id }}" data-test="group-row">
                        <div class="flex min-w-0 items-center gap-3">
                            <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-indigo-50 text-sm font-semibold text-indigo-600 dark:bg-indigo-500/10 dark:text-indigo-300">{{ strtoupper(mb_substr($group->name, 0, 1)) }}</span>
                            <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $group->name }}</span>
                                @unless ($group->is_active)
                                    <flux:badge color="zinc" size="sm">{{ __('Inactive') }}</flux:badge>
                                @endunless
                            </div>
                            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                                {{ implode(' · ', array_filter([$group->slug, trans_choice(':count board|:count boards', $group->boards_count, ['count' => $group->boards_count]), $group->description])) }}
                            </flux:text>
                            </div>
                        </div>
                        <div class="flex shrink-0 items-center gap-1">
                            <flux:button wire:click="toggleBoardGroup({{ $group->id }})" variant="ghost" size="sm">
                                {{ $group->is_active ? __('Deactivate') : __('Activate') }}
                            </flux:button>
                            <flux:button wire:click="editBoardGroup({{ $group->id }})" variant="ghost" size="sm" icon="pencil" data-test="edit-group" />
                        </div>
                    </div>
                @empty
                    <div class="rounded-lg border border-dashed border-zinc-300 py-10 text-center dark:border-zinc-700">
                        <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __('No board groups yet.') }}</flux:text>
                    </div>
                @endforelse
            </div>
        </div>
    @endif

    {{-- Boards --}}
    @if ($tab === 'boards')
        <div class="mt-5">
            <div class="flex items-center justify-end">
                <flux:button wire:click="newBoard" variant="primary" icon="plus" size="sm" data-test="new-board">{{ __('New board') }}</flux:button>
            </div>
            <div class="mt-4 space-y-2">
                @forelse ($this->boards as $board)
                    <div class="flex items-center justify-between gap-4 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" wire:key="board-{{ $board->id }}" data-test="board-row">
                        <div class="flex min-w-0 items-center gap-3">
                            <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-indigo-50 text-sm font-semibold text-indigo-600 dark:bg-indigo-500/10 dark:text-indigo-300">{{ strtoupper(mb_substr($board->name, 0, 1)) }}</span>
                            <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $board->name }}</span>
                                <flux:badge color="zinc" size="sm" variant="outline">{{ self::VISIBILITY_OPTIONS[$board->visibility] ?? ucfirst($board->visibility) }}</flux:badge>
                                @unless ($board->is_active)
                                    <flux:badge color="zinc" size="sm">{{ __('Inactive') }}</flux:badge>
                                @endunless
                            </div>
                            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                                {{ implode(' · ', array_filter([
                                    $board->boardGroup?->name ?? __('No group'),
                                    trans_choice(':count idea|:count ideas', $board->ideas_count, ['count' => $board->ideas_count]),
                                    $board->description,
                                ])) }}
                            </flux:text>
                            </div>
                        </div>
                        <div class="flex shrink-0 items-center gap-1">
                            <flux:button wire:click="toggleBoard({{ $board->id }})" variant="ghost" size="sm">
                                {{ $board->is_active ? __('Deactivate') : __('Activate') }}
                            </flux:button>
                            <flux:button wire:click="editBoard({{ $board->id }})" variant="ghost" size="sm" icon="pencil" data-test="edit-board" />
                        </div>
                    </div>
                @empty
                    <div class="rounded-lg border border-dashed border-zinc-300 py-10 text-center dark:border-zinc-700">
                        <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __('No boards yet.') }}</flux:text>
                    </div>
                @endforelse
            </div>
        </div>
    @endif

    {{-- Categories --}}
    @if ($tab === 'categories')
        <div class="mt-5">
            <div class="flex items-center justify-end">
                <flux:button wire:click="newCategory" variant="primary" icon="plus" size="sm" data-test="new-category">{{ __('New category') }}</flux:button>
            </div>
            <div class="mt-4 space-y-2">
                @forelse ($this->categories as $category)
                    <div class="flex items-center justify-between gap-4 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" wire:key="category-{{ $category->id }}" data-test="category-row">
                        <div class="flex min-w-0 items-center gap-3">
                            <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-indigo-50 text-sm font-semibold text-indigo-600 dark:bg-indigo-500/10 dark:text-indigo-300">{{ strtoupper(mb_substr($category->name, 0, 1)) }}</span>
                            <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $category->name }}</span>
                                @unless ($category->is_active)
                                    <flux:badge color="zinc" size="sm">{{ __('Inactive') }}</flux:badge>
                                @endunless
                            </div>
                            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                                {{ implode(' · ', array_filter([
                                    $category->board?->name,
                                    trans_choice(':count idea|:count ideas', $category->ideas_count, ['count' => $category->ideas_count]),
                                    $category->description,
                                ])) }}
                            </flux:text>
                            </div>
                        </div>
                        <div class="flex shrink-0 items-center gap-1">
                            <flux:button wire:click="toggleCategory({{ $category->id }})" variant="ghost" size="sm">
                                {{ $category->is_active ? __('Deactivate') : __('Activate') }}
                            </flux:button>
                            <flux:button wire:click="editCategory({{ $category->id }})" variant="ghost" size="sm" icon="pencil" data-test="edit-category" />
                        </div>
                    </div>
                @empty
                    <div class="rounded-lg border border-dashed border-zinc-300 py-10 text-center dark:border-zinc-700">
                        <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __('No categories yet.') }}</flux:text>
                    </div>
                @endforelse
            </div>
        </div>
    @endif

    {{-- Board group modal --}}
    <flux:modal name="board-group" class="max-w-lg" data-test="group-modal">
        <form wire:submit="saveBoardGroup" class="space-y-5">
            <flux:heading size="lg">{{ $groupId ? __('Edit board group') : __('New board group') }}</flux:heading>
            <flux:input wire:model="groupName" :label="__('Name')" required />
            <flux:input wire:model="groupSlug" :label="__('Slug')" :description="__('Leave blank to generate from the name.')" />
            <flux:textarea wire:model="groupDescription" :label="__('Description')" rows="2" />
            <flux:checkbox wire:model="groupIsActive" :label="__('Active')" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="save-group">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Board modal --}}
    <flux:modal name="board" class="max-w-lg" data-test="board-modal">
        <form wire:submit="saveBoard" class="space-y-5">
            <flux:heading size="lg">{{ $boardId ? __('Edit board') : __('New board') }}</flux:heading>
            <flux:input wire:model="boardName" :label="__('Name')" required />
            <flux:input wire:model="boardSlug" :label="__('Slug')" :description="__('Leave blank to generate from the name.')" />
            <flux:textarea wire:model="boardDescription" :label="__('Description')" rows="2" />
            <flux:select wire:model="boardGroupId" :label="__('Board group')" :placeholder="__('No group')">
                @foreach ($this->assignableBoardGroups as $group)
                    <flux:select.option value="{{ $group->id }}">{{ $group->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select wire:model="boardVisibility" :label="__('Visibility')">
                @foreach (self::VISIBILITY_OPTIONS as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:checkbox wire:model="boardIsActive" :label="__('Active')" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="save-board">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Category modal --}}
    <flux:modal name="category" class="max-w-lg" data-test="category-modal">
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
</section>
