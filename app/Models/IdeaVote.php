<?php

namespace App\Models;

use App\Enums\IdeaStatus;
use Database\Factories\IdeaVoteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property int $idea_id
 * @property int $user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Idea $idea
 * @property-read User $user
 */
#[Fillable(['idea_id', 'user_id'])]
class IdeaVote extends Model
{
    /** @use HasFactory<IdeaVoteFactory> */
    use HasFactory;

    /**
     * Get the idea that was voted on.
     *
     * @return BelongsTo<Idea, $this>
     */
    public function idea(): BelongsTo
    {
        return $this->belongsTo(Idea::class);
    }

    /**
     * Get the user who cast the vote.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The given user's votes on the given board that are still "active" —
     * attached to an idea whose status hasn't reached a terminal state.
     * Used to enforce a team's one-active-vote-per-board limit.
     *
     * @return Collection<int, self>
     */
    public static function activeVotesForUserOnBoard(int $userId, int $boardId): Collection
    {
        return static::where('user_id', $userId)
            ->whereHas('idea', fn ($query) => $query
                ->where('board_id', $boardId)
                ->whereIn('status', IdeaStatus::activeValues()))
            ->with('idea:id,title,slug,status')
            ->get();
    }

    /**
     * Count of the given user's active votes on the given board.
     */
    public static function activeVoteCountForUserOnBoard(int $userId, int $boardId): int
    {
        return static::where('user_id', $userId)
            ->whereHas('idea', fn ($query) => $query
                ->where('board_id', $boardId)
                ->whereIn('status', IdeaStatus::activeValues()))
            ->count();
    }

    /**
     * Atomically move a vote from one idea to another: removes the existing
     * vote and creates a new one for the target idea, in a single transaction
     * to prevent a race from producing two active votes on the same board.
     */
    public static function moveVote(self $existingVote, Idea $targetIdea, int $userId): self
    {
        return DB::transaction(function () use ($existingVote, $targetIdea, $userId) {
            $existingVote->delete();

            return static::create([
                'idea_id' => $targetIdea->id,
                'user_id' => $userId,
            ]);
        });
    }
}
