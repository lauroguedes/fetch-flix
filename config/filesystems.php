<?php

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
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim((string) env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
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

        /*
         * Media assets: downloaded video and audio files served to clients
         * through our own signed URLs.
         *
         * `local` in development, `s3` in production. Cloudflare R2 is
         * S3-compatible and uses the same driver with `MEDIA_S3_ENDPOINT`
         * pointed at the account endpoint.
         *
         * Deliberately no `visibility` key: setting one makes Flysystem send an
         * ACL header on every write, which R2 rejects. Objects are private by
         * bucket policy and reached only through short-lived pre-signed URLs.
         *
         * `throw` is on. A storage failure that returns false instead of
         * raising would be recorded as a successful download of a file that is
         * not there, and only surface later as a broken stream.
         */
        'media' => [
            'driver' => env('MEDIA_DISK_DRIVER', 'local'),
            'root' => storage_path('app/media'),
            'key' => env('MEDIA_S3_KEY'),
            'secret' => env('MEDIA_S3_SECRET'),
            'region' => env('MEDIA_S3_REGION', 'auto'),
            'bucket' => env('MEDIA_S3_BUCKET'),
            'endpoint' => env('MEDIA_S3_ENDPOINT'),
            'use_path_style_endpoint' => env('MEDIA_S3_PATH_STYLE', false),
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

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
