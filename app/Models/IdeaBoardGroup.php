<?php

namespace App\Models;

use Database\Factories\IdeaBoardGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property int $sort_order
 * @property bool $is_active
 * @property int $created_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read User $createdBy
 * @property-read Collection<int, IdeaBoard> $boards
 * @property-read Collection<int, Idea> $ideas
 */
#[Fillable(['team_id', 'name', 'slug', 'description', 'sort_order', 'is_active', 'created_by_user_id'])]
class IdeaBoardGroup extends Model
{
    /** @use HasFactory<IdeaBoardGroupFactory> */
    use HasFactory;

    /**
     * Get the team that owns the board group.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the user who created the board group.
     *
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Get the boards that belong to this group.
     *
     * @return HasMany<IdeaBoard, $this>
     */
    public function boards(): HasMany
    {
        return $this->hasMany(IdeaBoard::class, 'board_group_id');
    }

    /**
     * Get the ideas that belong to this group.
     *
     * @return HasMany<Idea, $this>
     */
    public function ideas(): HasMany
    {
        return $this->hasMany(Idea::class, 'board_group_id');
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
