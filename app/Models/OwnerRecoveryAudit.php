<?php

namespace App\Models;

use App\Enums\OwnerRecoveryAuditAction;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An append-only record of an event in an organization's owner-recovery flow.
 *
 * `changed_fields` holds contextual notes only (e.g. the requesting IP) —
 * the emailed code and token never reach this table, not even masked.
 *
 * @property int $id
 * @property int $team_id
 * @property int|null $owner_recovery_token_id
 * @property int|null $user_id
 * @property OwnerRecoveryAuditAction $action
 * @property array<string, mixed>|null $changed_fields
 * @property Carbon|null $created_at
 * @property-read Team $team
 * @property-read OwnerRecoveryToken|null $token
 * @property-read User|null $user
 */
#[Fillable(['team_id', 'owner_recovery_token_id', 'user_id', 'action', 'changed_fields'])]
class OwnerRecoveryAudit extends Model
{
    /**
     * The name of the "updated at" column.
     *
     * This is an append-only log with only a created_at column.
     *
     * @var string|null
     */
    public const UPDATED_AT = null;

    /**
     * Get the organization the event occurred in.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the recovery token the event describes, if it still exists.
     *
     * @return BelongsTo<OwnerRecoveryToken, $this>
     */
    public function token(): BelongsTo
    {
        return $this->belongsTo(OwnerRecoveryToken::class, 'owner_recovery_token_id');
    }

    /**
     * Get the owner the event was for, if they still exist.
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
            'action' => OwnerRecoveryAuditAction::class,
            'changed_fields' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
