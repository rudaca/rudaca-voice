<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

class IdeaAttachmentFile implements ValidationRule
{
    /**
     * Generic container types that Office files are frequently sniffed as
     * (OOXML as a zip archive, legacy .doc/.xls/.ppt as an OLE compound file).
     *
     * @var list<string>
     */
    private const GENERIC_CONTAINER_MIME_TYPES = [
        'application/zip',
        'application/x-zip-compressed',
        'application/octet-stream',
        'application/x-ole-storage',
        'application/x-cfb',
        'application/CDFV2',
        'application/vnd.ms-office',
    ];

    private const OFFICE_EXTENSIONS = ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $allowed = config('idea_attachments.allowed_extensions');

        if (! $value instanceof UploadedFile) {
            $fail(__('validation.mimes', ['values' => implode(', ', $allowed)]));

            return;
        }

        if (in_array($value->guessExtension(), $allowed, true)) {
            return;
        }

        $clientExtension = strtolower($value->getClientOriginalExtension());

        if (
            $value->getSize() > 0
            && in_array($clientExtension, self::OFFICE_EXTENSIONS, true)
            && in_array($clientExtension, $allowed, true)
            && in_array($value->getMimeType(), self::GENERIC_CONTAINER_MIME_TYPES, true)
        ) {
            return;
        }

        $fail(__('validation.mimes', ['values' => implode(', ', $allowed)]));
    }
}
