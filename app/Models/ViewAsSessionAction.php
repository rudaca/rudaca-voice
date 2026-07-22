<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $view_as_session_id
 * @property string $method
 * @property string $path
 * @property string|null $route_name
 * @property Carbon $performed_at
 * @property-read ViewAsSession $session
 */
#[Fillable(['view_as_session_id', 'method', 'path', 'route_name', 'performed_at'])]
class ViewAsSessionAction extends Model
{
    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Get the session this action belongs to.
     *
     * @return BelongsTo<ViewAsSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ViewAsSession::class, 'view_as_session_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'performed_at' => 'datetime',
        ];
    }
}
