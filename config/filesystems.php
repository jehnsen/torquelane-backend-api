<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            // No unauthenticated /storage/{path} route: files are served through
            // tenant-scoped API routes (R5), never straight off the disk.
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        // Document files (App\Documents\DocumentStorage). Private, always:
        // downloads are short-lived URLs issued after a policy check.
        // DOCUMENTS_DISK=local (default) or s3 for any S3-compatible store
        // (AWS S3, Cloudflare R2, Supabase Storage, DigitalOcean Spaces).
        'documents' => env('DOCUMENTS_DISK', 'local') === 's3' ? [
            'driver' => 's3',
            'key' => env('DOCUMENTS_S3_KEY'),
            'secret' => env('DOCUMENTS_S3_SECRET'),
            'region' => env('DOCUMENTS_S3_REGION', 'auto'),
            'bucket' => env('DOCUMENTS_S3_BUCKET'),
            'endpoint' => env('DOCUMENTS_S3_ENDPOINT'),
            'use_path_style_endpoint' => env('DOCUMENTS_S3_PATH_STYLE', true),
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
        ] : [
            'driver' => 'local',
            'root' => storage_path('app/private/documents'),
            'serve' => false,
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [],

];
