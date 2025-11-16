<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Maximum File Size
    |--------------------------------------------------------------------------
    |
    | Maximum allowed file size for attachments in bytes.
    | Default: 10 MB (10 * 1024 * 1024)
    |
    */
    'max_file_size' => env('ATTACHMENT_MAX_SIZE', 10 * 1024 * 1024),

    /*
    |--------------------------------------------------------------------------
    | Allowed MIME Types
    |--------------------------------------------------------------------------
    |
    | List of permitted MIME types for attachments.
    | Empty array allows all types.
    |
    */
    'allowed_mime_types' => [
        // Images
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',

        // Documents
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',

        // Text
        'text/plain',
        'text/csv',
        'text/html',
        'text/markdown',

        // Archives
        'application/zip',
        'application/x-7z-compressed',
        'application/x-rar-compressed',

        // Other
        'application/json',
        'application/xml',
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage Disk
    |--------------------------------------------------------------------------
    |
    | Disk to use for storing encrypted attachment files.
    | Must be configured in config/filesystems.php
    |
    */
    'storage_disk' => env('ATTACHMENT_STORAGE_DISK', 'local'),
];
