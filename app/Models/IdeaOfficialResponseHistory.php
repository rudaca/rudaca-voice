<?php

namespace App\Models;

use Database\Factories\IdeaOfficialResponseHistoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only audit trail for official-response publish/update/remove
 * actions, kept separate from idea_status_history since official responses
 * are not part of the status workflow.
 *
 * @property int $id
 * @property int $idea_id
 * @property int|null $official_response_id
 * @property int $actor_user_id
 * @property string $action
 * @property Carbon|null $created_at
 * @property-read Idea $idea
 * @property-read IdeaOfficialResponse|null $officialResponse
 * @property-read User $actor
 */
#[Fillable(['idea_id', 'official_response_id', 'actor_user_id', 'action'])]
class IdeaOfficialResponseHistory extends Model
{
    /** @use HasFactory<IdeaOfficialResponseHistoryFactory> */
    use HasFactory;

    public const ACTION_PUBLISHED = 'published';

    public const ACTION_UPDATED = 'updated';

    public const ACTION_REMOVED = 'removed';

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'idea_official_response_history';

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
     * Get the official response this history entry relates to.
     *
     * @return BelongsTo<IdeaOfficialResponse, $this>
     */
    public function officialResponse(): BelongsTo
    {
        return $this->belongsTo(IdeaOfficialResponse::class);
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
