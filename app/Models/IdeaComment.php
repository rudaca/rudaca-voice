<?php

namespace App\Models;

use Database\Factories\IdeaCommentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $idea_id
 * @property int $user_id
 * @property string $body
 * @property bool $is_internal
 * @property Carbon|null $hidden_at
 * @property int|null $hidden_by_user_id
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Idea $idea
 * @property-read User $user
 * @property-read User|null $hiddenBy
 */
#[Fillable(['idea_id', 'user_id', 'body', 'is_internal'])]
class IdeaComment extends Model
{
    /** @use HasFactory<IdeaCommentFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Get the idea the comment belongs to.
     *
     * @return BelongsTo<Idea, $this>
     */
    public function idea(): BelongsTo
    {
        return $this->belongsTo(Idea::class);
    }

    /**
     * Get the user who wrote the comment.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the moderator who hid this comment, if any.
     *
     * @return BelongsTo<User, $this>
     */
    public function hiddenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hidden_by_user_id');
    }

    /**
     * Whether this comment is currently hidden from the idea's comment thread.
     */
    public function isHidden(): bool
    {
        return $this->hidden_at !== null;
    }

    /**
     * Hide this comment from the idea's comment thread (moderation).
     */
    public function hide(int $moderatorUserId): void
    {
        $this->forceFill([
            'hidden_at' => now(),
            'hidden_by_user_id' => $moderatorUserId,
        ])->save();
    }

    /**
     * Restore a previously hidden comment to the idea's comment thread.
     */
    public function unhide(): void
    {
        $this->forceFill([
            'hidden_at' => null,
            'hidden_by_user_id' => null,
        ])->save();
    }

    /**
     * Scope a query to comments not currently hidden by moderation.
     *
     * @param  Builder<IdeaComment>  $query
     * @return Builder<IdeaComment>
     */
    public function scopeNotHidden($query)
    {
        return $query->whereNull('hidden_at');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_internal' => 'boolean',
            'hidden_at' => 'datetime',
        ];
    }
}
