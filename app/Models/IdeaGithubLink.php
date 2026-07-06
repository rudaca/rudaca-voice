<?php

namespace App\Models;

use Database\Factories\IdeaGithubLinkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Phase 2 / Planned model. Schema only — no GitHub sync logic yet.
 *
 * @property int $id
 * @property int $idea_id
 * @property string $github_owner
 * @property string $github_repo
 * @property int $github_issue_number
 * @property string $github_issue_url
 * @property string $github_issue_state
 * @property string $github_issue_status
 * @property Carbon|null $last_synced_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Idea $idea
 */
#[Fillable([
    'idea_id',
    'github_owner',
    'github_repo',
    'github_issue_number',
    'github_issue_url',
    'github_issue_state',
    'github_issue_status',
    'last_synced_at',
])]
class IdeaGithubLink extends Model
{
    /** @use HasFactory<IdeaGithubLinkFactory> */
    use HasFactory;

    /**
     * Get the idea the GitHub issue is linked to.
     *
     * @return BelongsTo<Idea, $this>
     */
    public function idea(): BelongsTo
    {
        return $this->belongsTo(Idea::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'github_issue_number' => 'integer',
            'last_synced_at' => 'datetime',
        ];
    }
}
