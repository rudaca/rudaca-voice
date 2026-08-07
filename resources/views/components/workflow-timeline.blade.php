{{--
    Explains the idea review workflow's happy-path chain (New -> Approved ->
    Planned -> In Progress -> Completed), styled after the idea show page's
    Activity timeline (colored dot + connecting line + colored badge). Meant
    to be shown inside a modal rather than inline on the page, so it's always
    a vertical list with room for a one-line explanation under each step.

    Declined isn't its own node on the chain -- it's nested under "New" (via
    a small red dot of its own) so the main rail stays a single unbroken
    path, with Declined reading as a fork off it instead of a step in it.
--}}
<ol {{ $attributes->class('flex flex-col') }} data-test="workflow-timeline">
    <li class="flex gap-3">
        <div class="flex flex-col items-center">
            <span class="mt-1 size-2.5 shrink-0 rounded-full bg-zinc-800 dark:bg-zinc-200"></span>
            <span class="mt-1 w-px flex-1 bg-zinc-200 dark:bg-zinc-700"></span>
        </div>
        <div class="flex flex-col items-start gap-1 pb-5">
            <flux:badge size="sm" color="zinc">{{ __('New') }}</flux:badge>
            <flux:text class="text-sm text-slate-600 dark:text-slate-500">
                {{ __('Just submitted. Waiting for a manager to approve or decline it.') }}
            </flux:text>

            <div class="ms-6 flex items-start gap-2" data-test="workflow-declined-branch">
                <div class="flex flex-col items-center">
                    <span class="block h-3 w-0 border-l-2 border-dashed border-red-500 dark:border-red-500"></span>
                    <span class="mt-0.5 size-2 shrink-0 rounded-full bg-red-700 dark:bg-red-400"></span>
                </div>
                <div class="flex flex-col items-start gap-1">
                    <flux:badge size="sm" color="red">{{ __('Declined') }}</flux:badge>
                    <flux:text class="text-sm text-slate-600 dark:text-slate-500">
                        {{ __("A manager decided not to move forward with it. Branches off New and doesn't continue on to Approved.") }}
                    </flux:text>
                </div>
            </div>
        </div>
    </li>

    <li class="flex gap-3">
        <div class="flex flex-col items-center">
            <span class="mt-1 size-2.5 shrink-0 rounded-full bg-amber-800 dark:bg-amber-200"></span>
            <span class="mt-1 w-px flex-1 bg-zinc-200 dark:bg-zinc-700"></span>
        </div>
        <div class="flex flex-col items-start gap-1 pb-5">
            <flux:badge size="sm" color="amber">{{ __('Approved') }}</flux:badge>
            <flux:text class="text-sm text-slate-600 dark:text-slate-500">
                {{ __("A manager approved it. It's accepted, but not yet scheduled.") }}
            </flux:text>
        </div>
    </li>

    <li class="flex gap-3">
        <div class="flex flex-col items-center">
            <span class="mt-1 size-2.5 shrink-0 rounded-full bg-blue-800 dark:bg-blue-200"></span>
            <span class="mt-1 w-px flex-1 bg-zinc-200 dark:bg-zinc-700"></span>
        </div>
        <div class="flex flex-col items-start gap-1 pb-5">
            <flux:badge size="sm" color="blue">{{ __('Planned') }}</flux:badge>
            <flux:text class="text-sm text-slate-600 dark:text-slate-500">
                {{ __('Added to the roadmap for an upcoming release.') }}
            </flux:text>
        </div>
    </li>

    <li class="flex gap-3">
        <div class="flex flex-col items-center">
            <span class="mt-1 size-2.5 shrink-0 rounded-full bg-indigo-800 dark:bg-indigo-200"></span>
            <span class="mt-1 w-px flex-1 bg-zinc-200 dark:bg-zinc-700"></span>
        </div>
        <div class="flex flex-col items-start gap-1 pb-5">
            <flux:badge size="sm" color="indigo">{{ __('In Progress') }}</flux:badge>
            <flux:text class="text-sm text-slate-600 dark:text-slate-500">
                {{ __('Actively being built.') }}
            </flux:text>
        </div>
    </li>

    <li class="flex gap-3">
        <span class="mt-1 size-2.5 shrink-0 rounded-full bg-green-800 dark:bg-green-200"></span>
        <div class="flex flex-col items-start gap-1">
            <flux:badge size="sm" color="green">{{ __('Completed') }}</flux:badge>
            <flux:text class="text-sm text-slate-600 dark:text-slate-500">
                {{ __('Shipped and live for everyone.') }}
            </flux:text>
        </div>
    </li>
</ol>
