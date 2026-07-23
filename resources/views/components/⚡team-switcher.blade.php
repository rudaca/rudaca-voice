<?php

use App\Data\UserTeam;
use App\Models\Team;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Component;

new class extends Component {
    public bool $compact = false;

    public function currentTeam(): ?array
    {
        $team = Auth::user()->currentTeam;

        return $team ? [
            'id' => $team->id,
            'name' => $team->name,
            'slug' => $team->slug,
        ] : null;
    }

    /**
     * Get the teams this user actually belongs to.
     *
     * @return Collection<int, UserTeam>
     */
    public function ownedTeams(): Collection
    {
        return Auth::user()->toUserTeams(includeCurrent: true);
    }

    /**
     * Get every other team in the system, visible only to Super Admins so
     * they can switch into any team to use View As there.
     *
     * @return Collection<int, UserTeam>
     */
    public function otherTeams(): Collection
    {
        $user = Auth::user();

        if (! $user->is_super_admin) {
            return collect();
        }

        $ownedIds = $user->teams()->pluck('teams.id');

        return Team::query()
            ->whereNotIn('id', $ownedIds)
            ->get()
            ->map(fn (Team $team) => $user->toUserTeam($team))
            ->sortBy(fn (UserTeam $team) => Str::lower($team->name))
            ->values();
    }

    public function switchTeam(string $slug): void
    {
        $user = Auth::user();
        $team = Team::where('slug', $slug)->firstOrFail();

        abort_unless(
            $user->belongsToTeam($team) || $user->is_super_admin,
            403
        );

        $currentTeamSlug = $user->currentTeam?->slug;

        $user->switchTeam($team);

        if (! request()->header('Referer')) {
            $this->redirectRoute('dashboard', ['current_team' => $team->slug], navigate: true);

            return;
        }

        if (! $currentTeamSlug) {
            $this->redirect(request()->header('Referer'), navigate: true);

            return;
        }

        $redirectTo = $this->replaceCurrentTeamInReferer(
            request()->header('Referer'),
            $currentTeamSlug,
            $team->slug,
        );

        $this->redirect($redirectTo ?? request()->header('Referer'), navigate: true);
    }

    protected function replaceCurrentTeamInReferer(string $referer, string $currentTeamSlug, string $newTeamSlug): ?string
    {
        $redirectTo = preg_replace(
            '#/'.preg_quote($currentTeamSlug, '#').'(?=/|\?|$)#',
            '/'.$newTeamSlug,
            $referer,
            1,
        );

        return preg_replace(
            '#([?&]current_team=)'.preg_quote($currentTeamSlug, '#').'(?=&|$)#',
            '$1'.$newTeamSlug,
            $redirectTo ?? $referer,
            1,
        );
    }
}; ?>

<div>
    <flux:dropdown position="bottom" align="start">
        @if ($compact)
            <button
                type="button"
                class="flex min-w-0 items-center gap-1 truncate text-xs text-slate-700 transition hover:text-slate-900 dark:text-slate-400 dark:hover:text-white"
                data-test="team-switcher-trigger"
            >
                <span class="truncate text-lg font-semibold">{{ $this->currentTeam()['name'] ?? __('Select team') }}</span>
                <flux:icon name="chevron-down" variant="micro" class="size-3.5 shrink-0" />
            </button>
        @else
            <flux:button variant="ghost" class="group w-full justify-start in-data-flux-sidebar-collapsed-desktop:justify-center" data-test="team-switcher-trigger">
                <flux:icon name="users" class="hidden size-4 in-data-flux-sidebar-collapsed-desktop:block" />
                <span class="truncate font-semibold in-data-flux-sidebar-collapsed-desktop:hidden">{{ $this->currentTeam()['name'] ?? __('Select team') }}</span>
                <flux:icon
                    name="chevrons-up-down"
                    variant="micro"
                    class="ms-auto size-4 in-data-flux-sidebar-collapsed-desktop:hidden"
                />
            </flux:button>
        @endif

        <flux:menu class="min-w-96">
            <flux:menu.heading>{{ __('Owned Teams') }}</flux:menu.heading>

            @foreach ($this->ownedTeams() as $team)
                <flux:menu.item
                    wire:click="switchTeam('{{ $team->slug }}')"
                    class="cursor-pointer {{ $team->isCurrent ? 'bg-zinc-50 font-semibold dark:bg-white/10' : '' }}"
                    data-test="team-switcher-item"
                >
                    <div class="flex w-full items-center justify-between gap-2">
                        <div class="flex min-w-0 items-center gap-1.5">
                            <span class="truncate">{{ $team->name }}</span>
                            @if ($team->isPersonal)
                                <flux:tooltip content="{{ __('Personal') }}">
                                    <flux:icon name="lock-closed" variant="outline" class="size-4 shrink-0 text-rose-900 dark:text-rose-400" />
                                </flux:tooltip>
                            @endif
                        </div>
                        @if ($team->isCurrent)
                            <flux:icon name="check" class="size-4 shrink-0" />
                        @endif
                    </div>
                </flux:menu.item>
            @endforeach

            @if ($this->otherTeams()->isNotEmpty())
                <flux:menu.separator />

                <flux:menu.heading>{{ __('Other Teams') }}</flux:menu.heading>

                <div class="max-h-64 overflow-y-auto">
                    @foreach ($this->otherTeams() as $team)
                        <flux:menu.item
                            wire:click="switchTeam('{{ $team->slug }}')"
                            class="cursor-pointer {{ $team->isCurrent ? 'bg-zinc-50 font-semibold dark:bg-white/10' : '' }}"
                            data-test="team-switcher-item"
                        >
                            <div class="flex w-full items-center justify-between gap-2">
                                <div class="flex min-w-0 items-center gap-1.5">
                                    <span class="truncate">{{ $team->name }}</span>
                                    @if ($team->isPersonal)
                                        <flux:tooltip content="{{ __('Private') }}">
                                            <flux:icon name="lock-closed" variant="outline" class="size-4 shrink-0 text-rose-900 dark:text-rose-400" />
                                        </flux:tooltip>
                                    @endif
                                </div>
                                <div class="flex shrink-0 items-center gap-1.5">
                                    <flux:tooltip content="{{ __('View only') }}">
                                        <flux:icon name="eye" variant="outline" class="size-4 text-slate-500" />
                                    </flux:tooltip>
                                    @if ($team->isCurrent)
                                        <flux:icon name="check" class="size-4" />
                                    @endif
                                </div>
                            </div>
                        </flux:menu.item>
                    @endforeach
                </div>
            @endif

            <flux:menu.separator />

            <flux:modal.trigger name="create-team-switcher">
                <flux:menu.item icon="plus" class="cursor-pointer" data-test="team-switcher-new-team">
                    {{ __('New team') }}
                </flux:menu.item>
            </flux:modal.trigger>
        </flux:menu>
    </flux:dropdown>
</div>
