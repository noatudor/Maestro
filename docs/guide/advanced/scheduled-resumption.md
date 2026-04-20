# Scheduled Resumption

Scheduled resumption allows paused workflows to automatically resume at a specified time without external triggers.

## Overview

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                       Scheduled Resumption Flow                              │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│   Workflow Execution                                                         │
│        │                                                                     │
│        ▼                                                                     │
│   ┌─────────────────────────────────────────────────────────────────────┐   │
│   │ Step with Resume Condition                                           │   │
│   │                                                                      │   │
│   │  ->step('wait_until_open')                                          │   │
│   │      ->job(PrepareJob::class)                                       │   │
│   │      ->resumeCondition(MarketOpenCondition::class)                  │   │
│   │                                                                      │   │
│   └──────────────────────────┬──────────────────────────────────────────┘   │
│                              │                                               │
│                              ▼                                               │
│   ┌─────────────────────────────────────────────────────────────────────┐   │
│   │                    Resume Condition Check                            │   │
│   │                                                                      │   │
│   │   evaluate() → ResumeConditionResult                                │   │
│   │                                                                      │   │
│   │   • resumeNow()                 → Continue immediately               │   │
│   │   • resumeAt(datetime)          → Schedule for later                │   │
│   │   • waitForTrigger()            → Wait for external trigger         │   │
│   │                                                                      │   │
│   └──────────────────────────┬──────────────────────────────────────────┘   │
│                              │                                               │
│                         ┌────┴────┐                                         │
│                         │         │                                         │
│                         ▼         ▼                                         │
│                    Resume Now  Schedule                                     │
│                         │         │                                         │
│                         │         ▼                                         │
│                         │   ┌─────────────────────────────────────────┐     │
│                         │   │ Workflow Paused                          │     │
│                         │   │ scheduled_resume_at = datetime           │     │
│                         │   └───────────────────┬─────────────────────┘     │
│                         │                       │                           │
│                         │                       │ Scheduled task runs       │
│                         │                       ▼                           │
│                         │   ┌─────────────────────────────────────────┐     │
│                         │   │ ProcessScheduledResumes Command          │     │
│                         │   │ Finds workflows where:                   │     │
│                         │   │ scheduled_resume_at <= now()             │     │
│                         │   └───────────────────┬─────────────────────┘     │
│                         │                       │                           │
│                         └───────────────────────┘                           │
│                                     │                                       │
│                                     ▼                                       │
│                           Workflow Resumed                                  │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Basic Usage

### Define Resume Condition

```php
->step('wait_for_business_hours')
    ->job(PrepareNotificationJob::class)
    ->resumeCondition(BusinessHoursCondition::class)
    ->build()
```

### Implement Resume Condition

```php
<?php

declare(strict_types=1);

namespace App\Conditions;

use Carbon\CarbonImmutable;
use Maestro\Workflow\Contracts\ResumeCondition;
use Maestro\Workflow\Contracts\StepOutputReader;
use Maestro\Workflow\Contracts\WorkflowContext;
use Maestro\Workflow\ValueObjects\ResumeConditionResult;

final readonly class BusinessHoursCondition implements ResumeCondition
{
    public function evaluate(
        WorkflowContext $context,
        StepOutputReader $outputs,
    ): ResumeConditionResult {
        $now = CarbonImmutable::now();

        // Check if within business hours (9 AM - 5 PM, Mon-Fri)
        if ($now->isWeekday() && $now->hour >= 9 && $now->hour < 17) {
            return ResumeConditionResult::resumeNow();
        }

        // Calculate next business hours
        $resumeAt = $this->nextBusinessHours($now);

        return ResumeConditionResult::resumeAt($resumeAt);
    }

    private function nextBusinessHours(CarbonImmutable $from): CarbonImmutable
    {
        $candidate = $from;

        // If it's a weekend, move to Monday
        while ($candidate->isWeekend()) {
            $candidate = $candidate->addDay();
        }

        // If before 9 AM, resume at 9 AM today
        if ($candidate->hour < 9) {
            return $candidate->setTime(9, 0, 0);
        }

        // If after 5 PM, resume at 9 AM next business day
        if ($candidate->hour >= 17) {
            $candidate = $candidate->addDay();
            while ($candidate->isWeekend()) {
                $candidate = $candidate->addDay();
            }
            return $candidate->setTime(9, 0, 0);
        }

        return $candidate;
    }
}
```

## Use Cases

### 1. Market Hours Trading

```php
final readonly class MarketOpenCondition implements ResumeCondition
{
    public function evaluate(
        WorkflowContext $context,
        StepOutputReader $outputs,
    ): ResumeConditionResult {
        $market = $context->market; // NYSE, NASDAQ, etc.
        $now = CarbonImmutable::now($market->timezone);

        if ($this->isMarketOpen($market, $now)) {
            return ResumeConditionResult::resumeNow();
        }

        $nextOpen = $this->nextMarketOpen($market, $now);

        return ResumeConditionResult::resumeAt($nextOpen);
    }

    private function isMarketOpen(Market $market, CarbonImmutable $time): bool
    {
        if ($this->isHoliday($market, $time)) {
            return false;
        }

        return $time->isWeekday()
            && $time->between(
                $time->setTimeFromTimeString($market->openTime),
                $time->setTimeFromTimeString($market->closeTime)
            );
    }
}
```

### 2. Rate Limit Backoff

```php
final readonly class RateLimitBackoffCondition implements ResumeCondition
{
    public function evaluate(
        WorkflowContext $context,
        StepOutputReader $outputs,
    ): ResumeConditionResult {
        $rateLimitOutput = $outputs->getOrNull(RateLimitOutput::class);

        if (!$rateLimitOutput || !$rateLimitOutput->limited) {
            return ResumeConditionResult::resumeNow();
        }

        // Resume when rate limit resets
        return ResumeConditionResult::resumeAt(
            CarbonImmutable::parse($rateLimitOutput->resetAt)
        );
    }
}
```

### 3. Scheduled Batch Processing

```php
final readonly class NightlyBatchCondition implements ResumeCondition
{
    public function evaluate(
        WorkflowContext $context,
        StepOutputReader $outputs,
    ): ResumeConditionResult {
        $now = CarbonImmutable::now();

        // Run batch at 2 AM
        $batchTime = $now->setTime(2, 0, 0);

        if ($now->hour >= 2 && $now->hour < 6) {
            // We're in the batch window
            return ResumeConditionResult::resumeNow();
        }

        // Schedule for tonight at 2 AM (or tomorrow if past 6 AM)
        if ($now->hour >= 6) {
            $batchTime = $batchTime->addDay();
        }

        return ResumeConditionResult::resumeAt($batchTime);
    }
}
```

### 4. Maintenance Window Avoidance

```php
final readonly class AvoidMaintenanceCondition implements ResumeCondition
{
    public function evaluate(
        WorkflowContext $context,
        StepOutputReader $outputs,
    ): ResumeConditionResult {
        $now = CarbonImmutable::now();

        // Check scheduled maintenance windows
        $maintenance = MaintenanceWindow::where('starts_at', '<=', $now->addHours(2))
            ->where('ends_at', '>', $now)
            ->first();

        if (!$maintenance) {
            return ResumeConditionResult::resumeNow();
        }

        // Resume after maintenance ends
        return ResumeConditionResult::resumeAt(
            CarbonImmutable::parse($maintenance->ends_at)->addMinutes(5)
        );
    }
}
```

### 5. External Dependency Check

```php
final readonly class DependencyReadyCondition implements ResumeCondition
{
    public function __construct(
        private readonly DependencyChecker $checker,
    ) {}

    public function evaluate(
        WorkflowContext $context,
        StepOutputReader $outputs,
    ): ResumeConditionResult {
        $status = $this->checker->checkDependency($context->dependencyId);

        if ($status->ready) {
            return ResumeConditionResult::resumeNow();
        }

        if ($status->estimatedReadyAt) {
            return ResumeConditionResult::resumeAt(
                CarbonImmutable::parse($status->estimatedReadyAt)
            );
        }

        // No estimate - wait for external trigger
        return ResumeConditionResult::waitForTrigger();
    }
}
```

## Processing Scheduled Resumes

### Scheduler Setup

```php
// app/Console/Kernel.php
protected function schedule(Schedule $schedule): void
{
    $schedule->command('maestro:process-scheduled-resumes')
        ->everyMinute()
        ->withoutOverlapping();
}
```

### Command Details

The `maestro:process-scheduled-resumes` command:

1. Finds workflows where `scheduled_resume_at <= now()`
2. Resumes each workflow
3. Clears the scheduled time
4. Advances the workflow

```bash
# Run manually
php artisan maestro:process-scheduled-resumes

# With verbose output
php artisan maestro:process-scheduled-resumes -v

# Process specific workflow
php artisan maestro:resume {workflow_id}
```

## Combining with Triggers

### Hybrid Approach

Resume by schedule OR external trigger (whichever comes first):

```php
->step('wait_for_data')
    ->job(PrepareDataJob::class)
    ->resumeCondition(DataReadyCondition::class)
    ->pauseTrigger(new PauseTriggerDefinition(
        triggerKey: 'data-ready',
        timeoutSeconds: 86400, // 24 hours max wait
    ))
    ->build()
```

```php
final readonly class DataReadyCondition implements ResumeCondition
{
    public function evaluate(
        WorkflowContext $context,
        StepOutputReader $outputs,
    ): ResumeConditionResult {
        // Check if data is already available
        if ($this->isDataReady($context->dataSourceId)) {
            return ResumeConditionResult::resumeNow();
        }

        // Check for known ETL schedule
        $etlSchedule = $this->getEtlSchedule($context->dataSourceId);

        if ($etlSchedule->nextRun) {
            // Resume shortly after scheduled ETL
            return ResumeConditionResult::resumeAt(
                $etlSchedule->nextRun->addMinutes(10)
            );
        }

        // Unknown schedule - wait for trigger
        return ResumeConditionResult::waitForTrigger();
    }
}
```

## Events

```php
// Workflow paused with scheduled resume
WorkflowPaused::class
// $event->scheduledResumeAt contains the datetime

// Workflow auto-resumed by scheduler
WorkflowAutoResumed::class
// Properties: workflowId, resumedAt, scheduledFor
```

Monitor scheduled resumes:

```php
Event::listen(WorkflowAutoResumed::class, function ($event) {
    Log::info('Workflow auto-resumed', [
        'workflow_id' => $event->workflowId->value,
        'scheduled_for' => $event->scheduledFor->toIso8601String(),
        'actual_resume' => $event->resumedAt->toIso8601String(),
        'delay_seconds' => $event->resumedAt->diffInSeconds($event->scheduledFor),
    ]);
});
```

## Database Schema

```sql
-- Workflows table includes
ALTER TABLE maestro_workflows ADD COLUMN scheduled_resume_at TIMESTAMP NULL;

-- Index for efficient lookup
CREATE INDEX idx_workflows_scheduled_resume
ON maestro_workflows(scheduled_resume_at)
WHERE scheduled_resume_at IS NOT NULL;
```

## Best Practices

### 1. Use Reasonable Scheduling Granularity

```php
// Good: Schedule to the minute
return ResumeConditionResult::resumeAt(
    $now->addMinutes(5)->startOfMinute()
);

// Avoid: Scheduling to exact seconds (unnecessary precision)
return ResumeConditionResult::resumeAt(
    $now->addSeconds(137)
);
```

### 2. Account for Timezone

```php
public function evaluate(...): ResumeConditionResult
{
    // Use the appropriate timezone
    $userTimezone = $context->userTimezone ?? 'UTC';
    $now = CarbonImmutable::now($userTimezone);

    // Convert back to UTC for storage
    $resumeAt = $this->calculateResumeTime($now)
        ->setTimezone('UTC');

    return ResumeConditionResult::resumeAt($resumeAt);
}
```

### 3. Handle Edge Cases

```php
public function evaluate(...): ResumeConditionResult
{
    $resumeAt = $this->calculateResumeTime();

    // Don't schedule in the past
    if ($resumeAt->isPast()) {
        return ResumeConditionResult::resumeNow();
    }

    // Don't schedule too far in the future
    if ($resumeAt->diffInDays(now()) > 30) {
        Log::warning('Resume scheduled very far in future', [
            'resume_at' => $resumeAt->toIso8601String(),
        ]);
    }

    return ResumeConditionResult::resumeAt($resumeAt);
}
```

### 4. Log Schedule Decisions

```php
public function evaluate(...): ResumeConditionResult
{
    $now = CarbonImmutable::now();

    if ($this->shouldResumeNow($context)) {
        Log::debug('Resume condition: immediate', [
            'condition' => static::class,
        ]);
        return ResumeConditionResult::resumeNow();
    }

    $resumeAt = $this->calculateResumeTime($context);

    Log::debug('Resume condition: scheduled', [
        'condition' => static::class,
        'resume_at' => $resumeAt->toIso8601String(),
        'wait_seconds' => $now->diffInSeconds($resumeAt),
    ]);

    return ResumeConditionResult::resumeAt($resumeAt);
}
```

## Next Steps

- [External Triggers](external-triggers.md) - Webhook integration
- [Branching](branching.md) - Conditional paths
- [Events Reference](../../operations/events.md) - All events
