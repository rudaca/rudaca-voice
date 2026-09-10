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
| Delete an idea | Owner | `canDelete()` |
| Moderate (internal comments, etc.) | Admin+ | `canModerate()` |
| Post an official response | Admin+ | `canRespondOfficially()` |
| Manage idea (status, general edits) | Manager+ | `canManage()` |
| Vote / comment | Employee+ (Viewer is read-only) | `canParticipate()` |
| **Manage attachments (add/remove)** | Manager+, **or** the idea's owner regardless of role | `canManageAttachments()` |

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

## Why this split matters for future features

Any new "who can do X to this idea" feature should ask: does this belong to
the *owner* of the idea, or does it require *elevated team role*? Use
`submitted_by_user_id` for the former, `TeamRole::isAtLeast(...)` for the
latter. Never gate a permission on `entered_by_user_id` — it exists only to
answer "who typed this in," not "whose idea is this" or "who may act on it."
