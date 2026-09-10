<?php

namespace App\Models;

use Database\Factories\IdeaEditHistoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only audit trail for edits made via the "Edit idea" form (title,
 * description, board/category, and Author reassignment), kept separate from
 * the other Idea history tables since it covers a distinct workflow. Each row
 * summarizes every field changed in a single save, rather than one row per
 * field, to keep the Activity timeline from being flooded by a single edit.
 *
 * @property int $id
 * @property int $idea_id
 * @property int $actor_user_id
 * @property string $summary
 * @property Carbon|null $created_at
 * @property-read Idea $idea
 * @property-read User $actor
 */
#[Fillable(['idea_id', 'actor_user_id', 'summary'])]
class IdeaEditHistory extends Model
{
    /** @use HasFactory<IdeaEditHistoryFactory> */
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'idea_edit_history';

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
     * Get the user who made this edit.
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
