<?php

namespace App\Models;

use App\Enums\IdentityProvider;
use Database\Factories\UserIdentityAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A stable link between a Rudaca Voice user and one external (Microsoft
 * Entra) identity, scoped to a single organization.
 *
 * Authenticating via `provider_tenant_id` + `provider_subject_id` (rather
 * than email) is what survives the linked account's email changing on the
 * provider side. The unique index on those two columns plus `provider` is
 * global, not per-organization, which is what makes it structurally
 * impossible for the same external identity to be linked to two users or
 * reused across organizations.
 *
 * @property int $id
 * @property int $user_id
 * @property int $team_id
 * @property IdentityProvider $provider
 * @property string $provider_tenant_id
 * @property string $provider_subject_id
 * @property string $email_at_link_time
 * @property string|null $display_name
 * @property Carbon|null $last_login_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Team $team
 * @property-read Collection<int, UserIdentityAccountAudit> $audits
 */
#[Fillable([
    'user_id',
    'team_id',
    'provider',
    'provider_tenant_id',
    'provider_subject_id',
    'email_at_link_time',
    'display_name',
    'last_login_at',
])]
class UserIdentityAccount extends Model
{
    /** @use HasFactory<UserIdentityAccountFactory> */
    use HasFactory;

    /**
     * Get the user this identity is linked to.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the organization this link belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the audit trail for this link.
     *
     * @return HasMany<UserIdentityAccountAudit, $this>
     */
    public function audits(): HasMany
    {
        return $this->hasMany(UserIdentityAccountAudit::class);
    }

    /**
     * Scope the query to a single organization.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeForTeam(Builder $query, Team|int $team): void
    {
        $query->where('team_id', $team instanceof Team ? $team->id : $team);
    }

    /**
     * Scope the query to a single provider.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeProvider(Builder $query, IdentityProvider|string $provider): void
    {
        $query->where('provider', $provider instanceof IdentityProvider ? $provider->value : $provider);
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
            'last_login_at' => 'datetime',
        ];
    }
}
