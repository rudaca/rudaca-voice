<?php

namespace App\Models;

use Database\Factories\IdeaBoardUserAccessFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Phase 2 / Planned model. Schema only — per-user board access is not enforced yet.
 *
 * @property int $id
 * @property int $board_id
 * @property int $user_id
 * @property string $access_level
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read IdeaBoard $board
 * @property-read User $user
 */
#[Fillable(['board_id', 'user_id', 'access_level'])]
class IdeaBoardUserAccess extends Model
{
    /** @use HasFactory<IdeaBoardUserAccessFactory> */
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'idea_board_user_access';

    /**
     * Get the board this access rule applies to.
     *
     * @return BelongsTo<IdeaBoard, $this>
     */
    public function board(): BelongsTo
    {
        return $this->belongsTo(IdeaBoard::class, 'board_id');
    }

    /**
     * Get the user this access rule applies to.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
