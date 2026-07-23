<?php

use App\Enums\TeamRole;
use App\Models\IdeaComment;
use App\Models\Team;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Moderate comments')] class extends Component {
    use WithPagination;

    #[Url(as: 'filter')]
    public string $filter = 'all';

    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    /**
     * Whether the current user may hide or restore comments (admin and above).
     */
    #[Computed]
    public function canModerate(): bool
    {
        return Auth::user()->teamRole($this->team)?->isAtLeast(TeamRole::Admin) ?? false;
    }

    /**
     * Comments across the team's ideas, newest first.
     *
     * @return LengthAwarePaginator<int, IdeaComment>
     */
    #[Computed]
    public function comments(): LengthAwarePaginator
    {
        return IdeaComment::query()
            ->whereHas('idea', fn ($query) => $query->where('team_id', $this->team->id))
            ->when($this->filter === 'hidden', fn ($query) => $query->whereNotNull('hidden_at'))
            ->with(['user:id,name', 'hiddenBy:id,name', 'idea:id,title,slug'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(15);
    }

    /**
     * Hide a comment from its idea's thread.
     */
    public function hideComment(int $commentId): void
    {
        abort_unless($this->canModerate, 403);

        $comment = $this->commentQuery()->whereKey($commentId)->firstOrFail();
        $comment->hide(Auth::id());

        Flux::toast(variant: 'success', text: __('Comment hidden.'));
    }

    /**
     * Restore a previously hidden comment.
     */
    public function unhideComment(int $commentId): void
    {
        abort_unless($this->canModerate, 403);

        $comment = $this->commentQuery()->whereKey($commentId)->firstOrFail();
        $comment->unhide();

        Flux::toast(variant: 'success', text: __('Comment restored.'));
    }

    /**
     * Base query for comments belonging to the current team, regardless of hidden state.
     */
    protected function commentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return IdeaComment::query()
            ->whereHas('idea', fn ($query) => $query->where('team_id', $this->team->id));
    }
}; ?>

@push('breadcrumbs')
    <x-breadcrumbs :items="[
        ['label' => __('Moderate Comments'), 'href' => null],
    ]" />
@endpush

<section class="mx-auto container px-6 pb-7 lg:px-8">
    <div class="flex flex-col gap-1">
        <flux:heading size="xl">{{ __('Moderate comments') }}</flux:heading>
        <flux:text class="text-slate-600 dark:text-slate-500">
            {{ __('Hide comments that violate guidelines. Hidden comments disappear from the idea thread but can be restored at any time.') }}
        </flux:text>
    </div>

    <x-sticky-toolbar class="mt-6 flex items-center gap-2 py-3">
        <flux:radio.group wire:model.live="filter" variant="segmented" size="sm">
            <flux:radio value="all">{{ __('All comments') }}</flux:radio>
            <flux:radio value="hidden">{{ __('Hidden only') }}</flux:radio>
        </flux:radio.group>
    </x-sticky-toolbar>

    <div class="mt-4">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Comment') }}</flux:table.column>
                <flux:table.column>{{ __('Idea') }}</flux:table.column>
                <flux:table.column>{{ __('Author') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column>{{ __('Action') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($this->comments as $comment)
                    <flux:table.row :key="'comment-'.$comment->id" data-test="moderate-comment-row">
                        <flux:table.cell class="max-w-sm">
                            <flux:text class="line-clamp-2 text-sm text-slate-800 dark:text-slate-400">{{ $comment->body }}</flux:text>
                        </flux:table.cell>

                        <flux:table.cell>
                            <a href="{{ route('ideas.show', ['idea' => $comment->idea->slug]) }}" wire:navigate class="text-sm hover:underline">
                                {{ $comment->idea->title }}
                            </a>
                        </flux:table.cell>

                        <flux:table.cell class="text-slate-700 dark:text-slate-400">
                            {{ $comment->user?->name ?? __('Unknown') }}
                        </flux:table.cell>

                        <flux:table.cell>
                            @if ($comment->isHidden())
                                <flux:badge color="red" size="sm">{{ __('Hidden') }}</flux:badge>
                                @if ($comment->hiddenBy)
                                    <div class="mt-1 text-xs text-slate-500">{{ __('by :name', ['name' => $comment->hiddenBy->name]) }}</div>
                                @endif
                            @else
                                <flux:badge color="green" size="sm" variant="outline">{{ __('Visible') }}</flux:badge>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell>
                            @if ($comment->isHidden())
                                <flux:button
                                    variant="ghost"
                                    size="sm"
                                    wire:click="unhideComment({{ $comment->id }})"
                                    data-test="unhide-comment"
                                >
                                    {{ __('Restore') }}
                                </flux:button>
                            @else
                                <flux:button
                                    variant="ghost"
                                    size="sm"
                                    class="border border-red-200 bg-red-50 text-red-700 hover:bg-red-100 dark:border-red-900 dark:bg-red-950 dark:text-red-400"
                                    wire:click="hideComment({{ $comment->id }})"
                                    data-test="hide-comment"
                                >
                                    {{ __('Hide') }}
                                </flux:button>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5">
                            <div class="py-14 text-center" data-test="moderate-comments-empty">
                                <flux:icon.chat-bubble-left-right class="mx-auto size-8 text-slate-400 dark:text-slate-700" />
                                <flux:heading class="mt-3">{{ __('No comments to show') }}</flux:heading>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>

    @if ($this->comments->hasPages())
        <div class="mt-6">
            {{ $this->comments->links() }}
        </div>
    @endif
</section>
