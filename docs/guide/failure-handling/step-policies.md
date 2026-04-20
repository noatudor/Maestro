# Step Failure Policies

Step failure policies define the immediate response when a job fails. Each step can have its own failure policy, allowing fine-grained control over error handling.

## Available Policies

### FailWorkflow

Immediately transition the workflow to `Failed` state:

```php
use Maestro\Workflow\Enums\FailurePolicy;

->step('validate_payment')
    ->job(ValidatePaymentJob::class)
    ->failurePolicy(FailurePolicy::FailWorkflow)
    ->build()
```

**Behavior:**
- Workflow immediately enters `Failed` state
- No further steps execute
- Workflow-level resolution strategy takes over

**Use for:**
- Critical steps where failure is unrecoverable
- Validation steps that gate subsequent processing
- Steps where partial execution is dangerous

### PauseWorkflow

Pause the workflow for manual intervention:

```php
->step('fraud_check')
    ->job(FraudCheckJob::class)
    ->failurePolicy(FailurePolicy::PauseWorkflow)
    ->build()
```

**Behavior:**
- Workflow enters `Paused` state
- No further steps execute
- Operator must manually resume or cancel

**Resume options:**
```bash
# Resume the workflow (retry the failed step)
php artisan maestro:resume {workflowId}

# Cancel the workflow
php artisan maestro:cancel {workflowId}
```

**Use for:**
- Steps requiring human judgment on failure
- Complex failures needing investigation
- Steps where automatic retry isn't appropriate

### RetryStep

Automatically retry the failed step with configurable backoff:

```php
->step('call_api')
    ->job(CallExternalApiJob::class)
    ->failurePolicy(FailurePolicy::RetryStep)
    ->retryable(
        maxAttempts: 5,
        delaySeconds: 30,
        backoffMultiplier: 2.0,
        maxDelaySeconds: 600
    )
    ->build()
```

**Behavior:**
- Step retries according to configuration
- Exponential backoff between attempts
- Falls through to workflow resolution when retries exhausted

**Retry schedule example:**
```
Attempt 1: Immediate execution
Attempt 2: After 30 seconds
Attempt 3: After 60 seconds (30 × 2)
Attempt 4: After 120 seconds (60 × 2)
Attempt 5: After 240 seconds (120 × 2)
[Retries exhausted → workflow resolution]
```

**Use for:**
- External API calls (transient network failures)
- Database operations (deadlocks, timeouts)
- Any operation with transient failure modes

### SkipStep

Skip the failed step and continue with the workflow:

```php
->step('send_notification')
    ->job(SendNotificationJob::class)
    ->failurePolicy(FailurePolicy::SkipStep)
    ->build()
```

**Behavior:**
- Step marked as `Skipped` with reason
- Workflow continues to next step
- No output produced (downstream steps must handle)

**Use for:**
- Non-critical operations (notifications, logging)
- Optional enhancements that shouldn't block workflow
- Steps with graceful degradation

### ContinueWithPartial

For fan-out steps, continue if partial success criteria is met:

```php
->fanOut('notify_subscribers')
    ->job(NotifySubscriberJob::class)
    ->items(fn($ctx, $out) => $ctx->subscribers)
    ->successCriteria(SuccessCriteria::Majority)
    ->failurePolicy(FailurePolicy::ContinueWithPartial)
    ->build()
```

**Behavior:**
- Evaluates success criteria after all jobs complete
- Continues if criteria met (e.g., majority succeeded)
- Aggregates partial results for downstream steps

**Use for:**
- Fan-out operations with acceptable failure rate
- Batch notifications where partial delivery is acceptable
- Parallel processing where some items may legitimately fail

## Retry Configuration

### Basic Retry

```php
->retryable(maxAttempts: 3)
```

### With Backoff

```php
->retryable(
    maxAttempts: 5,
    delaySeconds: 30,        // Initial delay
    backoffMultiplier: 2.0,  // Double each time
    maxDelaySeconds: 600     // Cap at 10 minutes
)
```

### Retry Scope for Fan-Out

```php
use Maestro\Workflow\Enums\RetryScope;

->fanOut('process_items')
    ->retryable(
        maxAttempts: 3,
        retryScope: RetryScope::FailedOnly  // Only retry failed jobs
    )
```

| Retry Scope | Behavior |
|-------------|----------|
| `All` | Retry all jobs in the fan-out |
| `FailedOnly` | Only retry jobs that failed |

## Policy Selection Guide

```
                    ┌─────────────────────────┐
                    │   Is step critical?     │
                    └───────────┬─────────────┘
                         │
              ┌──────────┴──────────┐
              │                     │
              ▼                     ▼
         Yes (Critical)       No (Non-critical)
              │                     │
              │              ┌──────┴──────┐
              │              │             │
              │              ▼             ▼
              │         Transient     Best effort
              │          errors?       only?
              │              │             │
              │              │             │
              ▼              ▼             ▼
         FailWorkflow   RetryStep      SkipStep
              │              │             │
              │              │             │
              └──────────────┼─────────────┘
                             │
                             ▼
                    Need human review
                    on failure?
                             │
                    ┌────────┴────────┐
                    │                 │
                    ▼                 ▼
                   Yes               No
                    │                 │
                    ▼                 ▼
              PauseWorkflow    (use above)
```

## Combining Policies with Compensation

```php
->step('charge_card')
    ->job(ChargeCardJob::class)
    ->failurePolicy(FailurePolicy::RetryStep)
    ->retryable(maxAttempts: 3, delaySeconds: 10)
    ->compensation(RefundCardJob::class)
    ->build()
```

When retries are exhausted:
1. Step is marked as failed
2. If compensation is triggered later, `RefundCardJob` runs
3. Compensation job can access the failed step's context

## Event Sequence

When a step fails and retries:

```php
// Attempt 1 fails
JobFailed::class           // Job failure recorded
StepRetried::class         // Retry scheduled

// Attempt 2 fails
JobFailed::class
StepRetried::class

// Attempt 3 fails (final attempt)
JobFailed::class
StepFailed::class          // Step ultimately failed
WorkflowFailed::class      // If FailWorkflow policy
// OR
WorkflowPaused::class      // If PauseWorkflow policy
// OR
StepSkipped::class         // If SkipStep policy
```

Listen to events for monitoring:

```php
Event::listen(StepRetried::class, function ($event) {
    Log::info("Step {$event->stepKey} retrying", [
        'attempt' => $event->attemptNumber,
        'delay' => $event->delaySeconds,
    ]);
});

Event::listen(StepFailed::class, function ($event) {
    Alert::send("Step {$event->stepKey} failed after retries");
});
```

## Complete Example

```php
<?php

declare(strict_types=1);

namespace App\Workflows;

use Maestro\Workflow\Definition\Builders\WorkflowDefinitionBuilder;
use Maestro\Workflow\Definition\WorkflowDefinition;
use Maestro\Workflow\Enums\{FailurePolicy, SuccessCriteria};

final class OrderWorkflow extends WorkflowDefinition
{
    public function define(WorkflowDefinitionBuilder $builder): void
    {
        $builder
            ->name('Order Processing')
            ->version(1)

            // CRITICAL: Validation must pass
            ->step('validate')
                ->job(ValidateOrderJob::class)
                ->failurePolicy(FailurePolicy::FailWorkflow)
                ->build()

            // RETRY: Payment may have transient failures
            ->step('payment')
                ->job(ProcessPaymentJob::class)
                ->failurePolicy(FailurePolicy::RetryStep)
                ->retryable(maxAttempts: 3, delaySeconds: 30)
                ->compensation(RefundPaymentJob::class)
                ->build()

            // PAUSE: Fraud check needs human review
            ->step('fraud_check')
                ->job(FraudCheckJob::class)
                ->failurePolicy(FailurePolicy::PauseWorkflow)
                ->build()

            // PARTIAL: Notification delivery can be partial
            ->fanOut('notify_stakeholders')
                ->job(NotifyStakeholderJob::class)
                ->items(fn($ctx, $out) => $ctx->stakeholders)
                ->successCriteria(SuccessCriteria::Majority)
                ->failurePolicy(FailurePolicy::ContinueWithPartial)
                ->build()

            // SKIP: Analytics is non-critical
            ->step('analytics')
                ->job(RecordAnalyticsJob::class)
                ->failurePolicy(FailurePolicy::SkipStep)
                ->build();
    }
}
```

## Next Steps

- [Workflow Resolution](workflow-resolution.md) - What happens after step failure
- [Auto-Retry](auto-retry.md) - Workflow-level retry configuration
- [Compensation](compensation.md) - Rollback patterns
