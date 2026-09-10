# Idea Concept: Ownership, Attribution & Permissions

This document explains the business logic behind who an idea "belongs to" and
who may manage it — the two-column ownership model introduced by the
"Submit idea on behalf" feature, and how it interacts with team roles.

## Two identity columns on `ideas`

An idea tracks two separate users, because the person typing an idea into the
system is not always the person whose idea it is:

| Column | Meaning | Set by |
|---|---|---|
| `submitted_by_user_id` | The idea's **owner of record** — whose idea this actually is. Drives permissions and shows up as the author everywhere in the UI. | The logged-in user, unless they pick someone else via "Submit idea on behalf" |
| `entered_by_user_id` | Who **physically logged it** — always the authenticated user at creation time. Attribution only. | Always `Auth::id()` |

From [⚡create.blade.php](../resources/views/pages/ideas/⚡create.blade.php)'s `save()`:

```php
$enteredByUserId = Auth::id();
$submittedByUserId = $validated['on_behalf_of_user_id'] ?? $enteredByUserId;

Idea::create([
    ...
    'submitted_by_user_id' => $submittedByUserId,
    'entered_by_user_id' => $enteredByUserId,
    ...
]);
```

**Default case** — the "Submit idea on behalf" switch is left off, or the
colleague search is left blank: both columns are the same value, the current
user. This is indistinguishable from the pre-on-behalf-of behavior, and every
idea created before this feature shipped was backfilled the same way
(`entered_by_user_id = submitted_by_user_id`).

**On-behalf-of case** — the user turns the switch on and searches for a
colleague: `submitted_by_user_id` becomes the colleague (the idea's owner),
while `entered_by_user_id` stays the person who entered it. The show page
displays both when they differ, e.g. "logged by X on behalf of Y"
(`⚡show.blade.php:1339`, gated on `$idea->entered_by_user_id !== $idea->submitted_by_user_id`).

`entered_by_user_id` is **never** used in a permission check — it is purely
for attribution/display.

## Who may submit on behalf of someone else

Gated by a team permission, not a hardcoded role check:

```php
// ⚡create.blade.php
public function canSubmitOnBehalf(): bool
{
    return Auth::user()->hasTeamPermission($this->team, TeamPermission::SubmitIdeaOnBehalf);
}
```

`TeamPermission::SubmitIdeaOnBehalf` is granted to **Owner** (via `Owner =>
TeamPermission::cases()`, i.e. everything) and **Admin** only
(`app/Enums/TeamRole.php`). Manager, Employee, and Viewer cannot submit on
behalf of another member — the switch and colleague-search field are hidden
from them entirely, not just disabled.

The candidate list (`onBehalfOfCandidates()`) is scoped to members of the
current team only — you cannot submit on behalf of someone outside your team.

## Who may manage an existing idea

Team roles, from highest to lowest: **Owner > Admin > Manager > Employee >
Viewer**. Idea-related permission checks in `⚡show.blade.php` use
`TeamRole::isAtLeast(...)`:

| Capability | Required role | Computed property |
|---|---|---|
| Delete a comment | Owner | `canDelete()` |
| Moderate (internal comments, etc.) | Admin+ | `canModerate()` |
| Post an official response | Admin+ | `canRespondOfficially()` |
| Manage idea (status, priority/impact/effort, mark duplicate) | Manager+ | `canManage()` |
| Vote / comment | Employee+ (Viewer is read-only) | `canParticipate()` |
| **Manage attachments (add/remove)** | Manager+, **or** the idea's owner regardless of role | `canManageAttachments()` |
| **Edit an idea** (title, description, board/category, Author) | Manager+, **or** the idea's owner regardless of role | `canEditIdea()` |
| **Delete an idea** | Manager+, **or** the idea's owner regardless of role | `canDeleteIdea()` |

Note that `canDelete()` (Owner only) and `canDeleteIdea()` (Manager+ or owner) are
deliberately separate properties: `canDelete()` still gates comment deletion
and moderation-adjacent actions, while `canDeleteIdea()` governs the idea
itself. Loosening one must never accidentally loosen the other.

`canManageAttachments()` is the one exception to the strict role hierarchy —
it deliberately checks the idea's `submitted_by_user_id`, not
`entered_by_user_id`:

```php
public function canManageAttachments(): bool
{
    return $this->canManage || Auth::id() === $this->ideaModel->submitted_by_user_id;
}
```

So an Employee or Viewer who is the idea's **owner** (`submitted_by_user_id`)
can still add/remove their own attachments, even though they generally can't
manage other ideas. If that same idea was entered *on their behalf* by an
Admin, the Admin is not automatically granted attachment rights by having
been the one who typed it in — they'd need Manager+ role separately, or to be
the owner themselves.

## Editing an idea after creation

`canEditIdea()` and `canDeleteIdea()` follow the exact same "Manager+, or the
owner" shape as `canManageAttachments()`, and for the same reason — an
Employee or Viewer who owns an idea should still be able to fix a typo or
delete it, without gaining any authority over other people's ideas:

```php
public function canEditIdea(): bool
{
    return $this->canManage || Auth::id() === $this->ideaModel->submitted_by_user_id;
}

public function canDeleteIdea(): bool
{
    return $this->canManage || Auth::id() === $this->ideaModel->submitted_by_user_id;
}
```

**Editable fields**: Title, Description, Board group/Board/Category, and
**Author** (i.e. `submitted_by_user_id` — see the table above for why this,
and not `entered_by_user_id`, is the column the Author field reads from and
writes to). The Author picker only offers active members of the current
team — no free text, no placeholder authors.

**Reassigning the Author behaves like any other field edit**:

- No notification to the previous author.
- No approval step.
- The previous author does not retain any special rights as a result of the
  change — their edit/delete access, like everyone else's, is re-evaluated
  from `submitted_by_user_id` on every request.
- `entered_by_user_id` is never touched by this action, and stays a
  completely separate field — see "Two identity columns" above.

It **is**, however, recorded in the idea's Activity timeline like every other
field changed by the same save — see the next section.

## Edit history: what changed is recorded in the Activity timeline

Every `updateIdea()` save that actually changes something writes one
`IdeaEditHistory` row summarizing every field that changed, so the Activity
panel gives a transparent account of who edited an idea and what they
touched — including who a reassigned Author used to be:

```php
// ⚡show.blade.php
private function summarizeIdeaEdits(array $validated, string $cleanDescription, IdeaBoard $newBoard): array
{
    $idea = $this->ideaModel;
    $changes = [];

    if ($idea->title !== $validated['editTitle']) {
        $changes[] = __('Title updated.');
    }

    if (trim(strip_tags($idea->description)) !== trim(strip_tags($cleanDescription))) {
        $changes[] = __('Description updated.');
    }

    // ...board, category, and Author compared the same way...

    return $changes;
}
```

Design notes:

- **One row per save, not per field.** A single edit touching Title, Board,
  and Author produces one `IdeaEditHistory` entry whose `summary` lists all
  three, e.g. `"Title updated. Board changed to Support. Author changed from
  Jane Doe to John Smith."` — not three separate timeline entries. This keeps
  a multi-field edit from flooding the Activity panel the way one row per
  field would.
- **No-op saves write nothing.** If the form is opened and saved without
  actually changing anything, `summarizeIdeaEdits()` returns an empty array
  and no history row (and no timeline entry) is created.
- **The description diff is text-only, not raw HTML.** Comparing
  `strip_tags()` output rather than the raw HTML avoids false positives from
  Quill re-serializing untouched content, or Purify reprocessing HTML that
  was already clean — both can shuffle markup without changing what the
  description actually says.
- **Old/new names are denormalized into the summary string** at write time
  (e.g. the Author's old and new name, the Board/Category's new name), the
  same way `IdeaAttachmentHistory` denormalizes `original_filename` — so the
  entry stays meaningful even if that board, category, or user is later
  renamed or removed.
- This is intentionally a *separate* table from `IdeaStatusHistory`,
  `IdeaOfficialResponseHistory`, and `IdeaAttachmentHistory` — same
  append-only, `actor_user_id` + `created_at`-only shape, but its own
  concern. `activityTimeline()` merges all four into one newest-first feed
  for display.

**Where this shows up**:

- The idea detail page's actions menu (`⚡show.blade.php`) exposes "Edit
  Idea" and "Delete Idea" independently, each gated on its own computed
  property — so an idea's Employee-owner sees Edit/Delete without also
  seeing Manager-only actions like "Manage Idea Status" or "Mark as
  Duplicate".
- The all-ideas list (`⚡index.blade.php`) shows the same two actions as
  hover-revealed row buttons (always visible below the `lg` breakpoint,
  since there's no hover on touch), gated by a per-idea
  `canManageIdea(Idea $idea)` helper that applies the identical rule. Its
  Edit button deep-links to `ideas/{slug}?edit=1`, which the show page's
  `mount()` detects (checking `canEditIdea` again server-side) to
  auto-open the same Edit Idea modal — no separate edit form is
  maintained for the list.

## Why this split matters for future features

Any new "who can do X to this idea" feature should ask: does this belong to
the *owner* of the idea, or does it require *elevated team role*? Use
`submitted_by_user_id` for the former, `TeamRole::isAtLeast(...)` for the
latter. Never gate a permission on `entered_by_user_id` — it exists only to
answer "who typed this in," not "whose idea is this" or "who may act on it."

`canManageAttachments()`, `canEditIdea()`, `canDeleteIdea()`, and the index
page's `canManageIdea(Idea $idea)` are all the same shape: `canManage ||
Auth::id() === $idea->submitted_by_user_id`. Reach for this pattern whenever
a new capability is "Manager+, or whoever owns the idea" — and give it its
own named property rather than folding it into an existing one, so tightening
or loosening one capability (e.g. comment deletion via `canDelete()`) can
never accidentally change another (e.g. idea deletion via `canDeleteIdea()`).
