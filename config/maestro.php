<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Table Names
    |--------------------------------------------------------------------------
    |
    | You may customize the table names used by Maestro. This is useful if you
    | need to avoid naming conflicts with existing tables in your database.
    |
    */
    'tables' => [
        'workflows' => 'maestro_workflows',
        'step_runs' => 'maestro_step_runs',
        'job_ledger' => 'maestro_job_ledger',
        'step_outputs' => 'maestro_step_outputs',
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    |
    | Configure which queue connection and queue name Maestro should use for
    | dispatching workflow jobs. Set to null to use Laravel's default queue.
    |
    */
    'queue' => [
        'connection' => env('MAESTRO_QUEUE_CONNECTION'),
        'name' => env('MAESTRO_QUEUE_NAME', 'maestro'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Zombie Detection
    |--------------------------------------------------------------------------
    |
    | Configure how Maestro detects and handles zombie jobs (jobs that are
    | marked as running but whose worker has died).
    |
    */
    'zombie_detection' => [
        'enabled' => env('MAESTRO_ZOMBIE_DETECTION', true),
        'threshold_minutes' => env('MAESTRO_ZOMBIE_THRESHOLD', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Locking
    |--------------------------------------------------------------------------
    |
    | Configure the locking mechanism used to prevent concurrent workflow
    | advancement. The timeout specifies how long a lock can be held.
    |
    */
    'locking' => [
        'driver' => env('MAESTRO_LOCK_DRIVER', 'database'),
        'timeout_seconds' => env('MAESTRO_LOCK_TIMEOUT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retry Defaults
    |--------------------------------------------------------------------------
    |
    | Default retry configuration for steps and jobs. These can be overridden
    | at the workflow definition level.
    |
    */
    'retry' => [
        'max_attempts' => env('MAESTRO_MAX_RETRY_ATTEMPTS', 3),
        'backoff_seconds' => env('MAESTRO_RETRY_BACKOFF', 60),
        'backoff_multiplier' => env('MAESTRO_RETRY_MULTIPLIER', 2.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cleanup & Archival
    |--------------------------------------------------------------------------
    |
    | Configure automatic cleanup of completed workflows. Set days to 0 to
    | disable automatic cleanup.
    |
    */
    'cleanup' => [
        'enabled' => env('MAESTRO_CLEANUP_ENABLED', false),
        'keep_completed_days' => env('MAESTRO_KEEP_COMPLETED_DAYS', 30),
        'keep_failed_days' => env('MAESTRO_KEEP_FAILED_DAYS', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | API Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the Maestro API routes and authentication. Set enabled to false
    | to disable the API routes entirely. You can customize the middleware to
    | use your own authentication (e.g., Sanctum) instead of the built-in
    | HMAC signature validation.
    |
    */
    'api' => [
        'enabled' => env('MAESTRO_API_ENABLED', true),
        'prefix' => env('MAESTRO_API_PREFIX', 'api/maestro'),
        'middleware' => [
            'api',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Trigger Authentication
    |--------------------------------------------------------------------------
    |
    | Configure how external triggers are authenticated. By default, triggers
    | use HMAC signature validation. Set driver to 'null' to disable trigger
    | authentication (useful when using external middleware like Sanctum).
    |
    | Available drivers: 'hmac', 'null'
    |
    */
    'trigger_auth' => [
        'driver' => env('MAESTRO_TRIGGER_AUTH_DRIVER', 'null'),
        'hmac' => [
            'secret' => env('MAESTRO_TRIGGER_SECRET'),
            'max_timestamp_drift_seconds' => env('MAESTRO_TRIGGER_MAX_DRIFT', 300),
        ],
    ],
];
