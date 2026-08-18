<?php

namespace App\Models;

use App\Enums\IdentityProvider;
use App\Enums\IdentityProviderAuditAction;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An append-only record of a change to an organization's identity provider
 * configuration.
 *
 * `changed_fields` holds field *names* only. Secret values never reach this
 * table, not even masked or truncated.
 *
 * `error_context` holds short diagnostic detail for a failure — e.g. the
 * error code and description a provider returned — never a secret, token,
 * or full request/response body.
 *
 * @property int $id
 * @property int $team_id
 * @property int|null $identity_provider_id
 * @property IdentityProvider $provider
 * @property IdentityProviderAuditAction $action
 * @property array<int, string>|null $changed_fields
 * @property array<string, string>|null $error_context
 * @property int|null $performed_by_user_id
 * @property Carbon|null $created_at
 * @property-read Team $team
 * @property-read TeamIdentityProvider|null $identityProvider
 * @property-read User|null $performedBy
 */
#[Fillable([
    'team_id',
    'identity_provider_id',
    'provider',
    'action',
    'changed_fields',
    'error_context',
    'performed_by_user_id',
])]
class TeamIdentityProviderAudit extends Model
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
     * Get the organization the change was made in.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the configuration the change was made to, if it still exists.
     *
     * @return BelongsTo<TeamIdentityProvider, $this>
     */
    public function identityProvider(): BelongsTo
    {
        return $this->belongsTo(TeamIdentityProvider::class, 'identity_provider_id');
    }

    /**
     * Get the user who made the change.
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
            'action' => IdentityProviderAuditAction::class,
            'changed_fields' => 'array',
            'error_context' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
