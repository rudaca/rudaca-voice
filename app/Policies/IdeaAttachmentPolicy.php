<?php

namespace App\Policies;

use App\Models\Idea;
use App\Models\IdeaAttachment;
use App\Models\User;

class IdeaAttachmentPolicy
{
    /**
     * Determine whether the user may view/download the attachment, given the
     * idea it's claimed to belong to. The caller is responsible for resolving
     * $idea with the correct team + visibility scoping first — this only
     * guards against an attachment id being swapped for one belonging to a
     * different idea (or, transitively, a different team).
     */
    public function view(User $user, IdeaAttachment $attachment, Idea $idea): bool
    {
        return $attachment->idea_id === $idea->id;
    }
}
