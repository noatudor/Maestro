# Configuration Reference

This document covers all configuration options available in `config/maestro.php`.

## Table Names

Customize the database table names used by Maestro:

```php
'tables' => [
    'workflows' => 'maestro_workflows',
    'step_runs' => 'maestro_step_runs',
    'jobs' => 'maestro_jobs',
    'outputs' => 'maestro_outputs',
],
```

## Queue Configuration

Configure how workflow jobs are queued:

```php
'queue' => [
    // Default queue connection
    'connection' => env('MAESTRO_QUEUE_CONNECTION', config('queue.default')),

    // Default queue name for workflow jobs
    'name' => env('MAESTRO_QUEUE_NAME', 'default'),

    // Retry attempts for failed jobs
    'tries' => 3,

    // Timeout in seconds for job execution
    'timeout' => 120,
],
```

### Per-Step Queue Configuration

Override queue settings for individual steps:

```php
->singleJob('heavy-processing', fn ($step) => $step
    ->job(HeavyJob::class)
    ->onQueue('heavy-tasks')
    ->onConnection('redis')
    ->delay(30)) // Delay in seconds
```

## Locking Configuration

Configure concurrent access control:

```php
'locking' => [
    // Lock timeout in seconds
    'timeout' => env('MAESTRO_LOCK_TIMEOUT', 5),

    // Lock driver: 'database' or 'redis'
    'driver' => env('MAESTRO_LOCK_DRIVER', 'database'),

    // Redis prefix for locks
    'redis_prefix' => 'maestro:lock:',
],
```

## Zombie Detection

Configure stale job detection:

```php
'zombie' => [
    // Jobs running longer than this are considered zombies (seconds)
    'threshold' => env('MAESTRO_ZOMBIE_THRESHOLD', 3600),

    // How often to check for zombie jobs (seconds)
    'check_interval' => 300,

    // Automatically mark zombie jobs as failed
    'auto_cleanup' => true,
],
```

## Retry Configuration

Default retry settings for failed steps:

```php
'retry' => [
    // Maximum retry attempts
    'max_attempts' => 3,

    // Base delay between retries (seconds)
    'delay' => 60,

    // Multiplier for exponential backoff
    'backoff_multiplier' => 2.0,

    // Maximum delay between retries (seconds)
    'max_delay' => 3600,
],
```

### Per-Step Retry Configuration

```php
->singleJob('flaky-api-call', fn ($step) => $step
    ->job(CallExternalApiJob::class)
    ->retryStep()
    ->retry(
        maxAttempts: 5,
        delaySeconds: 30,
        backoffMultiplier: 3.0,
        maxDelaySeconds: 600
    ))
```

## Cleanup and Archival

Configure automatic cleanup of completed workflows:

```php
'cleanup' => [
    // Enable automatic archival
    'enabled' => false,

    // Archive workflows after this many days
    'archive_after_days' => 30,

    // Delete archived workflows after this many days
    'delete_after_days' => 90,

    // Batch size for cleanup operations
    'batch_size' => 1000,
],
```

## API Configuration

Configure the HTTP API for external triggers:

```php
'api' => [
    // Enable HTTP API routes
    'enabled' => true,

    // API route prefix
    'prefix' => 'api/maestro',

    // Middleware for API routes
    'middleware' => ['api', 'auth:sanctum'],

    // Rate limiting
    'rate_limit' => 60, // requests per minute
],
```

## Trigger Authentication

Configure external trigger authentication:

```php
'triggers' => [
    // Default authenticator class
    'authenticator' => \Maestro\Workflow\Http\Authentication\HmacTriggerAuthenticator::class,

    // HMAC secret for signature verification
    'hmac_secret' => env('MAESTRO_TRIGGER_SECRET'),

    // Allowed trigger types
    'allowed_types' => ['webhook', 'approval', 'timer'],
],
```

## Events

Configure event dispatching:

```php
'events' => [
    // Dispatch events for workflow state changes
    'workflow_events' => true,

    // Dispatch events for step state changes
    'step_events' => true,

    // Dispatch events for job state changes
    'job_events' => true,
],
```

## Complete Example

```php
<?php

return [
    'tables' => [
        'workflows' => 'maestro_workflows',
        'step_runs' => 'maestro_step_runs',
        'jobs' => 'maestro_jobs',
        'outputs' => 'maestro_outputs',
    ],

    'queue' => [
        'connection' => env('MAESTRO_QUEUE_CONNECTION', 'redis'),
        'name' => env('MAESTRO_QUEUE_NAME', 'workflows'),
        'tries' => 3,
        'timeout' => 120,
    ],

    'locking' => [
        'timeout' => 5,
        'driver' => 'database',
    ],

    'zombie' => [
        'threshold' => 3600,
        'check_interval' => 300,
        'auto_cleanup' => true,
    ],

    'retry' => [
        'max_attempts' => 3,
        'delay' => 60,
        'backoff_multiplier' => 2.0,
        'max_delay' => 3600,
    ],

    'cleanup' => [
        'enabled' => false,
        'archive_after_days' => 30,
        'delete_after_days' => 90,
        'batch_size' => 1000,
    ],

    'api' => [
        'enabled' => true,
        'prefix' => 'api/maestro',
        'middleware' => ['api', 'auth:sanctum'],
        'rate_limit' => 60,
    ],

    'triggers' => [
        'authenticator' => \Maestro\Workflow\Http\Authentication\HmacTriggerAuthenticator::class,
        'hmac_secret' => env('MAESTRO_TRIGGER_SECRET'),
    ],

    'events' => [
        'workflow_events' => true,
        'step_events' => true,
        'job_events' => true,
    ],
];
```

## Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `MAESTRO_QUEUE_CONNECTION` | `redis` | Queue connection for workflow jobs |
| `MAESTRO_QUEUE_NAME` | `workflows` | Queue name for workflow jobs |
| `MAESTRO_LOCK_TIMEOUT` | `5` | Lock timeout in seconds |
| `MAESTRO_LOCK_DRIVER` | `database` | Lock driver (database/redis) |
| `MAESTRO_ZOMBIE_THRESHOLD` | `3600` | Zombie detection threshold in seconds |
| `MAESTRO_TRIGGER_SECRET` | - | HMAC secret for trigger authentication |
