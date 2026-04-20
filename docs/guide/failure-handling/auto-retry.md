# Auto-Retry

Auto-retry operates at the **workflow level**, providing an additional retry layer when step-level retries are exhausted. This is useful for recovering from longer outages or systemic failures.

## How Auto-Retry Works

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                        Auto-Retry Flow                                       │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│   Step fails (after step-level retries exhausted)                           │
│                    │                                                         │
│                    ▼                                                         │
│   ┌────────────────────────────────┐                                        │
│   │ Strategy: AutoRetry?           │                                        │
│   └───────────────┬────────────────┘                                        │
│                   │                                                          │
│          ┌────────┴────────┐                                                │
│          │                 │                                                │
│          ▼                 ▼                                                │
│         Yes               No                                                │
│          │                 │                                                │
│          │                 ▼                                                │
│          │          Other strategy                                          │
│          │          (AwaitDecision, etc.)                                   │
│          │                                                                   │
│          ▼                                                                   │
│   ┌────────────────────────────────┐                                        │
│   │ Retries remaining?             │                                        │
│   └───────────────┬────────────────┘                                        │
│                   │                                                          │
│          ┌────────┴────────┐                                                │
│          │                 │                                                │
│          ▼                 ▼                                                │
│         Yes               No                                                │
│          │                 │                                                │
│          ▼                 ▼                                                │
│   Schedule retry    Execute fallback                                        │
│   with delay        strategy                                                │
│          │                                                                   │
│          ▼                                                                   │
│   [Wait delaySeconds × backoff]                                             │
│          │                                                                   │
│          ▼                                                                   │
│   ProcessAutoRetriesCommand                                                 │
│   picks up and retries                                                      │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Configuration

### Basic Configuration

```php
use Maestro\Workflow\Definition\Config\{AutoRetryConfig, FailureResolutionConfig};
use Maestro\Workflow\Enums\FailureResolutionStrategy;

$builder
    ->failureResolution(
        FailureResolutionConfig::create()
            ->strategy(FailureResolutionStrategy::AutoRetry)
            ->autoRetryConfig(new AutoRetryConfig(
                maxRetries: 3,
                delaySeconds: 300,
            ))
    )
```

### Full Configuration

```php
$builder
    ->failureResolution(
        FailureResolutionConfig::create()
            ->strategy(FailureResolutionStrategy::AutoRetry)
            ->autoRetryConfig(new AutoRetryConfig(
                maxRetries: 5,              // Maximum retry attempts
                delaySeconds: 300,          // Initial delay (5 minutes)
                backoffMultiplier: 2.0,     // Double delay each retry
                maxDelaySeconds: 3600,      // Cap at 1 hour
                fallbackStrategy: FailureResolutionStrategy::AwaitDecision,
            ))
    )
```

### Parameters

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `maxRetries` | int | 3 | Maximum number of auto-retry attempts |
| `delaySeconds` | int | 300 | Initial delay before first retry |
| `backoffMultiplier` | float | 2.0 | Multiplier for exponential backoff |
| `maxDelaySeconds` | int | 3600 | Maximum delay between retries |
| `fallbackStrategy` | FailureResolutionStrategy | AwaitDecision | Strategy when retries exhausted |

## Retry Schedule Example

With configuration:
```php
new AutoRetryConfig(
    maxRetries: 4,
    delaySeconds: 300,      // 5 minutes
    backoffMultiplier: 2.0,
    maxDelaySeconds: 1800,  // 30 minutes
)
```

Schedule:
```
Retry 1: After 5 minutes (300s)
Retry 2: After 10 minutes (600s)
Retry 3: After 20 minutes (1200s)
Retry 4: After 30 minutes (1800s, capped)
[Retries exhausted → fallback strategy]
```

## Processing Auto-Retries

Auto-retries are processed by a scheduled command:

### Console Command

```bash
# Process all pending auto-retries
php artisan maestro:process-auto-retries

# With verbose output
php artisan maestro:process-auto-retries -v
```

### Laravel Scheduler

Add to `app/Console/Kernel.php`:

```php
protected function schedule(Schedule $schedule): void
{
    // Process auto-retries every minute
    $schedule->command('maestro:process-auto-retries')
        ->everyMinute()
        ->withoutOverlapping();
}
```

## Events

Auto-retry dispatches these events:

```php
// When auto-retry is scheduled
AutoRetryScheduled::class
// Properties: workflowId, attemptNumber, scheduledFor, reason

// When auto-retries are exhausted
AutoRetryExhausted::class
// Properties: workflowId, maxRetries, fallbackStrategy
```

Monitor auto-retries:

```php
Event::listen(AutoRetryScheduled::class, function ($event) {
    Log::info('Auto-retry scheduled', [
        'workflow_id' => $event->workflowId->value,
        'attempt' => $event->attemptNumber,
        'scheduled_for' => $event->scheduledFor->toIso8601String(),
    ]);
});

Event::listen(AutoRetryExhausted::class, function ($event) {
    Alert::send("Workflow {$event->workflowId} exhausted all auto-retries");
});
```

## Fallback Strategy

When auto-retries are exhausted, the fallback strategy takes over:

### AwaitDecision (Recommended)

```php
new AutoRetryConfig(
    maxRetries: 3,
    fallbackStrategy: FailureResolutionStrategy::AwaitDecision,
)
```

After retries exhausted:
- Workflow remains in `Failed` state
- `WorkflowAwaitingResolution` event dispatched
- Operator must make manual decision

### AutoCompensate

```php
new AutoRetryConfig(
    maxRetries: 3,
    fallbackStrategy: FailureResolutionStrategy::AutoCompensate,
)
```

After retries exhausted:
- Automatically trigger compensation
- Workflow transitions to `Compensating`
- Ends in `Compensated` or `CompensationFailed`

## Step-Level vs Workflow-Level Retry

### Step-Level Retries

Fast retries for transient failures:
- Configured per step
- Immediate or short delays
- Handled by job dispatch system

```php
->step('api_call')
    ->retryable(maxAttempts: 3, delaySeconds: 10)
```

### Workflow-Level Auto-Retry

Longer delays for systemic issues:
- Configured at workflow level
- Minutes to hours between retries
- Processed by scheduled command

```php
->failureResolution(
    FailureResolutionConfig::autoRetry()
        ->withAutoRetryConfig(new AutoRetryConfig(
            maxRetries: 3,
            delaySeconds: 300,
        ))
)
```

### Combining Both

```php
$builder
    // Workflow-level: retry after 5 minutes for outages
    ->failureResolution(
        FailureResolutionConfig::autoRetry()
            ->withAutoRetryConfig(new AutoRetryConfig(
                maxRetries: 3,
                delaySeconds: 300,
            ))
    )

    // Step-level: quick retries for network glitches
    ->step('external_api')
        ->job(CallApiJob::class)
        ->failurePolicy(FailurePolicy::RetryStep)
        ->retryable(maxAttempts: 5, delaySeconds: 5)
        ->build()
```

Flow:
1. Job fails
2. Step retries 5 times (5s, 10s, 20s, 40s, 80s delays)
3. Step ultimately fails
4. Workflow-level auto-retry kicks in
5. Retries 3 times (5m, 10m, 20m delays)
6. Falls back to `AwaitDecision`

## Database Tracking

Auto-retry state is tracked on the workflow:

```php
// Schema fields
'auto_retry_count' => int,           // Current retry count
'auto_retry_scheduled_at' => timestamp,  // Next retry time
'auto_retry_max' => int,             // Max retries allowed
```

Query workflows pending auto-retry:

```php
$pendingRetries = WorkflowModel::query()
    ->where('state', WorkflowState::Failed->value)
    ->whereNotNull('auto_retry_scheduled_at')
    ->where('auto_retry_scheduled_at', '<=', now())
    ->get();
```

## Complete Example

```php
<?php

declare(strict_types=1);

namespace App\Workflows;

use Maestro\Workflow\Definition\Builders\WorkflowDefinitionBuilder;
use Maestro\Workflow\Definition\Config\{AutoRetryConfig, FailureResolutionConfig};
use Maestro\Workflow\Definition\WorkflowDefinition;
use Maestro\Workflow\Enums\{FailurePolicy, FailureResolutionStrategy};

final class ResilientWorkflow extends WorkflowDefinition
{
    public function define(WorkflowDefinitionBuilder $builder): void
    {
        $builder
            ->name('Resilient Processing')
            ->version(1)

            // Aggressive auto-retry for this workflow
            ->failureResolution(
                FailureResolutionConfig::create()
                    ->strategy(FailureResolutionStrategy::AutoRetry)
                    ->autoRetryConfig(new AutoRetryConfig(
                        maxRetries: 5,
                        delaySeconds: 60,           // Start at 1 minute
                        backoffMultiplier: 3.0,     // Triple each time
                        maxDelaySeconds: 7200,      // Cap at 2 hours
                        fallbackStrategy: FailureResolutionStrategy::AwaitDecision,
                    ))
            )

            ->step('fetch_data')
                ->job(FetchDataJob::class)
                ->failurePolicy(FailurePolicy::RetryStep)
                ->retryable(maxAttempts: 3, delaySeconds: 5)
                ->build()

            ->step('process')
                ->job(ProcessDataJob::class)
                ->failurePolicy(FailurePolicy::FailWorkflow)
                ->build()

            ->step('store')
                ->job(StoreResultsJob::class)
                ->failurePolicy(FailurePolicy::RetryStep)
                ->retryable(maxAttempts: 3, delaySeconds: 10)
                ->build();
    }
}
```

## Monitoring Auto-Retries

### Dashboard Query

```php
// Get workflows in auto-retry state
$retrying = WorkflowModel::query()
    ->where('state', WorkflowState::Failed->value)
    ->whereNotNull('auto_retry_scheduled_at')
    ->select([
        'id',
        'definition_key',
        'auto_retry_count',
        'auto_retry_max',
        'auto_retry_scheduled_at',
        'failed_at',
    ])
    ->get();

foreach ($retrying as $workflow) {
    echo "Workflow {$workflow->id}: retry {$workflow->auto_retry_count}/{$workflow->auto_retry_max} ";
    echo "scheduled for {$workflow->auto_retry_scheduled_at}\n";
}
```

### Alerting

```php
// Alert when workflow exhausts auto-retries
Event::listen(AutoRetryExhausted::class, function ($event) {
    Alert::critical("Workflow {$event->workflowId} failed after {$event->maxRetries} auto-retries");
});

// Alert on excessive auto-retries across system
$schedule->call(function () {
    $count = WorkflowModel::query()
        ->where('auto_retry_count', '>=', 2)
        ->where('updated_at', '>=', now()->subHour())
        ->count();

    if ($count > 10) {
        Alert::warning("High auto-retry rate: {$count} workflows retrying");
    }
})->hourly();
```

## Best Practices

1. **Set reasonable delays** - Start with minutes, not seconds
2. **Use backoff** - Avoid hammering failing services
3. **Cap maximum delay** - Don't wait forever
4. **Choose appropriate fallback** - Usually `AwaitDecision`
5. **Monitor retry rates** - High rates indicate systemic issues

## Next Steps

- [Compensation](compensation.md) - Rollback patterns
- [Recovery Operations](recovery.md) - Manual intervention
- [Console Commands](../../operations/console-commands.md) - Operational tooling
