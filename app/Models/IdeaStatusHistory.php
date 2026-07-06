<?php

namespace App\Models;

use Database\Factories\IdeaStatusHistoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $idea_id
 * @property int $changed_by_user_id
 * @property string $old_status
 * @property string $new_status
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property-read Idea $idea
 * @property-read User $changedBy
 */
#[Fillable(['idea_id', 'changed_by_user_id', 'old_status', 'new_status', 'note'])]
class IdeaStatusHistory extends Model
{
    /** @use HasFactory<IdeaStatusHistoryFactory> */
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'idea_status_history';

    /**
     * The name of the "updated at" column.
     *
     * This is an append-only log with only a created_at column.
     *
     * @var string|null
     */
    public const UPDATED_AT = null;

    /**
     * Get the idea whose status changed.
     *
     * @return BelongsTo<Idea, $this>
     */
    public function idea(): BelongsTo
    {
        return $this->belongsTo(Idea::class);
    }

    /**
     * Get the user who changed the status.
     *
     * @return BelongsTo<User, $this>
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
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
