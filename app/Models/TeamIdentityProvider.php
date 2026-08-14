<?php

namespace App\Models;

use App\Enums\IdentityProvider;
use App\Enums\SsoEnforcementScope;
use App\Enums\TeamRole;
use Database\Factories\TeamIdentityProviderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An organization's configuration for one external identity provider.
 *
 * Organizations with no row here authenticate purely with email and password;
 * nothing about this model changes that until `enabled` is turned on.
 *
 * @property int $id
 * @property int $team_id
 * @property IdentityProvider $provider
 * @property string|null $tenant_id
 * @property string|null $client_id
 * @property string|null $client_secret_encrypted
 * @property bool $enabled
 * @property bool $enforce_sso
 * @property SsoEnforcementScope $enforce_sso_scope
 * @property bool $auto_provision_users
 * @property TeamRole|null $default_role
 * @property array<int, string> $allowed_domains
 * @property int|null $configured_by
 * @property Carbon|null $configured_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read User|null $configuredBy
 * @property-read Collection<int, TeamIdentityProviderAudit> $audits
 */
#[Fillable([
    'team_id',
    'provider',
    'tenant_id',
    'client_id',
    'client_secret_encrypted',
    'enabled',
    'enforce_sso',
    'enforce_sso_scope',
    'auto_provision_users',
    'default_role',
    'allowed_domains',
    'configured_by',
    'configured_at',
])]
#[Hidden(['client_secret_encrypted'])]
class TeamIdentityProvider extends Model
{
    /** @use HasFactory<TeamIdentityProviderFactory> */
    use HasFactory;

    /**
     * The tenant identifiers Microsoft accepts in place of a directory GUID.
     *
     * @var array<int, string>
     */
    public const MULTI_TENANT_IDENTIFIERS = ['common', 'organizations', 'consumers'];

    /**
     * The model's default attribute values.
     *
     * `allowed_domains` defaults here rather than in the schema because MySQL
     * refuses a literal DEFAULT on a JSON column.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'enabled' => false,
        'enforce_sso' => false,
        'enforce_sso_scope' => 'global',
        'auto_provision_users' => false,
        'allowed_domains' => '[]',
    ];

    /**
     * Get the organization this configuration belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the user who last configured this provider.
     *
     * @return BelongsTo<User, $this>
     */
    public function configuredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'configured_by');
    }

    /**
     * Get the audit trail for this configuration.
     *
     * @return HasMany<TeamIdentityProviderAudit, $this>
     */
    public function audits(): HasMany
    {
        return $this->hasMany(TeamIdentityProviderAudit::class, 'identity_provider_id');
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
     * Whether a client secret has been stored for this provider.
     *
     * Used by the configuration panel to decide between a masked placeholder and
     * an empty secret field, without ever reading the secret itself.
     */
    public function hasClientSecret(): bool
    {
        return filled($this->getRawOriginal('client_secret_encrypted'));
    }

    /**
     * Whether the provider has everything it needs to be turned on.
     */
    public function isConfigurable(): bool
    {
        return filled($this->client_id)
            && $this->hasClientSecret()
            && (! $this->provider->requiresTenantId() || filled($this->tenant_id));
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
            'client_secret_encrypted' => 'encrypted',
            'enabled' => 'boolean',
            'enforce_sso' => 'boolean',
            'enforce_sso_scope' => SsoEnforcementScope::class,
            'auto_provision_users' => 'boolean',
            'default_role' => TeamRole::class,
            'allowed_domains' => 'array',
            'configured_at' => 'datetime',
        ];
    }
}
