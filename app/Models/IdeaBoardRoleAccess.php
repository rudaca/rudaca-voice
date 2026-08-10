<?php

namespace App\Models;

use App\Enums\TeamRole;
use Database\Factories\IdeaBoardRoleAccessFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A per-role grant giving every team member holding the given role access to
 * a specific board, independent of the team-wide role hierarchy. Used to
 * scope private-management-note authorization to individual board
 * managers/moderators.
 *
 * @property int $id
 * @property int $board_id
 * @property TeamRole $role
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read IdeaBoard $board
 */
#[Fillable(['board_id', 'role'])]
class IdeaBoardRoleAccess extends Model
{
    /** @use HasFactory<IdeaBoardRoleAccessFactory> */
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'idea_board_role_access';

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
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => TeamRole::class,
        ];
    }
}
