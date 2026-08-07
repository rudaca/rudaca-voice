<?php

namespace App\Models;

use Database\Factories\IdeaOfficialResponseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $idea_id
 * @property int $responded_by_user_id
 * @property string $body
 * @property Carbon $published_at
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Idea $idea
 * @property-read User $respondedBy
 */
#[Fillable(['idea_id', 'responded_by_user_id', 'body', 'published_at'])]
class IdeaOfficialResponse extends Model
{
    /** @use HasFactory<IdeaOfficialResponseFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Get the idea this official response belongs to.
     *
     * @return BelongsTo<Idea, $this>
     */
    public function idea(): BelongsTo
    {
        return $this->belongsTo(Idea::class);
    }

    /**
     * Get the user who authored this official response.
     *
     * @return BelongsTo<User, $this>
     */
    public function respondedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by_user_id');
    }

    /**
     * Whether this response has been edited since it was first published.
     */
    public function wasEdited(): bool
    {
        return $this->updated_at !== null
            && $this->published_at !== null
            && $this->updated_at->gt($this->published_at);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }
}
