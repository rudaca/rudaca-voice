<?php

use App\Enums\IdeaStatus;
use App\Enums\TeamRole;
use App\Models\Idea;
use App\Models\IdeaAttachment;
use App\Models\IdeaAttachmentHistory;
use App\Models\IdeaBoard;
use App\Models\IdeaCategory;
use App\Models\IdeaComment;
use App\Models\IdeaEditHistory;
use App\Models\IdeaOfficialResponse;
use App\Models\IdeaOfficialResponseHistory;
use App\Models\IdeaStatusHistory;
use App\Models\IdeaVote;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Ideas\OfficialResponsePublished;
use App\Notifications\Ideas\OfficialResponseUpdated;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;
use Stevebauman\Purify\Facades\Purify;

new #[Title('Idea')] class extends Component {
    use WithFileUploads;

    /** @var array<string, string> */
    public const PRIORITY_OPTIONS = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High'];

    /** @var array<string, string> */
    public const IMPACT_OPTIONS = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High'];

    /** @var array<string, string> */
    public const EFFORT_OPTIONS = ['small' => 'Small', 'medium' => 'Medium', 'large' => 'Large'];

    public Idea $ideaModel;

    #[Validate('required|string|max:2000')]
    public string $commentBody = '';

    public bool $isPrivateNote = false;

    public string $status = '';

    public string $priority = '';

    public string $impact = '';

    public string $effort = '';

    public string $statusNote = '';

    public string $duplicateOfId = '';

    public string $duplicateNote = '';

    public string $editTitle = '';

    public string $editDescription = '';

    public string $editBoardGroupId = '';

    public string $editBoardId = '';

    public string $editCategoryId = '';

    /**
     * The idea's Author/Submitted-by user (ideas.submitted_by_user_id) as
     * staged in the edit form. Distinct from entered_by_user_id, which
     * records who actually logged the idea and is never changed here.
     */
    public ?int $editAuthorUserId = null;

    public string $editAuthorName = '';

    public string $editAuthorSearch = '';

    /**
     * Bumped each time the edit-idea modal is opened, forcing a fresh Quill
     * mount with the current description — see officialResponseEditorKey.
     */
    public int $editDescriptionEditorKey = 0;

    /**
     * The idea id a pending vote move would come from, set by toggleVote()
     * when the team limits members to one active vote per board and the
     * user already has one elsewhere on this idea's board.
     */
    public ?int $pendingMoveFromIdeaId = null;

    public string $officialResponseBody = '';

    /**
     * Bumped each time the official-response modal is (re)opened, forcing a
     * fresh Quill mount with the current server-rendered value — the editor
     * element is wire:ignore'd (see the Blade markup below) so Livewire never
     * touches it in place, and this modal is reused for both Add and Edit.
     */
    public int $officialResponseEditorKey = 0;

    /**
     * Files staged for upload onto this idea (see rules() in addAttachments()
     * for the mime/size/count limits enforced on them).
     *
     * @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile>
     */
    public array $newAttachments = [];

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'duplicateOfId' => __('original idea'),
            'editTitle' => __('title'),
            'editDescription' => __('description'),
            'editBoardGroupId' => __('board group'),
            'editBoardId' => __('board'),
            'editCategoryId' => __('category'),
            'editAuthorUserId' => __('author'),
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
            ->with(['boardGroup:id,name', 'board:id,name,team_id', 'category:id,name', 'submittedBy:id,name', 'enteredBy:id,name'])
            ->firstOrFail();

        $this->status = $this->ideaModel->status;
        $this->priority = $this->ideaModel->priority;
        $this->impact = $this->ideaModel->impact;
        $this->effort = $this->ideaModel->effort;

        // Supports the "Edit idea" quick action on the ideas list
        // (ideas/{idea}?edit=1), which deep-links straight into this modal.
        if (request()->boolean('edit') && $this->canEditIdea) {
            $this->openEditIdea();
        }
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
     * Whether the current user may edit this idea: any role except
     * Employee/Viewer (canManage), or the idea's original author.
     */
    #[Computed]
    public function canEditIdea(): bool
    {
        return $this->canManage || Auth::id() === $this->ideaModel->submitted_by_user_id;
    }

    /**
     * Whether the current user may delete this idea: any role except
     * Employee/Viewer (canManage), or the idea's original author. Distinct
     * from canDelete, which additionally gates comment deletion and stays
     * owner-only.
     */
    #[Computed]
    public function canDeleteIdea(): bool
    {
        return $this->canManage || Auth::id() === $this->ideaModel->submitted_by_user_id;
    }

    /**
     * Whether the current user may post private management notes on this idea's board.
     */
    #[Computed]
    public function canPostPrivateNote(): bool
    {
        return Auth::user()->can('managePrivateNotes', $this->ideaModel->board);
    }

    /**
     * Whether the current user may flag or unflag comments (admin and above).
     */
    #[Computed]
    public function canModerate(): bool
    {
        return Auth::user()->teamRole($this->team)?->isAtLeast(TeamRole::Admin) ?? false;
    }

    /**
     * Whether the current user may publish, edit, or remove this idea's
     * official response (admin and above — a step above routine triage).
     */
    #[Computed]
    public function canRespondOfficially(): bool
    {
        return Auth::user()->teamRole($this->team)?->isAtLeast(TeamRole::Admin) ?? false;
    }

    /**
     * Whether the current user may add or remove this idea's attachments
     * (manager and above, or the person who originally submitted it).
     */
    #[Computed]
    public function canManageAttachments(): bool
    {
        return $this->canManage || Auth::id() === $this->ideaModel->submitted_by_user_id;
    }

    /**
     * Update the idea's triage fields. Records a status-history entry only when the status changes.
     */
    public function updateManagement(): void
    {
        abort_unless($this->canManage, 403);

        $validated = $this->validate([
            'status' => ['required', Rule::in(array_keys(IdeaStatus::meta()))],
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
        $this->dispatch('modal-close', name: 'manage-idea');

        Flux::toast(variant: 'success', text: __('Idea updated.'));
    }

    /**
     * Soft-delete this idea and return to the idea list.
     */
    public function deleteIdea(): void
    {
        abort_unless($this->canDeleteIdea, 403);

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
     * Open the edit-idea form pre-filled with the idea's current values.
     */
    public function openEditIdea(): void
    {
        abort_unless($this->canEditIdea, 403);

        $idea = $this->ideaModel;

        $this->editTitle = $idea->title;
        $this->editDescription = $idea->description;
        $this->editBoardGroupId = (string) $idea->board_group_id;
        $this->editBoardId = (string) $idea->board_id;
        $this->editCategoryId = (string) $idea->category_id;
        $this->editAuthorUserId = $idea->submitted_by_user_id;
        $this->editAuthorName = $idea->submittedBy?->name ?? '';
        $this->editAuthorSearch = '';

        $this->resetValidation();
        $this->editDescriptionEditorKey++;
        $this->dispatch('modal-show', name: 'edit-idea');
    }

    /**
     * Reset the chosen board and category when the edit form's board group
     * changes (categories are board-specific).
     */
    public function updatedEditBoardGroupId(): void
    {
        $this->editBoardId = '';
        $this->editCategoryId = '';
    }

    /**
     * Reset the chosen category when the edit form's board changes.
     */
    public function updatedEditBoardId(): void
    {
        $this->editCategoryId = '';
    }

    /**
     * Active team members matching the current edit-form author search term.
     * Empty until a search term is typed.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function editAuthorCandidates(): Collection
    {
        $search = trim($this->editAuthorSearch);

        if (! $this->canEditIdea || $search === '') {
            return new Collection;
        }

        return $this->team->members()
            ->where('users.is_active', true)
            ->where(fn ($query) => $query
                ->where('users.name', 'like', "%{$search}%")
                ->orWhere('users.email', 'like', "%{$search}%"))
            ->orderBy('users.name')
            ->limit(10)
            ->get(['users.id', 'users.name', 'users.email']);
    }

    /**
     * Select the Author (ideas.submitted_by_user_id) to attribute this idea
     * to. Distinct from entered_by_user_id, which is left untouched.
     */
    public function selectEditAuthor(int $userId): void
    {
        abort_unless($this->canEditIdea, 403);

        $user = $this->team->members()
            ->where('users.id', $userId)
            ->where('users.is_active', true)
            ->firstOrFail();

        $this->editAuthorUserId = $user->id;
        $this->editAuthorName = $user->name;
        $this->editAuthorSearch = '';
    }

    /**
     * Clear the staged Author selection so the edit form's search box
     * reappears, letting the user pick a different one.
     */
    public function clearEditAuthorSelection(): void
    {
        abort_unless($this->canEditIdea, 403);

        $this->reset('editAuthorUserId', 'editAuthorName');
    }

    /**
     * Active board groups for the current team, for the edit-idea form.
     *
     * @return Collection<int, \App\Models\IdeaBoardGroup>
     */
    #[Computed]
    public function editBoardGroups(): Collection
    {
        return $this->team->boardGroups()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * Active boards within the edit-idea form's selected board group.
     *
     * @return Collection<int, IdeaBoard>
     */
    #[Computed]
    public function editBoards(): Collection
    {
        if ($this->editBoardGroupId === '') {
            return new Collection;
        }

        return $this->team->boards()
            ->where('is_active', true)
            ->where('board_group_id', $this->editBoardGroupId)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * Active categories for the edit-idea form's selected board.
     *
     * @return Collection<int, \App\Models\IdeaCategory>
     */
    #[Computed]
    public function editCategories(): Collection
    {
        if ($this->editBoardId === '') {
            return new Collection;
        }

        return $this->team->categories()
            ->where('is_active', true)
            ->where('board_id', $this->editBoardId)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * Update the idea's title, description, board/category, and Author.
     * Reassigning the Author (ideas.submitted_by_user_id) behaves like any
     * other field edit — it never touches entered_by_user_id and triggers no
     * notification or approval step — but, like every other field changed
     * here, it is recorded in the Activity timeline via IdeaEditHistory for
     * transparency.
     */
    public function updateIdea(): void
    {
        abort_unless($this->canEditIdea, 403);

        $teamId = $this->team->id;

        $validated = $this->validate([
            'editTitle' => ['required', 'string', 'max:255'],
            // Raw Quill HTML — generous, this is a DoS guard only; the real
            // business-rule length check runs post-sanitization below.
            'editDescription' => ['required', 'string', 'max:100000'],
            'editBoardGroupId' => [
                'required',
                Rule::exists('idea_board_groups', 'id')->where('team_id', $teamId)->where('is_active', true),
            ],
            'editBoardId' => [
                'required',
                Rule::exists('idea_boards', 'id')
                    ->where('team_id', $teamId)
                    ->where('board_group_id', $this->editBoardGroupId)
                    ->where('is_active', true),
            ],
            'editCategoryId' => [
                'required',
                Rule::exists('idea_categories', 'id')
                    ->where('team_id', $teamId)
                    ->where('board_id', $this->editBoardId)
                    ->where('is_active', true),
            ],
            'editAuthorUserId' => [
                'required',
                'integer',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $isEligible = $this->team->members()
                        ->where('users.id', $value)
                        ->where('users.is_active', true)
                        ->exists();

                    if (! $isEligible) {
                        $fail(__('Select an active member of your organization.'));
                    }
                },
            ],
        ]);

        $cleanDescription = Purify::config('idea_rich_text')->clean($validated['editDescription']);

        if (mb_strlen(strip_tags($cleanDescription)) > 20000) {
            $this->addError('editDescription', __('Description is too long.'));

            return;
        }

        $board = IdeaBoard::whereKey($validated['editBoardId'])->where('team_id', $teamId)->firstOrFail();

        $changes = $this->summarizeIdeaEdits($validated, $cleanDescription, $board);

        $this->ideaModel->update([
            'title' => $validated['editTitle'],
            'description' => $cleanDescription,
            'description_format' => 'html',
            'board_group_id' => $board->board_group_id,
            'board_id' => $board->id,
            'category_id' => $validated['editCategoryId'],
            'submitted_by_user_id' => $validated['editAuthorUserId'],
        ]);

        if ($changes !== []) {
            IdeaEditHistory::create([
                'idea_id' => $this->ideaModel->id,
                'actor_user_id' => Auth::id(),
                'summary' => implode(' ', $changes),
            ]);

            unset($this->editHistory, $this->activityTimeline);
        }

        $this->ideaModel->load(['boardGroup:id,name', 'board:id,name,team_id', 'category:id,name', 'submittedBy:id,name', 'enteredBy:id,name']);

        $this->dispatch('modal-close', name: 'edit-idea');

        Flux::toast(variant: 'success', text: __('Idea updated.'));
    }

    /**
     * Build a human-readable, one-sentence-per-field summary of what an
     * edit-form submission actually changed on the idea, comparing against
     * its pre-update values. Returns an empty array when nothing changed
     * (e.g. the form was opened and saved without edits), so callers can
     * skip writing a no-op history entry.
     *
     * @param  array<string, mixed>  $validated
     * @return array<int, string>
     */
    private function summarizeIdeaEdits(array $validated, string $cleanDescription, IdeaBoard $newBoard): array
    {
        $idea = $this->ideaModel;
        $changes = [];

        if ($idea->title !== $validated['editTitle']) {
            $changes[] = __('Title updated.');
        }

        // Compared as plain text, not raw HTML: Quill re-serializing untouched
        // content (or re-running Purify on already-clean HTML) can shuffle
        // markup without changing what the description actually says, and
        // that shouldn't be reported as an edit.
        if (trim(strip_tags($idea->description)) !== trim(strip_tags($cleanDescription))) {
            $changes[] = __('Description updated.');
        }

        if ((int) $idea->board_id !== $newBoard->id) {
            $changes[] = __('Board changed to :board.', ['board' => $newBoard->name]);
        }

        $newCategoryId = (int) $validated['editCategoryId'];

        if ((int) $idea->category_id !== $newCategoryId) {
            $newCategoryName = IdeaCategory::whereKey($newCategoryId)->value('name') ?? __('Unknown');
            $changes[] = __('Category changed to :category.', ['category' => $newCategoryName]);
        }

        $newAuthorId = (int) $validated['editAuthorUserId'];

        if ($idea->submitted_by_user_id !== $newAuthorId) {
            $oldAuthorName = $idea->submittedBy?->name ?? __('Unknown');
            $newAuthorName = User::whereKey($newAuthorId)->value('name') ?? __('Unknown');
            $changes[] = __('Author changed from :old to :new.', ['old' => $oldAuthorName, 'new' => $newAuthorName]);
        }

        return $changes;
    }

    /**
     * Open the official response form to add a new response.
     */
    public function openAddOfficialResponse(): void
    {
        abort_unless($this->canRespondOfficially, 403);

        $this->reset('officialResponseBody');
        $this->resetValidation();
        $this->officialResponseEditorKey++;
        $this->dispatch('modal-show', name: 'official-response-form');
    }

    /**
     * Open the official response form pre-filled with the current response.
     */
    public function openEditOfficialResponse(): void
    {
        abort_unless($this->canRespondOfficially, 403);

        $this->officialResponseBody = $this->officialResponse?->body ?? '';
        $this->resetValidation();
        $this->officialResponseEditorKey++;
        $this->dispatch('modal-show', name: 'official-response-form');
    }

    /**
     * Publish a new official response, or update the existing one if this
     * idea already has one. Editing never creates a second row — the same
     * response record is reused so there is only ever one active response
     * per idea. Notifies the submitter and voters either way.
     */
    public function saveOfficialResponse(): void
    {
        abort_unless($this->canRespondOfficially, 403);

        $validated = $this->validate([
            // Raw Quill HTML — generous, this is a DoS guard only; the real
            // 5000-character business rule is checked below, post-sanitization.
            'officialResponseBody' => ['required', 'string', 'max:20000'],
        ]);

        $cleanBody = Purify::config('idea_rich_text')->clean($validated['officialResponseBody']);

        if (mb_strlen(strip_tags($cleanBody)) > 5000) {
            $this->addError('officialResponseBody', __('Official response is too long.'));

            return;
        }

        $existing = $this->ideaModel->officialResponse()->first();
        $isNewResponse = $existing === null;

        if ($isNewResponse) {
            $response = IdeaOfficialResponse::create([
                'idea_id' => $this->ideaModel->id,
                'responded_by_user_id' => Auth::id(),
                'body' => $cleanBody,
                'body_format' => 'html',
                'published_at' => now(),
            ]);
        } else {
            $existing->update(['body' => $cleanBody, 'body_format' => 'html']);
            $response = $existing;
        }

        IdeaOfficialResponseHistory::create([
            'idea_id' => $this->ideaModel->id,
            'official_response_id' => $response->id,
            'actor_user_id' => Auth::id(),
            'action' => $isNewResponse ? IdeaOfficialResponseHistory::ACTION_PUBLISHED : IdeaOfficialResponseHistory::ACTION_UPDATED,
        ]);

        $recipients = $this->officialResponseRecipients();

        if ($recipients->isNotEmpty()) {
            Notification::send(
                $recipients,
                $isNewResponse ? new OfficialResponsePublished($response) : new OfficialResponseUpdated($response),
            );
        }

        unset($this->officialResponse, $this->officialResponseHistory, $this->activityTimeline);
        $this->reset('officialResponseBody');
        $this->dispatch('modal-close', name: 'official-response-form');

        Flux::toast(variant: 'success', text: $isNewResponse ? __('Official response published.') : __('Official response updated.'));
    }

    /**
     * Remove the idea's official response (soft-delete) and hide the panel.
     */
    public function removeOfficialResponse(): void
    {
        abort_unless($this->canRespondOfficially, 403);

        $response = $this->ideaModel->officialResponse()->first();

        abort_if($response === null, 404);

        $response->delete();

        IdeaOfficialResponseHistory::create([
            'idea_id' => $this->ideaModel->id,
            'official_response_id' => $response->id,
            'actor_user_id' => Auth::id(),
            'action' => IdeaOfficialResponseHistory::ACTION_REMOVED,
        ]);

        unset($this->officialResponse, $this->officialResponseHistory, $this->activityTimeline);
        $this->dispatch('modal-close', name: 'confirm-remove-official-response');

        Flux::toast(variant: 'success', text: __('Official response removed.'));
    }

    /**
     * Upload the staged files as attachments on this idea.
     */
    public function addAttachments(): void
    {
        abort_unless($this->canManageAttachments, 403);

        $validated = $this->validate([
            'newAttachments' => ['array', 'min:1'],
            'newAttachments.*' => [
                'file',
                'max:'.config('idea_attachments.max_file_size_kb'),
                'mimes:'.implode(',', config('idea_attachments.allowed_extensions')),
            ],
        ]);

        $maxAttachments = config('idea_attachments.max_attachments_per_idea');
        $existingCount = $this->ideaModel->attachments()->count();

        if ($existingCount + count($validated['newAttachments']) > $maxAttachments) {
            $this->addError('newAttachments', __('An idea can have at most :max attachments.', ['max' => $maxAttachments]));

            return;
        }

        $actorUserId = Auth::id();
        $disk = config('idea_attachments.disk');

        foreach ($validated['newAttachments'] as $file) {
            $extension = strtolower($file->getClientOriginalExtension());
            $storedName = (string) Str::uuid().'.'.$extension;
            $path = $file->storeAs("idea-attachments/{$this->ideaModel->team_id}/{$this->ideaModel->id}", $storedName, $disk);

            $attachment = IdeaAttachment::create([
                'idea_id' => $this->ideaModel->id,
                'uploaded_by_user_id' => $actorUserId,
                'disk' => $disk,
                'path' => $path,
                'original_filename' => Str::limit(basename($file->getClientOriginalName()), 255, ''),
                'extension' => $extension,
                'mime_type' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
            ]);

            IdeaAttachmentHistory::create([
                'idea_id' => $this->ideaModel->id,
                'idea_attachment_id' => $attachment->id,
                'actor_user_id' => $actorUserId,
                'action' => IdeaAttachmentHistory::ACTION_ADDED,
                'original_filename' => $attachment->original_filename,
            ]);
        }

        $this->reset('newAttachments');
        unset($this->attachments, $this->attachmentHistory, $this->activityTimeline);
        $this->dispatch('modal-close', name: 'add-attachments');

        Flux::toast(variant: 'success', text: __('Attachment added.'));
    }

    /**
     * Delete an attachment: removes the stored file, the database row, and
     * records a removal entry in the attachment history before the row
     * (and its foreign key) is gone.
     */
    public function removeAttachment(int $attachmentId): void
    {
        abort_unless($this->canManageAttachments, 403);

        $attachment = $this->ideaModel->attachments()->whereKey($attachmentId)->firstOrFail();

        IdeaAttachmentHistory::create([
            'idea_id' => $this->ideaModel->id,
            'idea_attachment_id' => $attachment->id,
            'actor_user_id' => Auth::id(),
            'action' => IdeaAttachmentHistory::ACTION_REMOVED,
            'original_filename' => $attachment->original_filename,
        ]);

        Storage::disk($attachment->disk)->delete($attachment->path);
        $attachment->delete();

        unset($this->attachments, $this->attachmentHistory, $this->activityTimeline);
        $this->dispatch('modal-close', name: "confirm-remove-attachment-{$attachmentId}");

        Flux::toast(variant: 'success', text: __('Attachment removed.'));
    }

    /**
     * The idea's submitter and distinct voters, excluding the current actor,
     * to notify when the official response is published or updated.
     *
     * @return Collection<int, User>
     */
    private function officialResponseRecipients(): Collection
    {
        return User::query()
            ->where('id', '!=', Auth::id())
            ->where(function ($query) {
                $query->where('id', $this->ideaModel->submitted_by_user_id)
                    ->orWhereIn('id', $this->ideaModel->votes()->pluck('user_id'));
            })
            ->get();
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

            $this->dispatch('modal-close', name: 'confirm-unvote');

            return;
        }

        if ($this->team->limitsOneActiveVotePerBoard()) {
            $activeVotes = IdeaVote::activeVotesForUserOnBoard(Auth::id(), $this->ideaModel->board_id);

            if ($activeVotes->count() > 1) {
                $this->dispatch('modal-show', name: 'blocked-multiple-votes');

                return;
            }

            if ($activeVotes->count() === 1) {
                $this->pendingMoveFromIdeaId = $activeVotes->first()->idea_id;

                $this->dispatch('modal-show', name: 'confirm-move-vote');

                return;
            }
        }

        IdeaVote::firstOrCreate([
            'idea_id' => $this->ideaModel->id,
            'user_id' => Auth::id(),
        ]);

        $this->dispatch('idea-voted');

        Flux::toast(variant: 'success', text: __('You have successfully casted your vote.'));
    }

    /**
     * Confirm moving the current user's active vote from another idea on
     * this board to this idea, per the team's one-active-vote-per-board setting.
     */
    public function confirmMoveVote(): void
    {
        abort_unless($this->canParticipate, 403);
        abort_unless($this->team->limitsOneActiveVotePerBoard(), 403);

        $existingVote = IdeaVote::where('idea_id', $this->pendingMoveFromIdeaId)
            ->where('user_id', Auth::id())
            ->first();

        abort_if($existingVote === null, 404);

        IdeaVote::moveVote($existingVote, $this->ideaModel, Auth::id());

        $this->reset('pendingMoveFromIdeaId');
        $this->dispatch('modal-close', name: 'confirm-move-vote');
        $this->dispatch('idea-voted');

        Flux::toast(variant: 'success', text: __('Your vote has been moved to this idea.'));
    }

    /**
     * The current user's one-active-vote-per-board status on this idea's
     * board, for the board-vote-status header. Null when the team doesn't
     * limit votes this way, or the user isn't eligible to vote at all.
     *
     * @return array{state: string, idea?: Idea}|null
     */
    #[Computed]
    public function boardVoteStatus(): ?array
    {
        if (! $this->team->limitsOneActiveVotePerBoard() || ! $this->canParticipate) {
            return null;
        }

        $activeVotes = IdeaVote::activeVotesForUserOnBoard(Auth::id(), $this->ideaModel->board_id);

        if ($activeVotes->count() > 1) {
            return ['state' => 'blocked'];
        }

        if ($activeVotes->count() === 1) {
            return ['state' => 'assigned', 'idea' => $activeVotes->first()->idea];
        }

        return ['state' => 'available'];
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
            'is_internal' => $this->canPostPrivateNote && $this->isPrivateNote,
        ]);

        $this->reset('commentBody', 'isPrivateNote');

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

    /**
     * Flag a comment, replacing it with a moderation notice in this thread (admin and above).
     */
    public function hideComment(int $commentId): void
    {
        abort_unless($this->canModerate, 403);

        $comment = $this->ideaModel->comments()->whereKey($commentId)->firstOrFail();
        $comment->hide(Auth::id());

        unset($this->comments);

        Flux::toast(variant: 'success', text: __('Comment flagged.'));
    }

    /**
     * Unflag a previously flagged comment, restoring it in this thread (admin and above).
     */
    public function unhideComment(int $commentId): void
    {
        abort_unless($this->canModerate, 403);

        $comment = $this->ideaModel->comments()->whereKey($commentId)->firstOrFail();
        $comment->unhide();

        unset($this->comments);

        Flux::toast(variant: 'success', text: __('Comment unflagged.'));
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
     * Whether the current user may see private management notes on this idea's board.
     */
    #[Computed]
    public function canViewPrivateNotes(): bool
    {
        return Auth::user()->can('managePrivateNotes', $this->ideaModel->board);
    }

    /**
     * Comments for the idea, oldest first. Flagged comments are included so a
     * moderation notice can be shown in their place, rather than vanishing.
     *
     * @return Collection<int, \App\Models\IdeaComment>
     */
    #[Computed]
    public function comments(): Collection
    {
        return $this->ideaModel->comments()
            ->with('user:id,name')
            ->when(! $this->canViewPrivateNotes, fn ($query) => $query->where('is_internal', false))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Email addresses of the team's managers and above, offered as a contact
     * point for disputing a flagged comment.
     *
     * @return SupportCollection<int, string>
     */
    #[Computed]
    public function managerEmails(): SupportCollection
    {
        return $this->team->managers()->pluck('email');
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
     * The idea's file attachments, oldest first.
     *
     * @return Collection<int, IdeaAttachment>
     */
    #[Computed]
    public function attachments(): Collection
    {
        return $this->ideaModel->attachments()
            ->with('uploadedBy:id,name')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Attachment add/remove history, newest first.
     *
     * @return Collection<int, IdeaAttachmentHistory>
     */
    #[Computed]
    public function attachmentHistory(): Collection
    {
        return $this->ideaModel->attachmentHistory()
            ->with(['actor:id,name', 'attachment:id'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * The audit trail of "Edit idea" saves (title/description/board/category/
     * Author changes), newest first.
     *
     * @return Collection<int, IdeaEditHistory>
     */
    #[Computed]
    public function editHistory(): Collection
    {
        return $this->ideaModel->editHistory()
            ->with('actor:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * The idea's current official response, if one exists and hasn't been removed.
     */
    #[Computed]
    public function officialResponse(): ?IdeaOfficialResponse
    {
        return $this->ideaModel->officialResponse()
            ->with('respondedBy:id,name')
            ->first();
    }

    /**
     * Publish/update/remove audit trail for the idea's official response(s), newest first.
     *
     * @return Collection<int, IdeaOfficialResponseHistory>
     */
    #[Computed]
    public function officialResponseHistory(): Collection
    {
        return $this->ideaModel->officialResponseHistory()
            ->with('actor:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Merged, newest-first timeline combining status changes and official
     * response events for the Activity panel. Kept as plain objects so the
     * Blade template can render both entry types identically.
     *
     * @return SupportCollection<int, object{key: string, color: string, dotColor: string, badgeClass: string, label: string, note: ?string, attachmentUrl: ?string, actorName: string, createdAt: \Illuminate\Support\Carbon}>
     */
    #[Computed]
    public function activityTimeline(): SupportCollection
    {
        $statusEntries = $this->statusHistory->map(function (IdeaStatusHistory $entry) {
            $meta = $this->statusMeta($entry->new_status);

            return (object) [
                'key' => 'status-'.$entry->id,
                'color' => $meta['color'],
                'dotColor' => $meta['dotColor'] ?? $meta['color'],
                'badgeClass' => $meta['class'] ?? '',
                'icon' => null,
                'iconClass' => '',
                'label' => $meta['label'],
                'note' => $entry->note,
                'attachmentUrl' => null,
                'actorName' => $entry->changedBy?->name ?? __('Unknown'),
                'createdAt' => $entry->created_at,
            ];
        });

        $responseEntries = $this->officialResponseHistory->map(function (IdeaOfficialResponseHistory $entry) {
            $isRemoved = $entry->action === IdeaOfficialResponseHistory::ACTION_REMOVED;
            $color = $isRemoved ? 'red' : 'indigo';

            return (object) [
                'key' => 'official-response-'.$entry->id,
                'color' => $color,
                'dotColor' => $color,
                'badgeClass' => '',
                'icon' => $isRemoved ? 'x-circle' : null,
                'iconClass' => $isRemoved ? 'text-red-500 dark:text-red-400' : '',
                'label' => match ($entry->action) {
                    IdeaOfficialResponseHistory::ACTION_PUBLISHED => __('Official response published'),
                    IdeaOfficialResponseHistory::ACTION_UPDATED => __('Official response updated'),
                    IdeaOfficialResponseHistory::ACTION_REMOVED => __('Official response removed'),
                    default => __('Official response changed'),
                },
                'note' => null,
                'attachmentUrl' => null,
                'actorName' => $entry->actor?->name ?? __('Unknown'),
                'createdAt' => $entry->created_at,
            ];
        });

        $attachmentEntries = $this->attachmentHistory->map(function (IdeaAttachmentHistory $entry) {
            $isRemoved = $entry->action === IdeaAttachmentHistory::ACTION_REMOVED;
            $color = $isRemoved ? 'red' : 'zinc';

            return (object) [
                'key' => 'attachment-'.$entry->id,
                'color' => $color,
                'dotColor' => $color,
                'badgeClass' => '',
                'icon' => $isRemoved ? 'x-circle' : 'paper-clip',
                'iconClass' => trim('mt-1 '.($isRemoved ? 'text-red-500 dark:text-red-400' : '')),
                'label' => $isRemoved ? __('File removed') : __('File added'),
                'note' => null,
                'attachmentUrl' => (! $isRemoved && $entry->attachment)
                    ? route('ideas.attachments.download', ['current_team' => $this->team->slug, 'idea' => $this->ideaModel->slug, 'attachment' => $entry->attachment->id])
                    : null,
                'actorName' => $entry->actor?->name ?? __('Unknown'),
                'createdAt' => $entry->created_at,
            ];
        });

        $editEntries = $this->editHistory->map(function (IdeaEditHistory $entry) {
            return (object) [
                'key' => 'edit-'.$entry->id,
                'color' => 'indigo',
                'dotColor' => 'indigo',
                'badgeClass' => '',
                'icon' => 'pencil-line',
                'iconClass' => 'text-indigo-500 dark:text-indigo-400',
                'label' => __('Idea edited'),
                'note' => $entry->summary,
                'attachmentUrl' => null,
                'actorName' => $entry->actor?->name ?? __('Unknown'),
                'createdAt' => $entry->created_at,
            ];
        });

        return $statusEntries->concat($responseEntries)
            ->concat($attachmentEntries)
            ->concat($editEntries)
            ->sortByDesc('createdAt')
            ->values();
    }

    /**
     * Team roles (manager and above) keyed by user id, for the staff role
     * badge shown next to commenters who hold one of these roles.
     *
     * @return array<int, TeamRole>
     */
    #[Computed]
    public function staffRoles(): array
    {
        return $this->team->memberships()
            ->get(['user_id', 'role'])
            ->filter(fn ($membership) => $membership->role->isAtLeast(TeamRole::Manager))
            ->pluck('role', 'user_id')
            ->all();
    }

    /**
     * Get the display metadata for a status value.
     *
     * Older idea_status_history rows can still carry retired status values
     * (e.g. "under_review", from before it was folded into the single-step
     * Approve flow). The history log is append-only and never rewritten, so
     * those values are aliased here for display only.
     *
     * @return array{label: string, color: string, class?: string, dotColor?: string}
     */
    public function statusMeta(string $status): array
    {
        $status = match ($status) {
            'under_review' => 'approved',
            default => $status,
        };

        return IdeaStatus::meta()[$status] ?? ['label' => str($status)->headline()->value(), 'color' => 'zinc'];
    }

    /**
     * Get the Flux badge color for a priority/impact/effort level.
     */
    public function levelColor(string $level): string
    {
        return match ($level) {
            'low', 'small' => 'zinc',
            'medium' => 'amber',
            'high', 'large' => 'red',
            default => 'zinc',
        };
    }
}; ?>

@php($idea = $this->ideaModel)
@php($meta = $this->statusMeta($idea->status))
@php($author = $idea->is_anonymous ? __('Anonymous') : ($idea->submittedBy?->name ?? __('Unknown')))

@push('breadcrumbs')
    <x-breadcrumbs :items="[
        ['label' => __('All Ideas'), 'href' => route('ideas.index')],
        ...($idea->boardGroup ? [['label' => $idea->boardGroup->name, 'href' => route('ideas.index', ['group' => $idea->board_group_id])]] : []),
        ...($idea->board ? [['label' => $idea->board->name, 'href' => $idea->board->filterUrl()]] : []),
        ['label' => $idea->title, 'href' => null],
    ]" />
@endpush

<section class="mx-auto w-full px-3 pb-7 sm:px-6 lg:px-8">
    <div
        class="sticky top-14 z-10 -mx-3 border-b border-transparent bg-white px-3 py-3 transition-colors duration-300 sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8 dark:bg-zinc-800"
        x-data="{ scrolled: false }"
        x-init="let onScroll = () => scrolled = window.scrollY > 8; onScroll(); window.addEventListener('scroll', onScroll, { passive: true })"
        x-bind:class="scrolled ? 'border-zinc-200 dark:border-zinc-700' : ''"
    >
    <div class="flex items-center justify-between gap-3">
        <flux:link as="button" x-data x-on:click="window.history.back()" variant="subtle" class="inline-flex items-center gap-1 text-sm">
            <flux:icon.arrow-left class="size-4" />
            {{ __('Back') }}
        </flux:link>

        <div class="flex items-center gap-2">
            @if ($this->canEditIdea)
                <flux:button
                    size="sm"
                    icon="pencil-line"
                    wire:click="openEditIdea"
                    class="border-indigo-800! text-indigo-700! hover:bg-indigo-50! dark:border-indigo-500! dark:text-indigo-400! dark:hover:bg-indigo-500/10!"
                    data-test="edit-idea-button"
                >
                    {{ __('Edit Idea') }}
                </flux:button>
            @endif

            @if ($this->canRespondOfficially || $this->canEditIdea || $this->canDeleteIdea)
                <flux:dropdown position="bottom" align="end">
                    <flux:button
                        size="sm"
                        :square="false"
                        icon="ellipsis-vertical"
                        icon:trailing="chevron-down"
                        icon-trailing:class="transition-transform duration-200 group-data-open:rotate-180"
                        class="group bg-black! text-white! hover:bg-zinc-800! dark:bg-gray-500! dark:text-white! dark:hover:bg-gray-600!"
                        aria-label="{{ __('Idea actions') }}"
                        data-test="idea-actions-trigger"
                    ></flux:button>

                    <flux:menu>
                        @if ($this->canRespondOfficially)
                            <flux:menu.item
                                icon="check-badge"
                                class="[&_[data-flux-menu-item-icon]]:text-zinc-800! dark:[&_[data-flux-menu-item-icon]]:text-white!"
                                wire:click="{{ $this->officialResponse ? 'openEditOfficialResponse' : 'openAddOfficialResponse' }}"
                                data-test="official-response-menu-item"
                            >
                                {{ $this->officialResponse ? __('Edit Official Response') : __('Add Official Response') }}
                            </flux:menu.item>
                        @endif

                        @if ($this->canManage)
                            <flux:modal.trigger name="manage-idea">
                                <flux:menu.item
                                    icon="adjustments-horizontal"
                                    class="[&_[data-flux-menu-item-icon]]:text-zinc-800! dark:[&_[data-flux-menu-item-icon]]:text-white!"
                                    data-test="manage-idea-status-menu-item"
                                >
                                    {{ __('Manage Idea Status') }}
                                </flux:menu.item>
                            </flux:modal.trigger>

                            <flux:menu.separator />

                            <flux:menu.item
                                icon="document-duplicate"
                                class="[&_[data-flux-menu-item-icon]]:text-zinc-800! dark:[&_[data-flux-menu-item-icon]]:text-white!"
                                wire:click="openMarkDuplicate"
                                data-test="mark-duplicate-menu-item"
                            >
                                {{ __('Mark as Duplicate') }}
                            </flux:menu.item>

                            <flux:menu.item
                                icon="clipboard"
                                class="[&_[data-flux-menu-item-icon]]:text-zinc-800! dark:[&_[data-flux-menu-item-icon]]:text-white!"
                                x-data="{ copied: false }"
                                x-on:click="copyIdeaLink(@js(route('ideas.show', ['idea' => $this->ideaModel->slug]))).then(() => { copied = true; setTimeout(() => copied = false, 2000) })"
                                data-test="copy-idea-link-menu-item"
                            >
                                <span x-text="copied ? @js(__('Copied')) : @js(__('Copy Link'))"></span>
                            </flux:menu.item>
                        @endif

                        @if ($this->canDeleteIdea)
                            <flux:menu.separator />

                            <flux:modal.trigger name="delete-idea">
                                <flux:menu.item
                                    icon="trash"
                                    class="text-red-500! data-active:bg-red-50! [&_[data-flux-menu-item-icon]]:text-red-500! dark:text-red-400! dark:data-active:bg-red-400/10! dark:[&_[data-flux-menu-item-icon]]:text-red-400!"
                                    data-test="delete-idea-menu-item"
                                >
                                    {{ __('Delete Idea') }}
                                </flux:menu.item>
                            </flux:modal.trigger>
                        @endif
                    </flux:menu>
                </flux:dropdown>
            @endif

            @if ($this->canParticipate)
                <flux:dropdown position="bottom" align="end">
                    <flux:button
                        variant="outline"
                        size="sm"
                        icon="hand-thumb-up"
                        icon:trailing="chevron-down"
                        class="border-slate-500! text-slate-700! hover:bg-slate-50! dark:border-slate-400! dark:text-slate-400! dark:hover:bg-slate-500/10!"
                        data-test="who-voted-trigger"
                    >
                        {{ __('Who voted') }}
                    </flux:button>

                    <flux:menu class="min-w-80">
                        <div class="max-h-98 overflow-y-auto">
                            @forelse ($this->voters as $vote)
                                <flux:menu.item class="cursor-default" wire:key="voter-{{ $vote->id }}">
                                    <div class="flex items-center gap-2">
                                        <flux:avatar size="xs" :name="$vote->user->name" />
                                        <div class="min-w-0">
                                            <div class="truncate">
                                                {{ $vote->user->name }}
                                                @if ($vote->user_id === Auth::id())
                                                    <span class="text-slate-700">({{ __('You') }})</span>
                                                @endif
                                            </div>
                                            <flux:tooltip content="{{ __('Date Voted') }}">
                                                <div style="font-size:9px" class="truncate  text-slate-700">{{ $vote->created_at->forUser()->format('M j, Y g:i A') }}</div>
                                            </flux:tooltip>
                                        </div>
                                    </div>
                                </flux:menu.item>
                            @empty
                                <flux:menu.item class="cursor-default text-slate-700">
                                    {{ __('No votes yet') }}
                                </flux:menu.item>
                            @endforelse
                        </div>
                    </flux:menu>
                </flux:dropdown>
            @endif
        </div>
    </div>
    </div>

    <div class="mt-4 grid grid-cols-1 gap-6 lg:grid-cols-[1fr_300px]">
        {{-- Main column --}}
        <div>
            @if ($this->duplicateOriginal)
                <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-950/20 dark:text-amber-200" data-test="duplicate-banner">
                    {{ __('This idea was marked as a duplicate of') }}
                    <a href="{{ route('ideas.show', ['idea' => $this->duplicateOriginal->slug]) }}" wire:navigate class="font-semibold underline">{{ $this->duplicateOriginal->title }}</a>.
                </div>
            @endif

            @if ($this->boardVoteStatus)
                <div
                    x-data="{
                        dismissed: false,
                        doNotShowAgain: false,
                        storageKey: 'board-vote-status-dismissed-{{ $this->ideaModel->board_id }}',
                        init() {
                            this.dismissed = localStorage.getItem(this.storageKey) === '1';
                        },
                        dismiss() {
                            this.dismissed = true;

                            if (this.doNotShowAgain) {
                                localStorage.setItem(this.storageKey, '1');
                            }
                        },
                    }"
                    x-cloak
                    x-show="! dismissed"
                    @class([
                        'mb-4 flex items-start gap-3 rounded-lg border p-3 text-sm',
                        'border-indigo-200 bg-indigo-50 text-indigo-900 dark:border-indigo-500/30 dark:bg-indigo-950/20 dark:text-indigo-200' => $this->boardVoteStatus['state'] !== 'blocked',
                        'border-red-200 bg-red-50 text-red-900 dark:border-red-500/30 dark:bg-red-950/20 dark:text-red-200' => $this->boardVoteStatus['state'] === 'blocked',
                    ])
                    data-test="board-vote-status"
                >
                    <div class="flex-1">
                        @if ($this->boardVoteStatus['state'] === 'available')
                            {{ __('You have 1 vote available on this board.') }}
                        @elseif ($this->boardVoteStatus['state'] === 'assigned')
                            {{ __('Your vote is currently assigned to') }}
                            <a href="{{ route('ideas.show', ['idea' => $this->boardVoteStatus['idea']->slug]) }}" wire:navigate class="font-semibold underline">
                                {{ __('Idea') }} #{{ $this->boardVoteStatus['idea']->id }}: {{ $this->boardVoteStatus['idea']->title }}
                            </a>.
                        @else
                            {{ __('You can only cast one vote on this board.') }}
                        @endif
                    </div>

                    <label class="flex shrink-0 cursor-pointer items-center gap-1.5 text-xs opacity-80">
                        <input
                            type="checkbox"
                            x-model="doNotShowAgain"
                            class="size-3.5 rounded border-current/40 bg-transparent accent-current"
                            data-test="board-vote-status-dont-show-again"
                        >
                        {{ __('Do not show again') }}
                    </label>

                    <button
                        type="button"
                        x-on:click="dismiss()"
                        class="-m-1 shrink-0 rounded p-1 opacity-60 hover:opacity-100"
                        aria-label="{{ __('Dismiss') }}"
                        data-test="board-vote-status-dismiss"
                    >
                        <flux:icon.x-mark class="size-4" />
                    </button>
                </div>
            @endif

            <div class="flex gap-1">
                {{-- Vote toggle --}}
                <flux:tooltip :content="$this->canParticipate ? ($this->hasVoted ? __('You voted this idea..') : __('Click to vote for this idea..')) : __('Viewers have read-only access.')">
                    <button
                        type="button"
                        @if (! $this->hasVoted) wire:click="toggleVote" @endif
                        wire:loading.attr="disabled"
                        @disabled(! $this->canParticipate)
                        aria-pressed="{{ $this->hasVoted ? 'true' : 'false' }}"
                        @class([
                            'flex w-[72px] shrink-0 flex-col py-2.5 items-center justify-center gap-1 rounded-xl border transition',
                            'cursor-not-allowed opacity-60' => ! $this->canParticipate,
                            'cursor-pointer' => $this->canParticipate,
                            'border-indigo-200 bg-indigo-50 text-indigo-600 dark:border-indigo-500/40 dark:bg-indigo-500/10 dark:text-indigo-300' => $this->hasVoted,
                            'border-zinc-200 text-slate-600 hover:border-indigo-200 hover:text-indigo-600 dark:border-zinc-700 dark:text-slate-500 dark:hover:border-indigo-500/40' => ! $this->hasVoted,
                        ])
                        data-test="vote-button"
                        x-data="{ justVoted: false }"
                        x-on:idea-voted.window="justVoted = true; setTimeout(() => justVoted = false, 4000)"
                        @if ($this->hasVoted) x-on:click="$dispatch('modal-show', { name: 'confirm-unvote' })" @endif
                    >
                        <flux:icon.chevron-up x-show="!justVoted" class="size-5" />
                        <flux:icon.hand-thumb-up
                            x-cloak
                            x-show="justVoted"
                            x-transition:enter="transition ease-out duration-300"
                            x-transition:enter-start="opacity-0 translate-y-2"
                            x-transition:enter-end="opacity-100 translate-y-0"
                            x-transition:leave="transition ease-in duration-150"
                            x-transition:leave-start="opacity-100 translate-y-0"
                            x-transition:leave-end="opacity-0 translate-y-2"
                            class="size-5"
                        />
                        <x-vote-count :count="$this->voteCount" class="text-lg font-extrabold" />
                        <span class="text-[11px] font-medium {{ $this->hasVoted ? 'text-indigo-500/80 dark:text-indigo-300/80' : 'text-slate-700' }}">{{ trans_choice('vote|votes', $this->voteCount) }}</span>
                    </button>
                </flux:tooltip>

                {{-- Confirm unvote modal --}}
                <flux:modal name="confirm-unvote" class="max-w-lg" :dismissible="false" data-test="confirm-unvote-modal">
                    <div class="space-y-5">
                        <div>
                            <flux:heading size="lg">{{ __('Remove your vote?') }}</flux:heading>
                            <flux:text class="mt-2 text-sm text-slate-600 dark:text-slate-500">
                                {{ __('You are removing your vote from this idea.') }}
                            </flux:text>
                        </div>
                        <div class="flex justify-end gap-2">
                            <flux:modal.close><flux:button variant="ghost" data-test="confirm-unvote-cancel">{{ __('Cancel') }}</flux:button></flux:modal.close>
                            <flux:button wire:click="toggleVote" variant="danger" data-test="confirm-unvote-yes">{{ __('Yes') }}</flux:button>
                        </div>
                    </div>
                </flux:modal>

                {{-- Confirm move vote modal --}}
                <flux:modal name="confirm-move-vote" class="max-w-lg" :dismissible="false" data-test="confirm-move-vote-modal">
                    <div class="space-y-5">
                        <div>
                            <flux:heading size="lg">{{ __('Move your vote?') }}</flux:heading>
                            <flux:text class="mt-2 text-sm text-slate-600 dark:text-slate-500">
                                {{ __('You have one active vote per board. Voting for this idea will move your vote from Idea #:id.', ['id' => $this->pendingMoveFromIdeaId]) }}
                            </flux:text>
                        </div>
                        <div class="flex justify-end gap-2">
                            <flux:modal.close><flux:button variant="ghost" data-test="confirm-move-vote-cancel">{{ __('Cancel') }}</flux:button></flux:modal.close>
                            <flux:button wire:click="confirmMoveVote" variant="primary" data-test="confirm-move-vote-yes">{{ __('Move vote') }}</flux:button>
                        </div>
                    </div>
                </flux:modal>

                {{-- Blocked: multiple pre-existing active votes on this board --}}
                <flux:modal name="blocked-multiple-votes" class="max-w-lg" data-test="blocked-multiple-votes-modal">
                    <div class="space-y-4 text-center">
                        <div class="mx-auto flex size-10 items-center justify-center rounded-full bg-red-100 dark:bg-red-500/10">
                            <flux:icon.exclamation-triangle class="size-5 text-red-600 dark:text-red-400" />
                        </div>
                        <div>
                            <flux:heading size="lg">{{ __('Active Vote in Effect') }}</flux:heading>
                            <flux:text class="mt-2 text-sm text-slate-600 dark:text-slate-500">
                                {{ __('You can only cast one vote on this board.') }}
                            </flux:text>
                        </div>
                        <div class="flex justify-center">
                            <flux:modal.close>
                                <flux:button variant="primary" data-test="blocked-multiple-votes-ok">{{ __('OK') }}</flux:button>
                            </flux:modal.close>
                        </div>
                    </div>
                </flux:modal>

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

                    <div class="mt-1.5 text-xs font-medium text-slate-600 dark:text-slate-500" data-test="idea-reference">
                        {{ __('Idea') }} #{{ $idea->id }}
                    </div>

                    <flux:heading size="xl" class="mt-0.5">{{ $idea->title }}</flux:heading>

                    <div class="flex items-center gap-2 text-xs text-slate-600 dark:text-slate-500">
                        <span>
                            {{ __('Submitted by') }}
                            <span class="font-medium text-slate-800 dark:text-slate-400">{{ $author }}</span>
                            · {{ $idea->created_at->forUser()->format('M j, Y g:i A') }}
                        </span>
                    </div>

                    @if ($this->canManage && $idea->entered_by_user_id !== $idea->submitted_by_user_id)
                        <div class="mt-0.5 text-xs text-slate-500 dark:text-slate-600" data-test="idea-entered-by">
                            {{ __('Entered by :name', ['name' => $idea->enteredBy?->name ?? __('Unknown')]) }}
                        </div>
                    @endif
                </div>
            </div>

            <div
                class="mt-4"
                x-data="{
                    expanded: false,
                    overflowing: false,
                    collapsedHeight: '12rem',
                    toggle() {
                        let el = $refs.descriptionContent;

                        // Pin to the current rendered height first (can't transition
                        // from/to 'none', so this gives the browser a concrete
                        // starting point to animate from either direction).
                        el.style.maxHeight = el.scrollHeight + 'px';

                        if (this.expanded) {
                            requestAnimationFrame(() => requestAnimationFrame(() => {
                                el.style.maxHeight = this.collapsedHeight;
                            }));
                        }

                        this.expanded = ! this.expanded;
                    },
                }"
                x-init="$nextTick(() => overflowing = $refs.descriptionContent.scrollHeight > $refs.descriptionContent.clientHeight)"
            >
                <div class="relative">
                    @if ($idea->description_format === 'html')
                        <div
                            x-ref="descriptionContent"
                            x-on:transitionend="$event.propertyName === 'max-height' && expanded && ($el.style.maxHeight = 'none')"
                            style="max-height: 12rem"
                            class="idea-rich-text overflow-hidden text-[15px] leading-relaxed text-slate-800 transition-[max-height] duration-300 ease-in-out motion-reduce:transition-none dark:text-slate-400"
                        >{!! $idea->description !!}</div>
                    @else
                        <div
                            x-ref="descriptionContent"
                            x-on:transitionend="$event.propertyName === 'max-height' && expanded && ($el.style.maxHeight = 'none')"
                            style="max-height: 12rem"
                            class="overflow-hidden whitespace-pre-line text-[15px] leading-relaxed text-slate-800 transition-[max-height] duration-300 ease-in-out motion-reduce:transition-none dark:text-slate-400"
                        >{{ $idea->description }}</div>
                    @endif

                    <div
                        x-show="overflowing && !expanded"
                        x-transition.opacity.duration.200ms
                        x-cloak
                        class="pointer-events-none absolute inset-x-0 bottom-0 h-24 bg-gradient-to-t from-white from-15% to-transparent dark:from-zinc-800"
                    ></div>
                </div>

                <flux:tooltip>
                    <flux:link
                        as="button"
                        x-show="overflowing"
                        x-on:click="toggle()"
                        variant="subtle"
                        class="mt-1.5 inline-flex items-center gap-1 text-sm"
                        data-test="idea-description-toggle"
                    >
                        <span x-text="expanded ? @js(__('Less')) : @js(__('More'))"></span>
                        <flux:icon.chevron-down x-bind:class="expanded ? 'rotate-180' : ''" class="size-4 transition-transform duration-200" />
                    </flux:link>
                    <flux:tooltip.content>
                        <span x-text="expanded ? @js(__('Less info')) : @js(__('More info'))"></span>
                    </flux:tooltip.content>
                </flux:tooltip>
            </div>


            {{-- Attachments --}}
            <div class="mt-6" data-test="attachments-section">
                @if ($this->attachments->isNotEmpty() || $this->canManageAttachments)
                    <div class="flex items-center justify-between">
                        <flux:text class="text-sm font-medium text-slate-800 dark:text-slate-400">
                            {{ __('Attachments') }} @if ($this->attachments->isNotEmpty()) ({{ $this->attachments->count() }}) @endif
                        </flux:text>

                        @if ($this->canManageAttachments)
                            <flux:tooltip content="{{ __('Add Attachment..') }}">
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    icon="paper-clip"
                                    x-on:click="$dispatch('modal-show', { name: 'add-attachments' })"
                                    data-test="add-attachments-trigger"
                                >
                                    {{ __('Add') }}
                                </flux:button>
                            </flux:tooltip>
                        @endif
                    </div>

                    @foreach ($this->attachments as $attachment)
                        <div class="mt-2 flex items-center gap-3 rounded-lg border border-zinc-200 p-2 dark:border-zinc-700" data-test="attachment-row-{{ $attachment->id }}">
                            @if ($attachment->isImage())
                                <img
                                    src="{{ route('ideas.attachments.download', ['current_team' => $this->team->slug, 'idea' => $idea->slug, 'attachment' => $attachment->id]) }}"
                                    class="size-10 shrink-0 rounded object-cover"
                                    alt=""
                                >
                            @else
                                <flux:icon
                                    :name="match ($attachment->extension) {
                                        'pdf' => 'document-text',
                                        'doc', 'docx' => 'document-text',
                                        'xls', 'xlsx' => 'table-cells',
                                        'ppt', 'pptx' => 'presentation-chart-bar',
                                        default => 'paper-clip',
                                    }"
                                    class="size-8 shrink-0 text-zinc-500"
                                />
                            @endif

                            <div class="min-w-0 flex-1">
                                <div class="truncate text-sm font-medium text-slate-800 dark:text-slate-300">{{ $attachment->original_filename }}</div>
                                <div class="text-xs text-slate-500 dark:text-slate-500">
                                    {{ Number::fileSize($attachment->size_bytes) }}
                                    · {{ __('Uploaded by') }} {{ $attachment->uploadedBy?->name ?? __('Unknown') }}
                                    · {{ $attachment->created_at->forUser()->format('M j, Y') }}
                                </div>
                            </div>

                            <flux:tooltip content="{{ __('Download') }}">
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    icon="arrow-down-tray"
                                    :href="route('ideas.attachments.download', ['current_team' => $this->team->slug, 'idea' => $idea->slug, 'attachment' => $attachment->id])"
                                    aria-label="{{ __('Download') }}"
                                    data-test="attachment-download-{{ $attachment->id }}"
                                />
                            </flux:tooltip>

                            @if ($this->canManageAttachments)
                                <flux:tooltip content="{{ __('Remove') }}">
                                    <flux:button
                                        size="sm"
                                        variant="ghost"
                                        icon="x-circle"
                                        x-on:click="$dispatch('modal-show', { name: 'confirm-remove-attachment-{{ $attachment->id }}' })"
                                        aria-label="{{ __('Remove') }}"
                                        data-test="attachment-remove-trigger-{{ $attachment->id }}"
                                    />
                                </flux:tooltip>

                                <flux:modal name="confirm-remove-attachment-{{ $attachment->id }}" class="max-w-lg" :dismissible="false" data-test="confirm-remove-attachment-modal-{{ $attachment->id }}">
                                    <div class="space-y-5">
                                        <div>
                                            <flux:heading size="lg">{{ __('Remove attachment?') }}</flux:heading>
                                            <flux:text class="mt-2 text-sm text-slate-600 dark:text-slate-500">
                                                {{ __(':name will be permanently deleted. This action is recorded in the activity log.', ['name' => $attachment->original_filename]) }}
                                            </flux:text>
                                        </div>
                                        <div class="flex justify-end gap-2">
                                            <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                                            <flux:button wire:click="removeAttachment({{ $attachment->id }})" variant="danger" data-test="confirm-remove-attachment-yes-{{ $attachment->id }}">
                                                {{ __('Remove') }}
                                            </flux:button>
                                        </div>
                                    </div>
                                </flux:modal>
                            @endif
                        </div>
                    @endforeach
                @endif
            </div>

            @if ($this->canManageAttachments)
                {{-- Add attachments modal --}}
                <flux:modal name="add-attachments" class="max-w-lg" data-test="add-attachments-modal">
                    <form wire:submit="addAttachments" class="space-y-5">
                        <flux:heading size="lg">{{ __('Add attachments') }}</flux:heading>
                        <flux:input
                            type="file"
                            wire:model="newAttachments"
                            multiple
                            :label="__('Files')"
                            :description="__('You can select multiple files at once. Images, PDFs, and Office documents up to :size each.', ['size' => Number::fileSize(config('idea_attachments.max_file_size_kb') * 1024)])"
                            data-test="show-idea-attachments-input"
                        />
                        @error('newAttachments')
                            <flux:text class="text-sm text-red-600 dark:text-red-400">{{ $message }}</flux:text>
                        @enderror
                        @error('newAttachments.*')
                            <flux:text class="text-sm text-red-600 dark:text-red-400">{{ $message }}</flux:text>
                        @enderror
                        <div class="flex justify-end gap-2">
                            <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                            <flux:button variant="primary" type="submit" data-test="save-attachments">{{ __('Upload') }}</flux:button>
                        </div>
                    </form>
                </flux:modal>
            @endif

            {{-- Official response --}}
            @if ($this->officialResponse)
                <div class="mt-6 rounded-xl border-2 border-indigo-200 bg-indigo-50/60 p-5 dark:border-indigo-500/30 dark:bg-indigo-950/20" data-test="official-response-panel">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="flex items-center gap-1.5">
                            <flux:icon.check-badge class="size-5 text-indigo-800 dark:text-indigo-400" />
                            <flux:heading size="sm" class="font-bold! uppercase text-indigo-800! dark:text-indigo-300!">{{ __('Official response') }}</flux:heading>
                        </div>

                        @if ($this->canRespondOfficially)
                            <div class="flex gap-2">
                                <flux:button
                                    size="sm"
                                    icon="pencil-line"
                                    wire:click="openEditOfficialResponse"
                                    class="bg-indigo-800! text-white! hover:bg-indigo-900! dark:bg-indigo-500! dark:text-white! dark:hover:bg-indigo-400!"
                                    data-test="edit-official-response-button"
                                >
                                    {{ __('Edit') }}
                                </flux:button>
                                <flux:button
                                    size="sm"
                                    variant="outline"
                                    icon="x-circle"
                                    x-on:click="$dispatch('modal-show', { name: 'confirm-remove-official-response' })"
                                    class="border-red-500! text-red-500! hover:bg-red-50! dark:border-red-500! dark:text-red-400! dark:hover:bg-red-500/10!"
                                    data-test="remove-official-response-trigger"
                                >
                                    {{ __('Remove') }}
                                </flux:button>
                            </div>
                        @endif
                    </div>

                    <div class="flex flex-wrap items-center gap-x-1 gap-y-1 text-xs text-slate-600 dark:text-slate-500">
                        <span class="font-medium text-slate-800 dark:text-slate-300">{{ $this->officialResponse->respondedBy?->name ?? __('Unknown') }}</span>
                        <span>· {{ __('Published') }} {{ $this->officialResponse->published_at->forUser()->format('M j, Y g:i A') }}</span>
                        @if ($this->officialResponse->wasEdited())
                            <span>· {{ __('Updated') }} {{ $this->officialResponse->updated_at->forUser()->format('M j, Y g:i A') }}</span>
                        @endif
                    </div>

                    @if ($this->officialResponse->body_format === 'html')
                        <div class="idea-rich-text mt-1 text-sm leading-relaxed text-indigo-800 dark:text-indigo-300" data-test="official-response-body">
                            {!! $this->officialResponse->body !!}
                        </div>
                    @else
                        <div class="mt-1 whitespace-pre-line text-sm leading-relaxed text-indigo-800 dark:text-indigo-300" data-test="official-response-body">
                            {{ $this->officialResponse->body }}
                        </div>
                    @endif
                </div>

                {{-- Confirm remove official response modal --}}
                <flux:modal name="confirm-remove-official-response" class="max-w-lg" :dismissible="false" data-test="confirm-remove-official-response-modal">
                    <div class="space-y-5">
                        <div>
                            <flux:heading size="lg">{{ __('Remove official response?') }}</flux:heading>
                            <flux:text class="mt-2 text-sm text-slate-600 dark:text-slate-500">
                                {{ __('This will hide the official response from this idea. This action is recorded in the activity log.') }}
                            </flux:text>
                        </div>
                        <div class="flex justify-end gap-2">
                            <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                            <flux:button wire:click="removeOfficialResponse" variant="danger" data-test="confirm-remove-official-response-yes">{{ __('Remove') }}</flux:button>
                        </div>
                    </div>
                </flux:modal>
            @endif

            @if ($this->canRespondOfficially)
                {{-- Add/edit official response modal --}}
                <flux:modal name="official-response-form" class="max-w-2xl lg:min-w-4xl" data-test="official-response-form-modal">
                    <form wire:submit="saveOfficialResponse" class="space-y-5">
                        <div>
                            <flux:heading size="lg">{{ $this->officialResponse ? __('Edit official response') : __('Add official response') }}</flux:heading>
                            <flux:text class="mt-2 text-sm text-slate-600 dark:text-slate-500">
                                {{ __('This is shown prominently on the idea, separate from comments.') }}
                            </flux:text>
                        </div>
                        <div>
                            <flux:label>{{ __('Response') }}</flux:label>
                            <div
                                wire:ignore
                                wire:key="official-response-editor-{{ $officialResponseEditorKey }}"
                                x-data
                                x-init="initRichTextEditor($refs.editor, $wire, 'officialResponseBody', @js($officialResponseBody))"
                            >
                                <div x-ref="editor" data-test="official-response-textarea"></div>
                            </div>
                            @error('officialResponseBody')
                                <flux:text class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</flux:text>
                            @enderror
                        </div>
                        <div class="flex justify-end gap-2">
                            <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                            <flux:button variant="primary" type="submit" data-test="save-official-response">
                                {{ $this->officialResponse ? __('Save changes') : __('Publish') }}
                            </flux:button>
                        </div>
                    </form>
                </flux:modal>
            @endif

            <flux:separator class="my-6" />

            {{-- Comments --}}
            <flux:heading size="lg">
                {{ trans_choice(':count comment|:count comments', $this->comments->count(), ['count' => $this->comments->count()]) }}
            </flux:heading>

            {{-- Composer --}}
            @if ($this->canParticipate)
                <form wire:submit="addComment" class="mt-4 flex gap-3">
                    <flux:avatar size="sm" :name="auth()->user()->name" />
                    <div class="min-w-0 flex-1 space-y-2">
                        <flux:textarea
                            wire:model="commentBody"
                            rows="3"
                            :placeholder="__('Share your thoughts or add context…')"
                            data-test="comment-body"
                        />
                        <div class="flex items-center justify-between gap-3">
                            @if ($this->canPostPrivateNote)
                                <flux:checkbox
                                    wire:model="isPrivateNote"
                                    :label="__('Private management note')"
                                    :description="__('Only administrators and users with permission to manage ideas can see this note.')"
                                    data-test="comment-private-note"
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
                        @if ($staffRole = $this->staffRoles[$comment->user_id] ?? null)
                            <x-role-tooltip :role="$staffRole->label()">
                                <flux:avatar size="sm" :name="$comment->user?->name ?? __('Unknown')" badge:circle badge:color="{{ $staffRole->badgeColor() }}">
                                    @if ($staffRole->avatarIcon())
                                        <x-slot:badge class="h-3.5! min-w-3.5!">
                                            @if ($staffRole->avatarIcon() === 'user-shield')
                                                <flux:icon.user-shield variant="micro" class="size-2.5 text-white" />
                                            @else
                                                <flux:icon.user-round-cog variant="micro" class="size-2.5 text-white" />
                                            @endif
                                        </x-slot:badge>
                                    @endif
                                </flux:avatar>
                            </x-role-tooltip>
                        @else
                            <flux:avatar size="sm" :name="$comment->user?->name ?? __('Unknown')" />
                        @endif
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-sm font-medium text-slate-900 dark:text-slate-200">{{ $comment->user?->name ?? __('Unknown') }}</span>
                                @if ($comment->is_internal)
                                    <flux:badge color="amber" size="sm" icon="lock-closed">{{ __('Private note') }}</flux:badge>
                                @endif
                                @if ($comment->isHidden())
                                    <flux:badge color="red" size="sm" icon="flag">{{ __('Flagged') }}</flux:badge>
                                @endif
                                <flux:tooltip content="{{ $comment->created_at->forUser()->format('M j, Y g:i A') }}">
                                    <span class="text-xs text-slate-700">{{ $comment->created_at->diffForHumans() }}</span>
                                </flux:tooltip>
                            </div>

                            @if ($comment->isHidden())
                                <div class="mt-2 flex items-start gap-2 rounded-lg border border-dashed border-red-300 bg-red-50/60 p-3 dark:border-red-900 dark:bg-red-950/20">
                                    <flux:icon.flag class="mt-0.5 size-4 shrink-0 text-red-600 dark:text-red-400" />
                                    <div class="space-y-1">
                                        <p class="text-sm font-medium text-red-700 dark:text-red-400">{{ __('This comment was flagged by a moderator.') }}</p>
                                        @if ($this->managerEmails->isNotEmpty())
                                            <p class="text-xs text-slate-600 dark:text-slate-500">
                                                {{ __("If you'd like to dispute this, please reach out to :emails — we're happy to take another look.", ['emails' => $this->managerEmails->implode(', ')]) }}
                                            </p>
                                        @endif
                                    </div>
                                </div>
                            @else
                                <div class="mt-1 whitespace-pre-line text-sm text-slate-800 dark:text-slate-400">{{ $comment->body }}</div>
                            @endif
                        </div>
                        @if ($this->canModerate || $this->canDelete)
                            <flux:dropdown position="bottom" align="end">
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    icon="ellipsis-vertical"
                                    icon:variant="outline"
                                    :square="false"
                                    data-test="comment-actions-trigger"
                                />

                                <flux:menu>
                                    @if ($this->canModerate)
                                        @if ($comment->isHidden())
                                            <flux:menu.item
                                                wire:click="unhideComment({{ $comment->id }})"
                                                icon="flag-slash"
                                                icon:variant="outline"
                                                class="text-red-600! hover:text-red-700! dark:text-red-400! dark:hover:text-red-300! data-flux-menu-item-icon:text-red-600! dark:data-flux-menu-item-icon:text-red-400!"
                                                data-test="unhide-comment"
                                            >
                                                {{ __('Unflag') }}
                                            </flux:menu.item>
                                        @else
                                            <flux:menu.item
                                                wire:click="hideComment({{ $comment->id }})"
                                                icon="flag"
                                                icon:variant="outline"
                                                class="text-red-600! hover:text-red-700! dark:text-red-400! dark:hover:text-red-300! data-flux-menu-item-icon:text-red-600! dark:data-flux-menu-item-icon:text-red-400!"
                                                data-test="hide-comment"
                                            >
                                                {{ __('Flag') }}
                                            </flux:menu.item>
                                        @endif
                                    @endif

                                    @if ($this->canDelete)
                                        <flux:menu.item
                                            wire:click="deleteComment({{ $comment->id }})"
                                            wire:confirm="{{ __('Delete this comment?') }}"
                                            icon="trash"
                                            icon:variant="outline"
                                            class="text-red-600! hover:text-red-700! dark:text-red-400! dark:hover:text-red-300! data-flux-menu-item-icon:text-red-600! dark:data-flux-menu-item-icon:text-red-400!"
                                            data-test="delete-comment"
                                        >
                                            {{ __('Delete') }}
                                        </flux:menu.item>
                                    @endif
                                </flux:menu>
                            </flux:dropdown>
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
            {{-- Edit idea modal --}}
            @if ($this->canEditIdea)
                <flux:modal name="edit-idea" class="flex max-h-[85vh] max-w-2xl flex-col lg:min-w-4xl" data-test="edit-idea-modal">
                    <form wire:submit="updateIdea" class="flex min-h-0 flex-1 flex-col">
                        <flux:heading size="lg" class="shrink-0 border-b border-zinc-200 pb-4 dark:border-zinc-700">{{ __('Edit idea') }}</flux:heading>

                        <div class="mt-5 min-h-0 flex-1 space-y-5 overflow-y-auto">

                        <flux:input
                            wire:model="editTitle"
                            :label="__('Title')"
                            type="text"
                            required
                            maxlength="255"
                            data-test="edit-idea-title"
                        />

                        <div>
                            <flux:label>{{ __('Author') }}</flux:label>
                            <flux:text class="mt-0.5 text-xs text-slate-600 dark:text-slate-500">
                                {{ __('Who this idea is attributed to. Change this to reassign an idea logged on someone\'s behalf once they have an account.') }}
                            </flux:text>

                            <div class="mt-1.5 space-y-2" data-test="edit-idea-author">
                                @if ($editAuthorUserId)
                                    <div class="flex items-center justify-between rounded-lg border border-zinc-200 bg-zinc-50 p-3 dark:border-zinc-700 dark:bg-zinc-800" data-test="edit-author-selected">
                                        <div class="flex items-center gap-3">
                                            <flux:avatar :name="$editAuthorName" size="xs" />
                                            <span class="font-medium text-slate-900 dark:text-slate-200">{{ $editAuthorName }}</span>
                                        </div>
                                        <flux:button wire:click="clearEditAuthorSelection" variant="ghost" size="sm" data-test="change-edit-author">
                                            {{ __('Change') }}
                                        </flux:button>
                                    </div>
                                @else
                                    <div class="relative">
                                        <flux:input
                                            wire:model.live.debounce.300ms="editAuthorSearch"
                                            :placeholder="__('Search for a colleague...')"
                                            data-test="edit-author-search-input"
                                        />
                                        <flux:error name="editAuthorUserId" />

                                        @if (trim($editAuthorSearch) !== '')
                                            <div class="mt-1 max-h-56 space-y-1 overflow-y-auto rounded-lg border border-zinc-200 p-1 dark:border-zinc-700" data-test="edit-author-results">
                                                @forelse ($this->editAuthorCandidates as $candidate)
                                                    <button
                                                        type="button"
                                                        wire:click="selectEditAuthor({{ $candidate->id }})"
                                                        wire:key="edit-author-candidate-{{ $candidate->id }}"
                                                        class="flex w-full items-center gap-3 rounded-md p-2 text-start hover:bg-zinc-100 dark:hover:bg-zinc-800"
                                                        data-test="edit-author-option"
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
                        </div>

                        <flux:select wire:model.live="editBoardGroupId" :label="__('Board group')" :placeholder="__('Choose a board group')" required data-test="edit-idea-board-group">
                            @foreach ($this->editBoardGroups as $group)
                                <flux:select.option value="{{ $group->id }}">{{ $group->name }}</flux:select.option>
                            @endforeach
                        </flux:select>

                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <flux:select
                                wire:model.live="editBoardId"
                                :label="__('Board')"
                                :placeholder="$editBoardGroupId === '' ? __('Select a board group first') : __('Choose a board')"
                                :disabled="$editBoardGroupId === ''"
                                required
                                data-test="edit-idea-board"
                            >
                                @foreach ($this->editBoards as $board)
                                    <flux:select.option value="{{ $board->id }}">{{ $board->name }}</flux:select.option>
                                @endforeach
                            </flux:select>

                            <flux:select
                                wire:model="editCategoryId"
                                :label="__('Category')"
                                :placeholder="$editBoardId === '' ? __('Select a board first') : __('Choose a category')"
                                :disabled="$editBoardId === ''"
                                required
                                data-test="edit-idea-category"
                            >
                                @foreach ($this->editCategories as $category)
                                    <flux:select.option value="{{ $category->id }}">{{ $category->name }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        </div>

                        <div>
                            <flux:label>{{ __('Description') }}</flux:label>
                            <div
                                wire:ignore
                                wire:key="edit-idea-description-editor-{{ $editDescriptionEditorKey }}"
                                x-data
                                x-init="initRichTextEditor($refs.editor, $wire, 'editDescription', @js($editDescription))"
                            >
                                <div x-ref="editor" data-test="edit-idea-description"></div>
                            </div>
                            @error('editDescription')
                                <flux:text class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</flux:text>
                            @enderror
                        </div>

                        </div>

                        <div class="mt-5 flex shrink-0 justify-end gap-2 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                            <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                            <flux:button variant="primary" type="submit" wire:loading.attr="disabled" data-test="edit-idea-save">
                                {{ __('Save changes') }}
                            </flux:button>
                        </div>
                    </form>
                </flux:modal>
            @endif

            {{-- Manage idea status modal (owner/admin/manager only) --}}
            @if ($this->canManage)
                <flux:modal name="manage-idea" class="max-w-3xl" data-test="manage-idea-modal">
                    <div class="space-y-5">
                        <flux:heading size="lg">{{ __('Manage idea') }}</flux:heading>

                        <form wire:submit="updateManagement" id="manage-idea-form" class="space-y-4">
                            <div class="grid grid-cols-2 gap-6 sm:grid-cols-4">
                                <flux:select wire:model="status" :label="__('Status')" size="sm" data-test="manage-status">
                                    @foreach (IdeaStatus::meta() as $value => $statusMeta)
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
                            </div>

                            <flux:textarea
                                wire:model="statusNote"
                                :label="__('Status note (optional)')"
                                rows="4"
                                :placeholder="__('Added to the activity log when the status changes')"
                                class="w-full"
                                data-test="manage-note"
                            />
                        </form>

                        <div class="flex justify-end border-t border-zinc-200 pt-4 dark:border-zinc-700">
                            <flux:button variant="primary" type="submit" form="manage-idea-form" size="sm" wire:loading.attr="disabled" data-test="manage-save">
                                {{ __('Save changes') }}
                            </flux:button>
                        </div>
                    </div>
                </flux:modal>

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

            {{-- Delete idea modal --}}
            @if ($this->canDeleteIdea)
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

            {{-- More details --}}
            <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" data-test="more-details-panel">
                <div class="flex items-center gap-1.5">
                    <flux:icon.information-circle class="size-4 shrink-0 text-slate-700" />
                    <flux:heading size="sm">{{ __('More details') }}</flux:heading>
                </div>

                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex items-center justify-between">
                        <dt class="text-slate-600 dark:text-slate-500">{{ __('Status') }}</dt>
                        <dd><flux:badge size="sm" :color="$meta['color']" class="{{ $meta['class'] ?? '' }}" data-test="more-details-status">{{ $meta['label'] }}</flux:badge></dd>
                    </div>
                    <div class="flex items-center justify-between">
                        <dt class="text-slate-600 dark:text-slate-500">{{ __('Priority') }}</dt>
                        <dd><flux:badge size="sm" :color="$this->levelColor($idea->priority)" data-test="more-details-priority">{{ self::PRIORITY_OPTIONS[$idea->priority] ?? $idea->priority }}</flux:badge></dd>
                    </div>
                    <div class="flex items-center justify-between">
                        <dt class="text-slate-600 dark:text-slate-500">{{ __('Impact') }}</dt>
                        <dd><flux:badge size="sm" :color="$this->levelColor($idea->impact)" data-test="more-details-impact">{{ self::IMPACT_OPTIONS[$idea->impact] ?? $idea->impact }}</flux:badge></dd>
                    </div>
                    <div class="flex items-center justify-between">
                        <dt class="text-slate-600 dark:text-slate-500">{{ __('Effort') }}</dt>
                        <dd><flux:badge size="sm" :color="$this->levelColor($idea->effort)" data-test="more-details-effort">{{ self::EFFORT_OPTIONS[$idea->effort] ?? $idea->effort }}</flux:badge></dd>
                    </div>
                </dl>
            </div>

            {{-- Activity timeline --}}
            <ui-disclosure open class="group/disclosure block overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                <button type="button" class="group/disclosure-button flex w-full items-center justify-between p-5" data-test="activity-panel-toggle">
                    <div class="flex items-center gap-1.5">
                        <flux:icon.timeline class="size-4 shrink-0 text-slate-700" />
                        <flux:heading size="sm">{{ __('Activity') }}</flux:heading>
                    </div>
                    <flux:icon.chevron-right class="size-4 shrink-0 text-slate-700 group-data-open/disclosure-button:hidden rtl:rotate-180" />
                    <flux:icon.chevron-down class="hidden size-4 shrink-0 text-slate-700 group-data-open/disclosure-button:block" />
                </button>

                <div class="grid grid-rows-[0fr] transition-[grid-template-rows] duration-300 ease-in-out data-open:grid-rows-[1fr]" data-open>
                    <div class="overflow-hidden">
                        <div class="px-5 pb-5">
                            @forelse ($this->activityTimeline as $entry)
                                <div class="flex gap-3" wire:key="{{ $entry->key }}">
                                    <div class="flex flex-col items-center">
                                        @if ($entry->icon)
                                            <flux:icon :icon="$entry->icon" class="size-4 shrink-0 {{ $entry->iconClass }}" />
                                        @else
                                            {{-- Timeline is newest-first, so the first dot is the most recent event: pulse it. --}}
                                            <x-status-dot :color="$entry->dotColor" size="size-2.5" class="mt-1.5" :pulse="$loop->first" />
                                        @endif
                                        @unless ($loop->last)
                                            <span class="w-px flex-1 bg-zinc-200 dark:bg-zinc-700"></span>
                                        @endunless
                                    </div>
                                    <div class="min-w-0 flex-1 {{ $loop->last ? '' : 'pb-4' }}">
                                        @if ($entry->attachmentUrl)
                                            <a href="{{ $entry->attachmentUrl }}" class="inline-block">
                                                <flux:badge :color="$entry->color" size="sm" class="{{ $entry->badgeClass }} cursor-pointer hover:underline">{{ $entry->label }}</flux:badge>
                                            </a>
                                        @else
                                            <flux:badge :color="$entry->color" size="sm" class="{{ $entry->badgeClass }}">{{ $entry->label }}</flux:badge>
                                        @endif
                                        @if ($entry->note)
                                            <p class="mt-0.5 text-xs text-slate-600 dark:text-slate-400">{{ $entry->note }}</p>
                                        @endif
                                        <p class="mt-1 text-xs text-slate-700">
                                            {{ $entry->actorName }}
                                            @if ($entry->createdAt)
                                                · {{ $entry->createdAt->diffForHumans() }}
                                            @endif
                                        </p>
                                    </div>
                                </div>
                            @empty
                                <flux:text class="text-sm text-slate-600 dark:text-slate-500">{{ __('No activity yet.') }}</flux:text>
                            @endforelse
                        </div>
                    </div>
                </div>
            </ui-disclosure>

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
