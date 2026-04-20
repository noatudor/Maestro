# Queue Configuration

This guide covers queue configuration for Maestro workflows.

## Basic Configuration

```php
// config/maestro.php
'queue' => [
    // Queue connection (null = use Laravel default)
    'connection' => env('MAESTRO_QUEUE_CONNECTION'),

    // Queue name for workflow jobs
    'name' => env('MAESTRO_QUEUE_NAME', 'maestro'),
],
```

## Queue Architecture

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                        Queue Architecture                                    │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│   Workflow Jobs                                                              │
│   ┌──────────────────────────────────────────────────────────────────────┐  │
│   │                                                                      │  │
│   │   ┌───────────────┐  ┌───────────────┐  ┌───────────────┐          │  │
│   │   │   Single Job  │  │  Fan-Out Job  │  │  Polling Job  │          │  │
│   │   │   Steps       │  │  Steps        │  │  Steps        │          │  │
│   │   └───────┬───────┘  └───────┬───────┘  └───────┬───────┘          │  │
│   │           │                  │                  │                   │  │
│   │           └──────────────────┼──────────────────┘                   │  │
│   │                              ▼                                      │  │
│   │   ┌──────────────────────────────────────────────────────────────┐ │  │
│   │   │                    Queue Router                               │ │  │
│   │   │                                                               │ │  │
│   │   │   Step onQueue() ──────────────▶ Step-specific queue         │ │  │
│   │   │   Step onConnection() ─────────▶ Step-specific connection    │ │  │
│   │   │   No override ──────────────────▶ Default maestro queue      │ │  │
│   │   │                                                               │ │  │
│   │   └──────────────────────────────────────────────────────────────┘ │  │
│   │                              │                                      │  │
│   └──────────────────────────────┼──────────────────────────────────────┘  │
│                                  ▼                                          │
│   ┌──────────────────────────────────────────────────────────────────────┐  │
│   │                       Laravel Queue                                   │  │
│   │                                                                       │  │
│   │   ┌────────────────┐ ┌────────────────┐ ┌────────────────┐          │  │
│   │   │ maestro        │ │ maestro-high   │ │ maestro-low    │          │  │
│   │   │ (default)      │ │ (priority)     │ │ (background)   │          │  │
│   │   └───────┬────────┘ └───────┬────────┘ └───────┬────────┘          │  │
│   │           │                  │                  │                    │  │
│   │           └──────────────────┼──────────────────┘                    │  │
│   │                              ▼                                       │  │
│   │   ┌──────────────────────────────────────────────────────────────┐  │  │
│   │   │                     Queue Workers                             │  │  │
│   │   │  php artisan queue:work --queue=maestro-high,maestro,...     │  │  │
│   │   └──────────────────────────────────────────────────────────────┘  │  │
│   │                                                                       │  │
│   └──────────────────────────────────────────────────────────────────────┘  │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Per-Step Queue Configuration

Override queue settings for individual steps:

```php
->step('critical_payment')
    ->job(ProcessPaymentJob::class)
    ->onQueue('maestro-high')        // High priority queue
    ->onConnection('redis')          // Specific connection
    ->build()

->step('send_analytics')
    ->job(SendAnalyticsJob::class)
    ->onQueue('maestro-low')         // Low priority queue
    ->build()

->step('delayed_notification')
    ->job(SendNotificationJob::class)
    ->delay(60)                       // Delay by 60 seconds
    ->build()
```

## Queue Priority Strategy

### Multiple Queues Setup

```php
// Define queue names by priority
class QueueNames
{
    public const HIGH = 'maestro-high';
    public const DEFAULT = 'maestro';
    public const LOW = 'maestro-low';
    public const POLLING = 'maestro-polling';
}
```

### Worker Configuration

```bash
# High-priority worker (processes critical jobs first)
php artisan queue:work --queue=maestro-high,maestro,maestro-low

# Dedicated polling worker
php artisan queue:work --queue=maestro-polling --sleep=10

# Background worker
php artisan queue:work --queue=maestro-low --sleep=5
```

### Supervisor Configuration

```ini
; /etc/supervisor/conf.d/maestro-queues.conf

[program:maestro-high]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/artisan queue:work redis --queue=maestro-high --sleep=1 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
numprocs=4
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/maestro-high.log

[program:maestro-default]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/artisan queue:work redis --queue=maestro --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
numprocs=8
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/maestro-default.log

[program:maestro-polling]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/artisan queue:work redis --queue=maestro-polling --sleep=10 --tries=1 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
numprocs=2
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/maestro-polling.log
```

## Laravel Horizon Integration

```php
// config/horizon.php
'environments' => [
    'production' => [
        'maestro-supervisor' => [
            'connection' => 'redis',
            'queue' => ['maestro-high', 'maestro', 'maestro-low'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'minProcesses' => 4,
            'maxProcesses' => 20,
            'balanceMaxShift' => 5,
            'balanceCooldown' => 3,
            'tries' => 3,
            'timeout' => 300,
        ],
        'maestro-polling-supervisor' => [
            'connection' => 'redis',
            'queue' => ['maestro-polling'],
            'balance' => 'simple',
            'minProcesses' => 2,
            'maxProcesses' => 5,
            'tries' => 1,
            'timeout' => 600,
        ],
    ],
],
```

## Redis Queue Configuration

Recommended Redis configuration for production:

```php
// config/queue.php
'connections' => [
    'redis' => [
        'driver' => 'redis',
        'connection' => 'default',
        'queue' => env('REDIS_QUEUE', 'default'),
        'retry_after' => 90,
        'block_for' => 5, // Long polling for efficiency
        'after_commit' => true, // Dispatch after DB commits
    ],
],
```

### Redis Connection Tuning

```php
// config/database.php
'redis' => [
    'client' => env('REDIS_CLIENT', 'phpredis'),

    'default' => [
        'url' => env('REDIS_URL'),
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'password' => env('REDIS_PASSWORD'),
        'port' => env('REDIS_PORT', '6379'),
        'database' => env('REDIS_DB', '0'),
    ],

    'queue' => [
        'url' => env('REDIS_QUEUE_URL'),
        'host' => env('REDIS_QUEUE_HOST', '127.0.0.1'),
        'password' => env('REDIS_QUEUE_PASSWORD'),
        'port' => env('REDIS_QUEUE_PORT', '6379'),
        'database' => env('REDIS_QUEUE_DB', '1'), // Separate DB for queue
    ],
],
```

## Job Timeout Configuration

### Global Timeout

```php
// config/maestro.php (not in current config, but could be added)
'queue' => [
    'connection' => env('MAESTRO_QUEUE_CONNECTION'),
    'name' => env('MAESTRO_QUEUE_NAME', 'maestro'),
    'timeout' => 300, // 5 minutes default
],
```

### Per-Step Timeout

```php
->step('long_running')
    ->job(LongRunningJob::class)
    ->timeout(3600) // 1 hour timeout
    ->build()
```

### In Job Class

```php
final class LongRunningJob extends OrchestratedJob
{
    public int $timeout = 3600; // 1 hour

    public int $tries = 1; // Don't retry automatically

    protected function execute(): void
    {
        // Long-running operation
    }
}
```

## Fan-Out Parallelism

Control concurrent job execution in fan-out steps:

```php
->fanOut('process_items')
    ->job(ProcessItemJob::class)
    ->items(fn($ctx, $out) => $ctx->items)
    ->parallelism(50)  // Max 50 concurrent jobs
    ->build()
```

The `parallelism` setting controls how many jobs are dispatched simultaneously. Remaining items queue up and dispatch as jobs complete.

## Failed Job Handling

### Configuration

```php
// config/queue.php
'failed' => [
    'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
    'database' => env('DB_CONNECTION', 'mysql'),
    'table' => 'failed_jobs',
],
```

### Retry Failed Jobs

```bash
# List failed jobs
php artisan queue:failed

# Retry specific job
php artisan queue:retry {id}

# Retry all failed Maestro jobs
php artisan queue:retry $(php artisan queue:failed --queue=maestro --format=json | jq -r '.[].id')
```

### Automatic Retry

Configure automatic retry attempts:

```php
final class ProcessPaymentJob extends OrchestratedJob
{
    public int $tries = 3;

    public int $maxExceptions = 2;

    public array $backoff = [30, 60, 120]; // Seconds between retries

    protected function execute(): void
    {
        // ...
    }
}
```

## Best Practices

### 1. Separate Queues by Purpose

```php
// Critical path - fast workers
->step('payment')->onQueue('maestro-critical')

// Background tasks - slower workers
->step('analytics')->onQueue('maestro-background')

// Polling - dedicated workers
->polling('wait_confirmation')->onQueue('maestro-polling')
```

### 2. Set Appropriate Timeouts

```php
// Short timeout for fast operations
->step('validate')
    ->timeout(30)

// Longer timeout for external APIs
->step('external_api')
    ->timeout(120)

// Very long timeout for batch processing
->fanOut('process_batch')
    ->timeout(3600)
```

### 3. Use After Commit

Ensure jobs dispatch after database transactions commit:

```php
// In job class
public bool $afterCommit = true;

// Or in step definition
->step('notify')
    ->job(NotifyJob::class)
    ->afterCommit()
```

### 4. Monitor Queue Depth

```php
// Scheduled command to monitor queue health
Schedule::call(function () {
    $depth = Queue::size('maestro');

    if ($depth > 10000) {
        Log::warning('Maestro queue depth high', ['depth' => $depth]);
    }
})->everyMinute();
```

## Next Steps

- [Database Configuration](database.md) - Database settings
- [Trigger Authentication](trigger-auth.md) - External trigger setup
- [Scaling](../advanced/scaling.md) - Production scaling
