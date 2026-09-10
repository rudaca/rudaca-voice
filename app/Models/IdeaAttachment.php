<?php

namespace App\Models;

use Database\Factories\IdeaAttachmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $idea_id
 * @property int $uploaded_by_user_id
 * @property string $disk
 * @property string $path
 * @property string $original_filename
 * @property string $extension
 * @property string $mime_type
 * @property int $size_bytes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Idea $idea
 * @property-read User $uploadedBy
 */
#[Fillable([
    'idea_id',
    'uploaded_by_user_id',
    'disk',
    'path',
    'original_filename',
    'extension',
    'mime_type',
    'size_bytes',
])]
class IdeaAttachment extends Model
{
    /** @use HasFactory<IdeaAttachmentFactory> */
    use HasFactory;

    /**
     * Extensions displayed with an image preview rather than a file-type icon.
     */
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    /**
     * Get the idea this attachment belongs to.
     *
     * @return BelongsTo<Idea, $this>
     */
    public function idea(): BelongsTo
    {
        return $this->belongsTo(Idea::class);
    }

    /**
     * Get the user who uploaded this attachment.
     *
     * @return BelongsTo<User, $this>
     */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    /**
     * Whether this attachment is an image that can be shown as a thumbnail.
     */
    public function isImage(): bool
    {
        return in_array($this->extension, self::IMAGE_EXTENSIONS, true);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }
}
