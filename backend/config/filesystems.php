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

        /*
         * The private disk, and the one M5 will store legal documents on.
         *
         * `serve` is **false**, changed from the scaffold default at M5.0
         * (D-114). Left true, Laravel registers `GET /storage/{path}` and
         * `PUT /storage/{path}` straight into this directory. The GET requires a
         * signed URL, so it was never open — but a signed URL is a **transferable
         * bearer token that bypasses the authorization chain entirely**: no
         * Policy, no `EffectiveAccessResolver`, no Data Scope, and no distinction
         * between `documents.download` and `documents.sensitive.download`.
         *
         * For KTP, NPWP, Minuta Akta and Warkah that is exactly what CLAUDE.md
         * section 21 forbids — "authorization protected" and "unavailable through
         * predictable public URLs" — and section 54's "never expose private
         * document URLs".
         *
         * Turned off **before** any document exists to reach through it. A legal
         * document is only ever readable by streaming it from a controller that
         * has authorized the actor against the record first.
         */
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],

        /*
         * Local demo tooling only (App\Domains\Demo\DemoDataSeeder). Same shape
         * as `local` — private, unserved, never a public URL — but a different
         * root, so a demo run can never write into, read from, or collide with
         * a real document on `local`. Nothing outside demo tooling reads this
         * disk, and no real Document ever names it.
         */
        'local_demo' => [
            'driver' => 'local',
            'root' => storage_path('app/private_demo'),
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
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
