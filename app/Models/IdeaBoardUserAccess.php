<?php

namespace App\Models;

use App\Enums\AccessLevel;
use Database\Factories\IdeaBoardUserAccessFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A per-user override granting a specific user access to a specific board,
 * independent of their team-wide role. Used to scope private-management-note
 * authorization to individual board managers/moderators.
 *
 * @property int $id
 * @property int $board_id
 * @property int $user_id
 * @property AccessLevel $access_level
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

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_level' => AccessLevel::class,
        ];
    }
}
