<?php

namespace App\Models;

use App\Enums\TeamRole;
use App\Enums\ViewAsSessionEndReason;
use Database\Factories\ViewAsSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

/**
 * @property int $id
 * @property int $super_admin_id
 * @property int $target_user_id
 * @property int $team_id
 * @property TeamRole $role_viewed_as
 * @property Carbon $started_at
 * @property Carbon $last_activity_at
 * @property Carbon|null $ended_at
 * @property ViewAsSessionEndReason|null $ended_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $superAdmin
 * @property-read User $targetUser
 * @property-read Team $team
 * @property-read Collection<int, ViewAsSessionAction> $actions
 */
#[Fillable(['super_admin_id', 'target_user_id', 'team_id', 'role_viewed_as', 'started_at', 'last_activity_at', 'ended_at', 'ended_reason'])]
class ViewAsSession extends Model
{
    /** @use HasFactory<ViewAsSessionFactory> */
    use HasFactory;

    /**
     * Get the currently active View As session for this browser session, if any.
     */
    public static function current(): ?self
    {
        $id = Session::get('view_as_session_id');

        if (! $id) {
            return null;
        }

        return static::query()->whereNull('ended_at')->find($id);
    }

    /**
     * Get the Super Admin who initiated this session.
     *
     * @return BelongsTo<User, $this>
     */
    public function superAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'super_admin_id');
    }

    /**
     * Get the user being viewed as.
     *
     * @return BelongsTo<User, $this>
     */
    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    /**
     * Get the team this session is scoped to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the actions recorded during this session.
     *
     * @return HasMany<ViewAsSessionAction, $this>
     */
    public function actions(): HasMany
    {
        return $this->hasMany(ViewAsSessionAction::class);
    }

    /**
     * Determine if this session has been idle longer than the configured timeout.
     */
    public function isExpired(): bool
    {
        return $this->last_activity_at
            ->addMinutes(config('view-as.timeout_minutes'))
            ->isPast();
    }

    /**
     * Record a request made during this session and refresh its activity timestamp.
     */
    public function recordActivity(Request $request): void
    {
        $this->actions()->create([
            'method' => $request->method(),
            'path' => $request->path(),
            'route_name' => $request->route()?->getName(),
            'performed_at' => now(),
        ]);

        $this->update(['last_activity_at' => now()]);
    }

    /**
     * End this session, reverting authentication back to the Super Admin.
     */
    public function end(ViewAsSessionEndReason $reason): void
    {
        $this->update(['ended_at' => now(), 'ended_reason' => $reason]);

        Auth::loginUsingId($this->super_admin_id);

        Session::forget('view_as_session_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role_viewed_as' => TeamRole::class,
            'ended_reason' => ViewAsSessionEndReason::class,
            'started_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }
}
