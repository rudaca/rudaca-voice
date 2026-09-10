<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Storage Disk
    |--------------------------------------------------------------------------
    |
    | Idea attachments are stored on this disk. Defaults to the DigitalOcean
    | Spaces disk (private visibility — files are only ever served through
    | the application's authenticated download route, never linked to
    | directly).
    |
    */

    'disk' => env('IDEA_ATTACHMENTS_DISK', 'do_spaces'),

    /*
    |--------------------------------------------------------------------------
    | Maximum File Size
    |--------------------------------------------------------------------------
    |
    | Maximum size, in kilobytes, allowed for a single attachment.
    |
    */

    'max_file_size_kb' => env('IDEA_ATTACHMENTS_MAX_FILE_SIZE_KB', 51200), // 50MB

    /*
    |--------------------------------------------------------------------------
    | Maximum Attachments Per Idea
    |--------------------------------------------------------------------------
    */

    'max_attachments_per_idea' => env('IDEA_ATTACHMENTS_MAX_PER_IDEA', 10),

    /*
    |--------------------------------------------------------------------------
    | Allowed Extensions
    |--------------------------------------------------------------------------
    |
    | Used for both `mimes:` validation and choosing a display icon.
    |
    */

    'allowed_extensions' => [
        'jpg', 'jpeg', 'png', 'webp',
        'pdf',
        'doc', 'docx',
        'xls', 'xlsx',
        'ppt', 'pptx',
    ],

];
