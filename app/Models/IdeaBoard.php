<?php

namespace App\Models;

use Database\Factories\IdeaBoardFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property int|null $board_group_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string $visibility
 * @property int $sort_order
 * @property bool $is_active
 * @property int $created_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read IdeaBoardGroup|null $boardGroup
 * @property-read User $createdBy
 * @property-read Collection<int, IdeaCategory> $categories
 * @property-read Collection<int, Idea> $ideas
 * @property-read Collection<int, IdeaComment> $comments
 * @property-read Collection<int, IdeaBoardRoleAccess> $roleAccess
 * @property-read Collection<int, IdeaBoardUserAccess> $userAccess
 */
#[Fillable(['team_id', 'board_group_id', 'name', 'slug', 'description', 'visibility', 'sort_order', 'is_active', 'created_by_user_id'])]
class IdeaBoard extends Model
{
    /** @use HasFactory<IdeaBoardFactory> */
    use HasFactory;

    /**
     * Get the team that owns the board.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the group the board belongs to.
     *
     * @return BelongsTo<IdeaBoardGroup, $this>
     */
    public function boardGroup(): BelongsTo
    {
        return $this->belongsTo(IdeaBoardGroup::class, 'board_group_id');
    }

    /**
     * Get the user who created the board.
     *
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Get the categories that belong to this board.
     *
     * @return HasMany<IdeaCategory, $this>
     */
    public function categories(): HasMany
    {
        return $this->hasMany(IdeaCategory::class, 'board_id');
    }

    /**
     * Get the ideas that belong to this board.
     *
     * @return HasMany<Idea, $this>
     */
    public function ideas(): HasMany
    {
        return $this->hasMany(Idea::class, 'board_id');
    }

    /**
     * Get the comments left on this board's ideas.
     *
     * @return HasManyThrough<IdeaComment, Idea, $this>
     */
    public function comments(): HasManyThrough
    {
        return $this->hasManyThrough(IdeaComment::class, Idea::class, 'board_id', 'idea_id');
    }

    /**
     * Get the per-role access rules for this board (Phase 2).
     *
     * @return HasMany<IdeaBoardRoleAccess, $this>
     */
    public function roleAccess(): HasMany
    {
        return $this->hasMany(IdeaBoardRoleAccess::class, 'board_id');
    }

    /**
     * Get the per-user access rules for this board (Phase 2).
     *
     * @return HasMany<IdeaBoardUserAccess, $this>
     */
    public function userAccess(): HasMany
    {
        return $this->hasMany(IdeaBoardUserAccess::class, 'board_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
