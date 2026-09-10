<?php

namespace App\Models;

use Database\Factories\IdeaAttachmentHistoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only audit trail for attachment add/remove actions, kept separate
 * from the other Idea history tables since attachments are not part of the
 * status or official-response workflows. The attachment row it points to may
 * be hard-deleted after a removal, so the original filename is denormalized
 * here to keep the entry meaningful.
 *
 * @property int $id
 * @property int $idea_id
 * @property int|null $idea_attachment_id
 * @property int $actor_user_id
 * @property string $action
 * @property string $original_filename
 * @property Carbon|null $created_at
 * @property-read Idea $idea
 * @property-read IdeaAttachment|null $attachment
 * @property-read User $actor
 */
#[Fillable(['idea_id', 'idea_attachment_id', 'actor_user_id', 'action', 'original_filename'])]
class IdeaAttachmentHistory extends Model
{
    /** @use HasFactory<IdeaAttachmentHistoryFactory> */
    use HasFactory;

    public const ACTION_ADDED = 'added';

    public const ACTION_REMOVED = 'removed';

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'idea_attachment_history';

    /**
     * The name of the "updated at" column.
     *
     * This is an append-only log with only a created_at column.
     *
     * @var string|null
     */
    public const UPDATED_AT = null;

    /**
     * Get the idea this history entry belongs to.
     *
     * @return BelongsTo<Idea, $this>
     */
    public function idea(): BelongsTo
    {
        return $this->belongsTo(Idea::class);
    }

    /**
     * Get the attachment this history entry relates to, if it still exists.
     *
     * @return BelongsTo<IdeaAttachment, $this>
     */
    public function attachment(): BelongsTo
    {
        return $this->belongsTo(IdeaAttachment::class, 'idea_attachment_id');
    }

    /**
     * Get the user who performed this action.
     *
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
