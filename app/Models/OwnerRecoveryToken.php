<?php

namespace App\Models;

use Database\Factories\OwnerRecoveryTokenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A single-use, time-limited token that lets an organization owner regain
 * access when they've been locked out of both password and Microsoft
 * sign-in.
 *
 * `code` is the unguessable route key (mirrors `TeamInvitation`); `code_hash`
 * is the hash of a separate, short one-time code emailed alongside the link
 * — the second factor, checked the same way a password would be and never
 * stored or logged in plaintext.
 *
 * @property int $id
 * @property int $team_id
 * @property int $user_id
 * @property string $code
 * @property string $code_hash
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 * @property int $attempts
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read User $user
 */
#[Fillable(['team_id', 'user_id', 'code', 'code_hash', 'expires_at', 'used_at', 'attempts'])]
class OwnerRecoveryToken extends Model
{
    /** @use HasFactory<OwnerRecoveryTokenFactory> */
    use HasFactory;

    /**
     * The maximum number of wrong-code attempts before a token is invalidated.
     */
    public const MAX_ATTEMPTS = 5;

    /**
     * Get the organization this token grants recovery access to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the owner this token was issued for.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Determine if the token has expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Determine if the token has already been used.
     */
    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }

    /**
     * Determine if the token has exceeded its allowed wrong-code attempts.
     */
    public function hasExceededAttempts(): bool
    {
        return $this->attempts >= self::MAX_ATTEMPTS;
    }

    /**
     * Determine if the token can still be redeemed.
     */
    public function isUsable(): bool
    {
        return ! $this->isExpired() && ! $this->isUsed() && ! $this->hasExceededAttempts();
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'code';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }
}
