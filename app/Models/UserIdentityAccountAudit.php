<?php

namespace App\Models;

use App\Enums\IdentityProvider;
use App\Enums\UserIdentityAccountAuditAction;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An append-only record of a link or unlink event for a user's external
 * identity.
 *
 * `changed_fields` holds contextual notes only (e.g. "forced") — claims,
 * tokens, and secrets never reach this table, not even masked or truncated.
 *
 * @property int $id
 * @property int $team_id
 * @property int|null $user_identity_account_id
 * @property int|null $user_id
 * @property IdentityProvider $provider
 * @property UserIdentityAccountAuditAction $action
 * @property array<int, string>|null $changed_fields
 * @property int|null $performed_by_user_id
 * @property Carbon|null $created_at
 * @property-read Team $team
 * @property-read UserIdentityAccount|null $userIdentityAccount
 * @property-read User|null $user
 * @property-read User|null $performedBy
 */
#[Fillable([
    'team_id',
    'user_identity_account_id',
    'user_id',
    'provider',
    'action',
    'changed_fields',
    'performed_by_user_id',
])]
class UserIdentityAccountAudit extends Model
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
     * Get the identity link the event describes, if it still exists.
     *
     * @return BelongsTo<UserIdentityAccount, $this>
     */
    public function userIdentityAccount(): BelongsTo
    {
        return $this->belongsTo(UserIdentityAccount::class);
    }

    /**
     * Get the user the identity was linked to, if they still exist.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the user who performed the link/unlink action.
     *
     * @return BelongsTo<User, $this>
     */
    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by_user_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => IdentityProvider::class,
            'action' => UserIdentityAccountAuditAction::class,
            'changed_fields' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
