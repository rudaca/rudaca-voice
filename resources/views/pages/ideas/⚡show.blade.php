<?php

use App\Enums\TeamRole;
use App\Models\Idea;
use App\Models\IdeaComment;
use App\Models\IdeaStatusHistory;
use App\Models\IdeaVote;
use App\Models\Team;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Title('Idea')] class extends Component {
    /**
     * Display metadata for each idea status (label + Flux badge color).
     *
     * @var array<string, array{label: string, color: string, class?: string}>
     */
    public const STATUS_META = [
        'new' => ['label' => 'New', 'color' => 'zinc'],
        'under_review' => ['label' => 'Under Review', 'color' => 'amber'],
        'planned' => ['label' => 'Planned', 'color' => 'blue'],
        'in_progress' => ['label' => 'In Progress', 'color' => 'indigo'],
        'released' => ['label' => 'Implemented', 'color' => 'green'],
        'not_doing' => ['label' => 'Declined', 'color' => 'red'],
        'duplicate' => ['label' => 'Duplicate', 'color' => 'rose', 'class' => 'bg-rose-700! text-white!'],
    ];

    /** @var array<string, string> */
    public const PRIORITY_OPTIONS = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High'];

    /** @var array<string, string> */
    public const IMPACT_OPTIONS = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High'];

    /** @var array<string, string> */
    public const EFFORT_OPTIONS = ['small' => 'Small', 'medium' => 'Medium', 'large' => 'Large'];

    public Idea $ideaModel;

    #[Validate('required|string|max:2000')]
    public string $commentBody = '';

    public bool $isInternal = false;

    public string $status = '';

    public string $priority = '';

    public string $impact = '';

    public string $effort = '';

    public string $statusNote = '';

    public string $duplicateOfId = '';

    public string $duplicateNote = '';

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'duplicateOfId' => __('original idea'),
        ];
    }

    /**
     * Resolve the idea scoped to the current team (slugs are only unique per team).
     */
    public function mount(string $idea): void
    {
        $team = Auth::user()->currentTeam;

        $this->ideaModel = Idea::query()
            ->where('team_id', $team->id)
            ->where('slug', $idea)
            ->visibleTo(Auth::user()->teamRole($team), Auth::id())
            ->with(['boardGroup:id,name', 'board:id,name', 'category:id,name', 'submittedBy:id,name'])
            ->firstOrFail();

        $this->status = $this->ideaModel->status;
        $this->priority = $this->ideaModel->priority;
        $this->impact = $this->ideaModel->impact;
        $this->effort = $this->ideaModel->effort;
    }

    /**
     * Whether the current user may manage this idea (owner/admin/manager).
     */
    #[Computed]
    public function canManage(): bool
    {
        return Auth::user()->teamRole($this->team)?->isAtLeast(TeamRole::Manager) ?? false;
    }

    /**
     * Whether the current user may vote and comment (employee and above; viewers are read-only).
     */
    #[Computed]
    public function canParticipate(): bool
    {
        return Auth::user()->teamRole($this->team)?->isAtLeast(TeamRole::Employee) ?? false;
    }

    /**
     * Whether the current user may delete this idea or its comments (owner only).
     */
    #[Computed]
    public function canDelete(): bool
    {
        return Auth::user()->teamRole($this->team)?->isAtLeast(TeamRole::Owner) ?? false;
    }

    /**
     * Whether the current user may post internal (manager-only) comments.
     */
    #[Computed]
    public function canPostInternal(): bool
    {
        return Auth::user()->teamRole($this->team)?->isAtLeast(TeamRole::Manager) ?? false;
    }

    /**
     * Update the idea's triage fields. Records a status-history entry only when the status changes.
     */
    public function updateManagement(): void
    {
        abort_unless($this->canManage, 403);

        $validated = $this->validate([
            'status' => ['required', Rule::in(array_keys(self::STATUS_META))],
            'priority' => ['required', Rule::in(array_keys(self::PRIORITY_OPTIONS))],
            'impact' => ['required', Rule::in(array_keys(self::IMPACT_OPTIONS))],
            'effort' => ['required', Rule::in(array_keys(self::EFFORT_OPTIONS))],
            'statusNote' => ['nullable', 'string', 'max:2000'],
        ]);

        $previousStatus = $this->ideaModel->status;

        $attributes = [
            'status' => $validated['status'],
            'priority' => $validated['priority'],
            'impact' => $validated['impact'],
            'effort' => $validated['effort'],
        ];

        // Clearing the duplicate status also clears the link to the original idea.
        if ($validated['status'] !== 'duplicate') {
            $attributes['duplicate_of_idea_id'] = null;
        }

        $this->ideaModel->update($attributes);

        if ($previousStatus !== $validated['status']) {
            IdeaStatusHistory::create([
                'idea_id' => $this->ideaModel->id,
                'changed_by_user_id' => Auth::id(),
                'old_status' => $previousStatus,
                'new_status' => $validated['status'],
                'note' => $validated['statusNote'] !== '' ? $validated['statusNote'] : null,
            ]);

            unset($this->statusHistory);
        }

        $this->reset('statusNote');

        Flux::toast(variant: 'success', text: __('Idea updated.'));
    }

    /**
     * Soft-delete this idea (owner only) and return to the idea list.
     */
    public function deleteIdea(): void
    {
        abort_unless($this->canDelete, 403);

        $this->ideaModel->delete();

        Flux::toast(variant: 'success', text: __('Idea deleted.'));

        $this->redirectRoute('ideas.index', navigate: true);
    }

    /**
     * Open the "mark as duplicate" modal.
     */
    public function openMarkDuplicate(): void
    {
        abort_unless($this->canManage, 403);

        $this->reset('duplicateOfId', 'duplicateNote');
        $this->resetValidation();
        $this->dispatch('modal-show', name: 'mark-duplicate');
    }

    /**
     * Mark this idea as a duplicate of another idea in the same team.
     */
    public function markDuplicate(): void
    {
        abort_unless($this->canManage, 403);

        $teamId = $this->team->id;

        $validated = $this->validate([
            // Must be another idea in the same team (whereNot excludes this idea).
            'duplicateOfId' => ['required', Rule::exists('ideas', 'id')->where('team_id', $teamId)->whereNot('id', $this->ideaModel->id)],
            'duplicateNote' => ['nullable', 'string', 'max:2000'],
        ]);

        $previousStatus = $this->ideaModel->status;

        $this->ideaModel->update([
            'status' => 'duplicate',
            'duplicate_of_idea_id' => $validated['duplicateOfId'],
        ]);

        if ($previousStatus !== 'duplicate') {
            IdeaStatusHistory::create([
                'idea_id' => $this->ideaModel->id,
                'changed_by_user_id' => Auth::id(),
                'old_status' => $previousStatus,
                'new_status' => 'duplicate',
                'note' => $this->duplicateNote !== '' ? $this->duplicateNote : null,
            ]);

            unset($this->statusHistory);
        }

        $this->status = 'duplicate';
        unset($this->duplicateOriginal);
        $this->reset('duplicateOfId', 'duplicateNote');
        $this->dispatch('modal-close', name: 'mark-duplicate');

        Flux::toast(variant: 'success', text: __('Marked as duplicate.'));
    }

    /**
     * The original idea this one duplicates, if any.
     */
    #[Computed]
    public function duplicateOriginal(): ?Idea
    {
        if (! $this->ideaModel->duplicate_of_idea_id) {
            return null;
        }

        return Idea::where('team_id', $this->team->id)
            ->whereKey($this->ideaModel->duplicate_of_idea_id)
            ->first(['id', 'title', 'slug']);
    }

    /**
     * Ideas that have been marked as duplicates of this idea.
     *
     * @return Collection<int, Idea>
     */
    #[Computed]
    public function duplicatesList(): Collection
    {
        return Idea::where('team_id', $this->team->id)
            ->where('duplicate_of_idea_id', $this->ideaModel->id)
            ->orderBy('title')
            ->get(['id', 'title', 'slug', 'status']);
    }

    /**
     * Candidate originals to mark this idea as a duplicate of (same team, excluding self and other duplicates).
     *
     * @return Collection<int, Idea>
     */
    #[Computed]
    public function candidateIdeas(): Collection
    {
        return $this->team->ideas()
            ->where('id', '!=', $this->ideaModel->id)
            ->where('status', '!=', 'duplicate')
            ->orderBy('title')
            ->get(['id', 'title']);
    }

    /**
     * Toggle the current user's vote on this idea.
     */
    public function toggleVote(): void
    {
        abort_unless($this->canParticipate, 403);

        $existingVote = IdeaVote::where('idea_id', $this->ideaModel->id)
            ->where('user_id', Auth::id())
            ->first();

        if ($existingVote) {
            $existingVote->delete();
        } else {
            IdeaVote::firstOrCreate([
                'idea_id' => $this->ideaModel->id,
                'user_id' => Auth::id(),
            ]);
        }
    }

    /**
     * Add a public comment from the current user to this idea.
     *
     * The idea was resolved scoped to the current team in mount(), so a comment
     * can only ever be attached to an idea belonging to the user's current team.
     */
    public function addComment(): void
    {
        abort_unless($this->canParticipate, 403);

        $validated = $this->validate();

        IdeaComment::create([
            'idea_id' => $this->ideaModel->id,
            'user_id' => Auth::id(),
            'body' => $validated['commentBody'],
            'is_internal' => $this->canPostInternal && $this->isInternal,
        ]);

        $this->reset('commentBody', 'isInternal');

        unset($this->comments);

        Flux::toast(variant: 'success', text: __('Comment added.'));
    }

    /**
     * Soft-delete a comment on this idea (owner only).
     */
    public function deleteComment(int $commentId): void
    {
        abort_unless($this->canDelete, 403);

        $this->ideaModel->comments()->whereKey($commentId)->firstOrFail()->delete();

        unset($this->comments);

        Flux::toast(variant: 'success', text: __('Comment deleted.'));
    }

    #[Computed]
    public function voteCount(): int
    {
        return $this->ideaModel->votes()->count();
    }

    #[Computed]
    public function hasVoted(): bool
    {
        return $this->ideaModel->votes()
            ->where('user_id', Auth::id())
            ->exists();
    }

    /**
     * Votes for this idea with their voter loaded, sorted alphabetically by
     * voter name — except the current user's own vote, which always leads.
     *
     * @return Collection<int, IdeaVote>
     */
    #[Computed]
    public function voters(): Collection
    {
        return $this->ideaModel->votes()
            ->with('user:id,name')
            ->get()
            ->filter(fn (IdeaVote $vote) => $vote->user !== null)
            ->sortBy([
                fn (IdeaVote $a, IdeaVote $b) => ($b->user_id === Auth::id()) <=> ($a->user_id === Auth::id()),
                fn (IdeaVote $a, IdeaVote $b) => Str::lower($a->user->name) <=> Str::lower($b->user->name),
            ])
            ->values();
    }

    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    /**
     * Whether the current user may see internal (manager-only) comments.
     */
    #[Computed]
    public function canViewInternalComments(): bool
    {
        return Auth::user()->teamRole($this->team)?->isAtLeast(TeamRole::Manager) ?? false;
    }

    /**
     * Visible comments for the idea, oldest first.
     *
     * @return Collection<int, \App\Models\IdeaComment>
     */
    #[Computed]
    public function comments(): Collection
    {
        return $this->ideaModel->comments()
            ->notHidden()
            ->with('user:id,name')
            ->when(! $this->canViewInternalComments, fn ($query) => $query->where('is_internal', false))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Status change history, newest first.
     *
     * @return Collection<int, \App\Models\IdeaStatusHistory>
     */
    #[Computed]
    public function statusHistory(): Collection
    {
        return $this->ideaModel->statusHistory()
            ->with('changedBy:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * User ids that hold a staff role (manager and above) on the team.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function staffUserIds(): array
    {
        return $this->team->memberships()
            ->get(['user_id', 'role'])
            ->filter(fn ($membership) => $membership->role->isAtLeast(TeamRole::Manager))
            ->pluck('user_id')
            ->all();
    }

    /**
     * Get the display metadata for a status value.
     *
     * @return array{label: string, color: string, class?: string}
     */
    public function statusMeta(string $status): array
    {
        return self::STATUS_META[$status] ?? ['label' => str($status)->headline()->value(), 'color' => 'zinc'];
    }
}; ?>

@php($idea = $this->ideaModel)
@php($meta = $this->statusMeta($idea->status))
@php($author = $idea->is_anonymous ? __('Anonymous') : ($idea->submittedBy?->name ?? __('Unknown')))

@push('breadcrumbs')
    <x-breadcrumbs :items="[
        ['label' => __('All Ideas'), 'href' => route('ideas.index')],
        ...($idea->boardGroup ? [['label' => $idea->boardGroup->name, 'href' => route('ideas.index', ['group' => $idea->board_group_id])]] : []),
        ...($idea->board ? [['label' => $idea->board->name, 'href' => route('ideas.index', ['board' => $idea->board_id])]] : []),
        ['label' => $idea->title, 'href' => null],
    ]" />
@endpush

<section class="mx-auto w-full  px-6 pb-7 lg:px-8">
    <div class="flex items-center justify-between gap-3">
        <flux:link as="button" x-data x-on:click="window.history.back()" variant="subtle" class="inline-flex items-center gap-1 text-sm">
            <flux:icon.arrow-left class="size-4" />
            {{ __('Back') }}
        </flux:link>

        <flux:dropdown position="bottom" align="end">
            <flux:button
                variant="outline"
                size="sm"
                icon="hand-thumb-up"
                icon:trailing="chevron-down"
                class="border-indigo-700! text-indigo-700! hover:bg-indigo-50! dark:border-indigo-400! dark:text-indigo-400! dark:hover:bg-indigo-500/10!"
                data-test="who-voted-trigger"
            >
                {{ __('Who voted') }}
            </flux:button>

            <flux:menu class="min-w-80">
                <div class="max-h-64 overflow-y-auto">
                    @forelse ($this->voters as $vote)
                        <flux:menu.item class="cursor-default" wire:key="voter-{{ $vote->id }}">
                            <div class="flex items-center gap-2">
                                <flux:avatar size="xs" :name="$vote->user->name" color="auto" color:seed="{{ $vote->user_id }}" />
                                <div class="min-w-0">
                                    <div class="truncate">
                                        {{ $vote->user->name }}
                                        @if ($vote->user_id === Auth::id())
                                            <span class="text-slate-500">({{ __('You') }})</span>
                                        @endif
                                    </div>
                                    <flux:tooltip content="{{ __('Date Voted') }}">
                                        <div style="font-size:9px" class="truncate  text-slate-500">{{ $vote->created_at->format('M j, Y g:i A') }}</div>
                                    </flux:tooltip>
                                </div>
                            </div>
                        </flux:menu.item>
                    @empty
                        <flux:menu.item class="cursor-default text-slate-500">
                            {{ __('No votes yet') }}
                        </flux:menu.item>
                    @endforelse
                </div>
            </flux:menu>
        </flux:dropdown>
    </div>

    <div class="mt-5 grid grid-cols-1 gap-6 lg:grid-cols-[1fr_300px]">
        {{-- Main column --}}
        <div>
            @if ($this->duplicateOriginal)
                <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-950/20 dark:text-amber-200" data-test="duplicate-banner">
                    {{ __('This idea was marked as a duplicate of') }}
                    <a href="{{ route('ideas.show', ['idea' => $this->duplicateOriginal->slug]) }}" wire:navigate class="font-semibold underline">{{ $this->duplicateOriginal->title }}</a>.
                </div>
            @endif

            <div class="flex gap-4">
                {{-- Vote toggle --}}
                <flux:tooltip :content="$this->canParticipate ? ($this->hasVoted ? __('You voted this idea..') : __('Click to vote for this idea..')) : __('Viewers have read-only access.')">
                    <button
                        type="button"
                        wire:click="toggleVote"
                        wire:loading.attr="disabled"
                        @disabled(! $this->canParticipate)
                        aria-pressed="{{ $this->hasVoted ? 'true' : 'false' }}"
                        @class([
                            'flex w-[72px] shrink-0 flex-col items-center justify-center gap-1 self-start rounded-xl border py-3 transition',
                            'cursor-not-allowed opacity-60' => ! $this->canParticipate,
                            'cursor-pointer' => $this->canParticipate,
                            'border-indigo-200 bg-indigo-50 text-indigo-600 dark:border-indigo-500/40 dark:bg-indigo-500/10 dark:text-indigo-300' => $this->hasVoted,
                            'border-zinc-200 text-slate-600 hover:border-indigo-200 hover:text-indigo-600 dark:border-zinc-700 dark:text-slate-500 dark:hover:border-indigo-500/40' => ! $this->hasVoted,
                        ])
                        data-test="vote-button"
                    >
                        <flux:icon.chevron-up class="size-5" />
                        <span class="text-lg font-extrabold">{{ $this->voteCount }}</span>
                        <span class="text-[11px] font-medium {{ $this->hasVoted ? 'text-indigo-500/80 dark:text-indigo-300/80' : 'text-slate-500' }}">{{ trans_choice('vote|votes', $this->voteCount) }}</span>
                    </button>
                </flux:tooltip>

                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:badge :color="$meta['color']" size="sm" class="{{ $meta['class'] ?? '' }}">{{ $meta['label'] }}</flux:badge>
                        @if ($idea->category)
                            <flux:badge color="zinc" size="sm" variant="outline">{{ $idea->category->name }}</flux:badge>
                        @endif
                        @if ($idea->board)
                            <span class="inline-flex items-center gap-1 text-xs text-slate-600 dark:text-slate-500">
                                <flux:icon.rectangle-group class="size-3.5" />
                                @if ($idea->boardGroup){{ $idea->boardGroup->name }} · @endif{{ $idea->board->name }}
                            </span>
                        @endif
                    </div>

                    <flux:heading size="xl" class="mt-3">{{ $idea->title }}</flux:heading>

                    <div class="mt-3 flex items-center gap-2 text-sm text-slate-600 dark:text-slate-500">
                        <flux:avatar size="xs" :name="$author" color="auto" color:seed="{{ $idea->submitted_by_user_id ?? $author }}" />
                        <span>
                            {{ __('Submitted by') }}
                            <span class="font-medium text-slate-800 dark:text-slate-400">{{ $author }}</span>
                            · {{ $idea->created_at->format('M j, Y g:i A') }}
                        </span>
                    </div>
                </div>
            </div>

            <div class="mt-6 whitespace-pre-line text-[15px] leading-relaxed text-slate-800 dark:text-slate-400">{{ $idea->description }}</div>

            <flux:separator class="my-8" />

            {{-- Comments --}}
            <flux:heading size="lg">
                {{ trans_choice(':count comment|:count comments', $this->comments->count(), ['count' => $this->comments->count()]) }}
            </flux:heading>

            {{-- Composer --}}
            @if ($this->canParticipate)
                <form wire:submit="addComment" class="mt-4 flex gap-3">
                    <flux:avatar size="sm" :name="auth()->user()->name" color="auto" color:seed="{{ auth()->id() }}" />
                    <div class="min-w-0 flex-1 space-y-2">
                        <flux:textarea
                            wire:model="commentBody"
                            rows="3"
                            :placeholder="__('Share your thoughts or add context…')"
                            data-test="comment-body"
                        />
                        <div class="flex items-center justify-between gap-3">
                            @if ($this->canPostInternal)
                                <flux:checkbox
                                    wire:model="isInternal"
                                    :label="__('Internal note (staff only)')"
                                    data-test="comment-internal"
                                />
                            @else
                                <span></span>
                            @endif

                            <flux:button
                                variant="primary"
                                type="submit"
                                size="sm"
                                wire:loading.attr="disabled"
                                data-test="add-comment-button"
                            >
                                {{ __('Comment') }}
                            </flux:button>
                        </div>
                    </div>
                </form>
            @else
                <flux:text class="mt-4 text-sm text-slate-600 dark:text-slate-500" data-test="viewer-read-only-notice">
                    {{ __('Viewers have read-only access and cannot comment.') }}
                </flux:text>
            @endif

            <div class="mt-6 space-y-4">
                @forelse ($this->comments as $comment)
                    <div
                        @class([
                            'flex gap-3 rounded-xl p-4',
                            'bg-amber-50 dark:bg-amber-950/20' => $comment->is_internal,
                            'bg-zinc-50 dark:bg-zinc-800/40' => ! $comment->is_internal,
                        ])
                        wire:key="comment-{{ $comment->id }}"
                    >
                        <flux:avatar size="sm" :name="$comment->user?->name ?? __('Unknown')" color="auto" color:seed="{{ $comment->user_id ?? $comment->user?->name ?? __('Unknown') }}" />
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-sm font-medium text-slate-900 dark:text-slate-200">{{ $comment->user?->name ?? __('Unknown') }}</span>
                                @if (in_array($comment->user_id, $this->staffUserIds, true))
                                    <flux:badge color="teal" size="sm">{{ __('Staff') }}</flux:badge>
                                @endif
                                @if ($comment->is_internal)
                                    <flux:badge color="amber" size="sm">{{ __('Internal') }}</flux:badge>
                                @endif
                                <flux:tooltip content="{{ $comment->created_at->format('M j, Y g:i A') }}">
                                    <span class="text-xs text-slate-500">{{ $comment->created_at->diffForHumans() }}</span>
                                </flux:tooltip>
                            </div>
                            <div class="mt-1 whitespace-pre-line text-sm text-slate-800 dark:text-slate-400">{{ $comment->body }}</div>
                        </div>
                        @if ($this->canDelete)
                            <flux:button
                                wire:click="deleteComment({{ $comment->id }})"
                                wire:confirm="{{ __('Delete this comment?') }}"
                                variant="ghost"
                                size="sm"
                                icon="trash"
                                class="text-red-600"
                                data-test="delete-comment"
                            />
                        @endif
                    </div>
                @empty
                    <div class="rounded-xl border border-dashed border-zinc-300 py-8 text-center dark:border-zinc-700">
                        <flux:text class="text-sm text-slate-600 dark:text-slate-500">{{ __('No comments yet — start the discussion above.') }}</flux:text>
                    </div>
                @endforelse
            </div>
        </div>

        {{-- Right rail --}}
        <aside class="space-y-4">
            {{-- Manage panel (owner/admin/manager only) --}}
            @if ($this->canManage)
                <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" data-test="manage-panel">
                    <flux:heading size="sm">{{ __('Manage idea') }}</flux:heading>

                    <form wire:submit="updateManagement" class="mt-4 space-y-4">
                        <flux:select wire:model="status" :label="__('Status')" size="sm" data-test="manage-status">
                            @foreach (self::STATUS_META as $value => $statusMeta)
                                <flux:select.option value="{{ $value }}">{{ $statusMeta['label'] }}</flux:select.option>
                            @endforeach
                        </flux:select>

                        <flux:select wire:model="priority" :label="__('Priority')" size="sm" data-test="manage-priority">
                            @foreach (self::PRIORITY_OPTIONS as $value => $label)
                                <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                            @endforeach
                        </flux:select>

                        <flux:select wire:model="impact" :label="__('Impact')" size="sm" data-test="manage-impact">
                            @foreach (self::IMPACT_OPTIONS as $value => $label)
                                <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                            @endforeach
                        </flux:select>

                        <flux:select wire:model="effort" :label="__('Effort')" size="sm" data-test="manage-effort">
                            @foreach (self::EFFORT_OPTIONS as $value => $label)
                                <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                            @endforeach
                        </flux:select>

                        <flux:textarea
                            wire:model="statusNote"
                            :label="__('Status note (optional)')"
                            rows="2"
                            :placeholder="__('Added to the activity log when the status changes')"
                            data-test="manage-note"
                        />

                        <flux:button variant="primary" type="submit" size="sm" class="w-full" wire:loading.attr="disabled" data-test="manage-save">
                            {{ __('Save changes') }}
                        </flux:button>
                    </form>

                    <flux:separator class="my-4" variant="subtle" />

                    <flux:button wire:click="openMarkDuplicate" variant="ghost" size="sm" class="w-full" icon="document-duplicate" data-test="open-mark-duplicate">
                        {{ __('Mark as duplicate') }}
                    </flux:button>

                    @if ($this->canDelete)
                        <flux:modal.trigger name="delete-idea">
                            <flux:button variant="danger" size="sm" class="mt-2 w-full" icon="trash" data-test="delete-idea-button">
                                {{ __('Delete idea') }}
                            </flux:button>
                        </flux:modal.trigger>
                    @endif
                </div>

                @if ($this->canDelete)
                    {{-- Delete idea modal --}}
                    <flux:modal name="delete-idea" class="max-w-lg" :dismissible="false" data-test="delete-idea-modal">
                        <div class="space-y-5">
                            <div>
                                <flux:heading size="lg">{{ __('Delete this idea?') }}</flux:heading>
                                <flux:text class="mt-2 text-sm text-slate-600 dark:text-slate-500">
                                    {{ __('This will remove ":title" and its comments from the idea list. This cannot be undone from the UI.', ['title' => $idea->title]) }}
                                </flux:text>
                            </div>
                            <div class="flex justify-end gap-2">
                                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                                <flux:button wire:click="deleteIdea" variant="danger" data-test="confirm-delete-idea">{{ __('Delete idea') }}</flux:button>
                            </div>
                        </div>
                    </flux:modal>
                @endif

                {{-- Mark as duplicate modal --}}
                <flux:modal name="mark-duplicate" class="max-w-lg" :dismissible="false" data-test="mark-duplicate-modal">
                    <form wire:submit="markDuplicate" class="space-y-5">
                        <flux:heading size="lg">{{ __('Mark as duplicate') }}</flux:heading>
                        <flux:text class="text-sm text-slate-600 dark:text-slate-500">
                            {{ __('Link this idea to the original it duplicates. Its status will change to Duplicate.') }}
                        </flux:text>
                        <flux:select wire:model="duplicateOfId" :label="__('Original idea')" :placeholder="__('Choose the original idea')" required data-test="duplicate-original">
                            @foreach ($this->candidateIdeas as $candidate)
                                <flux:select.option value="{{ $candidate->id }}">{{ $candidate->title }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:textarea wire:model="duplicateNote" :label="__('Note (optional)')" rows="2" :placeholder="__('Added to the activity log')" data-test="duplicate-note" />
                        <div class="flex justify-end gap-2">
                            <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                            <flux:button variant="primary" type="submit" data-test="confirm-duplicate">{{ __('Mark as duplicate') }}</flux:button>
                        </div>
                    </form>
                </flux:modal>
            @endif

            {{-- Activity timeline --}}
            <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:heading size="sm">{{ __('Activity') }}</flux:heading>

                <div class="mt-4">
                    @forelse ($this->statusHistory as $entry)
                        @php($entryMeta = $this->statusMeta($entry->new_status))
                        <div class="flex gap-3" wire:key="history-{{ $entry->id }}">
                            <div class="flex flex-col items-center">
                                <span class="mt-1.5 size-2.5 shrink-0 rounded-full {{ $loop->first ? 'bg-indigo-500' : 'bg-zinc-300 dark:bg-zinc-600' }}"></span>
                                @unless ($loop->last)
                                    <span class="w-px flex-1 bg-zinc-200 dark:bg-zinc-700"></span>
                                @endunless
                            </div>
                            <div class="min-w-0 flex-1 {{ $loop->last ? '' : 'pb-4' }}">
                                <flux:badge :color="$entryMeta['color']" size="sm" class="{{ $entryMeta['class'] ?? '' }}">{{ $entryMeta['label'] }}</flux:badge>
                                @if ($entry->note)
                                    <p class="mt-0.5 text-sm text-slate-600 dark:text-slate-400">{{ $entry->note }}</p>
                                @endif
                                <p class="mt-1 text-xs text-slate-500">
                                    {{ $entry->changedBy?->name ?? __('Unknown') }}
                                    @if ($entry->created_at)
                                        · {{ $entry->created_at->diffForHumans() }}
                                    @endif
                                </p>
                            </div>
                        </div>
                    @empty
                        <flux:text class="text-sm text-slate-600 dark:text-slate-500">{{ __('No activity yet.') }}</flux:text>
                    @endforelse
                </div>
            </div>

            {{-- Duplicates of this idea --}}
            @if ($this->duplicatesList->isNotEmpty())
                <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" data-test="duplicates-of">
                    <flux:heading size="sm">{{ __('Duplicates of this idea') }}</flux:heading>
                    <div class="mt-3 space-y-2">
                        @foreach ($this->duplicatesList as $duplicate)
                            <a href="{{ route('ideas.show', ['idea' => $duplicate->slug]) }}" wire:navigate class="block truncate text-sm text-indigo-600 hover:underline dark:text-indigo-400" wire:key="dup-{{ $duplicate->id }}">
                                {{ $duplicate->title }}
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif
        </aside>
    </div>
</section>
