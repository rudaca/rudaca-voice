<?php

namespace App\Models;

use App\Enums\TeamRole;
use Database\Factories\IdeaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property int|null $board_group_id
 * @property int $board_id
 * @property int|null $category_id
 * @property int $submitted_by_user_id
 * @property string $title
 * @property string $slug
 * @property string $description
 * @property string $status
 * @property string $priority
 * @property string $impact
 * @property string $effort
 * @property bool $is_anonymous
 * @property bool $is_private
 * @property int|null $duplicate_of_idea_id
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read IdeaBoardGroup|null $boardGroup
 * @property-read IdeaBoard $board
 * @property-read IdeaCategory|null $category
 * @property-read User $submittedBy
 * @property-read Idea|null $duplicateOf
 * @property-read Collection<int, Idea> $duplicates
 * @property-read Collection<int, IdeaVote> $votes
 * @property-read Collection<int, IdeaComment> $comments
 * @property-read Collection<int, IdeaStatusHistory> $statusHistory
 * @property-read Collection<int, IdeaGithubLink> $githubLinks
 */
#[Fillable([
    'team_id',
    'board_group_id',
    'board_id',
    'category_id',
    'submitted_by_user_id',
    'title',
    'slug',
    'description',
    'status',
    'priority',
    'impact',
    'effort',
    'is_anonymous',
    'is_private',
    'duplicate_of_idea_id',
])]
class Idea extends Model
{
    /** @use HasFactory<IdeaFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Get the team that owns the idea.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the board group the idea belongs to.
     *
     * @return BelongsTo<IdeaBoardGroup, $this>
     */
    public function boardGroup(): BelongsTo
    {
        return $this->belongsTo(IdeaBoardGroup::class, 'board_group_id');
    }

    /**
     * Get the board the idea belongs to.
     *
     * @return BelongsTo<IdeaBoard, $this>
     */
    public function board(): BelongsTo
    {
        return $this->belongsTo(IdeaBoard::class, 'board_id');
    }

    /**
     * Get the category the idea belongs to.
     *
     * @return BelongsTo<IdeaCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(IdeaCategory::class, 'category_id');
    }

    /**
     * Get the user who submitted the idea.
     *
     * @return BelongsTo<User, $this>
     */
    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    /**
     * Get the idea this one is a duplicate of.
     *
     * @return BelongsTo<Idea, $this>
     */
    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(Idea::class, 'duplicate_of_idea_id');
    }

    /**
     * Get the ideas that have been marked as duplicates of this idea.
     *
     * @return HasMany<Idea, $this>
     */
    public function duplicates(): HasMany
    {
        return $this->hasMany(Idea::class, 'duplicate_of_idea_id');
    }

    /**
     * Get the votes for the idea.
     *
     * @return HasMany<IdeaVote, $this>
     */
    public function votes(): HasMany
    {
        return $this->hasMany(IdeaVote::class);
    }

    /**
     * Get the comments for the idea.
     *
     * @return HasMany<IdeaComment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(IdeaComment::class);
    }

    /**
     * Get the status change history for the idea.
     *
     * @return HasMany<IdeaStatusHistory, $this>
     */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(IdeaStatusHistory::class);
    }

    /**
     * Get the linked GitHub issues for the idea (Phase 2).
     *
     * @return HasMany<IdeaGithubLink, $this>
     */
    public function githubLinks(): HasMany
    {
        return $this->hasMany(IdeaGithubLink::class);
    }

    /**
     * Scope a query to ideas visible to a user holding the given team role:
     * private ideas are hidden from everyone except Manager+ and the
     * original submitter.
     *
     * @param  Builder<Idea>  $query
     * @return Builder<Idea>
     */
    public function scopeVisibleTo(Builder $query, ?TeamRole $role, int $userId): Builder
    {
        if ($role?->isAtLeast(TeamRole::Manager)) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($userId) {
            $query->where('is_private', false)
                ->orWhere('submitted_by_user_id', $userId);
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_anonymous' => 'boolean',
            'is_private' => 'boolean',
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
