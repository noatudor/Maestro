# Workflow Failure Resolution

When a step ultimately fails (after retries are exhausted or with `FailWorkflow` policy), the workflow-level failure resolution strategy determines what happens next.

## Resolution Strategies

### AwaitDecision (Default)

The workflow transitions to `Failed` state and waits for manual intervention:

```php
use Maestro\Workflow\Definition\Config\FailureResolutionConfig;
use Maestro\Workflow\Enums\FailureResolutionStrategy;

$builder
    ->failureResolution(
        FailureResolutionConfig::create()
            ->strategy(FailureResolutionStrategy::AwaitDecision)
    )
```

**Behavior:**
- Workflow enters `Failed` state
- `WorkflowAwaitingResolution` event dispatched
- Operator must make a resolution decision

**Available decisions:**
- **Retry** - Retry the failed step
- **RetryFromStep** - Retry from an earlier step
- **Compensate** - Trigger compensation for completed steps
- **Cancel** - Cancel the workflow
- **MarkResolved** - Mark as resolved (manual fix applied)

### AutoRetry

Automatically retry the failed step with exponential backoff:

```php
use Maestro\Workflow\Definition\Config\{AutoRetryConfig, FailureResolutionConfig};

$builder
    ->failureResolution(
        FailureResolutionConfig::create()
            ->strategy(FailureResolutionStrategy::AutoRetry)
            ->autoRetryConfig(new AutoRetryConfig(
                maxRetries: 3,
                delaySeconds: 300,        // 5 minutes
                backoffMultiplier: 2.0,
                maxDelaySeconds: 3600,    // 1 hour max
                fallbackStrategy: FailureResolutionStrategy::AwaitDecision,
            ))
    )
```

**Behavior:**
- Workflow stays in `Failed` state but retry is scheduled
- `AutoRetryScheduled` event dispatched
- After max retries, falls back to `fallbackStrategy`

[Learn more about Auto-Retry](auto-retry.md)

### AutoCompensate

Immediately trigger compensation when failure occurs:

```php
use Maestro\Workflow\Enums\CompensationScope;

$builder
    ->failureResolution(
        FailureResolutionConfig::create()
            ->strategy(FailureResolutionStrategy::AutoCompensate)
            ->compensationScope(CompensationScope::All)
    )
```

**Behavior:**
- Workflow transitions to `Compensating` state
- Compensation jobs run for completed steps (in reverse order)
- Workflow ends in `Compensated` or `CompensationFailed` state

**Compensation scopes:**
- `All` - Compensate all completed steps
- `FailedStepOnly` - Compensate only the failed step
- `FromStep` - Compensate from a specific step onwards

[Learn more about Compensation](compensation.md)

## Manual Resolution Decisions

### Via Console Command

```bash
# Retry the failed step
php artisan maestro:resolve {workflowId} --decision=retry

# Retry from a specific step
php artisan maestro:resolve {workflowId} --decision=retry-from-step --step=payment

# Trigger compensation
php artisan maestro:resolve {workflowId} --decision=compensate

# Cancel the workflow
php artisan maestro:resolve {workflowId} --decision=cancel

# Mark as manually resolved
php artisan maestro:resolve {workflowId} --decision=mark-resolved --reason="Fixed manually"
```

### Via API

```http
POST /api/maestro/workflows/{workflowId}/resolve
Content-Type: application/json

{
    "decision": "retry",
    "reason": "Transient failure resolved"
}
```

### Programmatically

```php
use Maestro\Workflow\Contracts\WorkflowManager;
use Maestro\Workflow\Enums\ResolutionDecision;

$workflowManager->resolve(
    workflowId: $workflowId,
    decision: ResolutionDecision::Retry,
    reason: 'Operator approved retry',
);
```

## Resolution Decision Types

### Retry

Retry the failed step:

```php
$workflowManager->resolve($workflowId, ResolutionDecision::Retry);
```

- Creates a new step run superseding the failed one
- Dispatches job for the retried step
- Workflow transitions back to `Running`

### RetryFromStep

Retry from a specific earlier step:

```php
use Maestro\Workflow\ValueObjects\RetryFromStepRequest;

$workflowManager->retryFromStep(
    new RetryFromStepRequest(
        workflowId: $workflowId,
        stepKey: StepKey::fromString('payment'),
        compensateIntermediateSteps: false,
    )
);
```

Options:
- `compensateIntermediateSteps: true` - Run compensation for steps between the retry point and failure
- `compensateIntermediateSteps: false` - Just supersede and retry

### Compensate

Trigger compensation:

```php
$workflowManager->resolve($workflowId, ResolutionDecision::Compensate);
```

- Workflow transitions to `Compensating`
- Runs compensation jobs in reverse step order
- Ends in `Compensated` or `CompensationFailed`

### Cancel

Cancel the workflow:

```php
$workflowManager->resolve($workflowId, ResolutionDecision::Cancel);
```

- Workflow transitions to `Cancelled`
- Optional: trigger compensation first (depends on `cancelBehavior`)

### MarkResolved

Mark as resolved after manual fix:

```php
$workflowManager->resolve(
    $workflowId,
    ResolutionDecision::MarkResolved,
    reason: 'Data manually corrected in database',
);
```

- Records resolution decision for audit
- Workflow stays in `Failed` state (terminal)
- Use when manual intervention fixed the underlying issue

## Cancel Behavior

Configure what happens when a workflow is cancelled:

```php
use Maestro\Workflow\Enums\CancelBehavior;

$builder
    ->failureResolution(
        FailureResolutionConfig::create()
            ->cancelBehavior(CancelBehavior::Compensate)
    )
```

| Behavior | Description |
|----------|-------------|
| `NoCompensate` | Cancel immediately without compensation |
| `Compensate` | Run compensation before cancelling |

## Timeout Behavior

Configure what happens when workflow-level timeout occurs:

```php
use Maestro\Workflow\Enums\TimeoutBehavior;

$builder
    ->failureResolution(
        FailureResolutionConfig::create()
            ->timeoutBehavior(TimeoutBehavior::AwaitDecision)
    )
```

| Behavior | Description |
|----------|-------------|
| `Fail` | Mark workflow as failed |
| `AwaitDecision` | Pause and await operator decision |
| `Compensate` | Trigger compensation |

## Resolution Events

Events dispatched during resolution:

```php
// When workflow awaits decision
WorkflowAwaitingResolution::class

// When decision is made
ResolutionDecisionMade::class  // Contains decision type and reason

// After retry
WorkflowResumed::class

// After compensation
CompensationStarted::class
CompensationCompleted::class  // or CompensationFailed::class
```

Monitor resolution decisions:

```php
Event::listen(ResolutionDecisionMade::class, function ($event) {
    AuditLog::record([
        'workflow_id' => $event->workflowId,
        'decision' => $event->decision->value,
        'reason' => $event->reason,
        'operator' => auth()->user()?->email,
    ]);
});
```

## Configuration Presets

Convenience methods for common configurations:

```php
// Wait for manual decision (default)
FailureResolutionConfig::awaitDecision()

// Auto-retry with defaults
FailureResolutionConfig::autoRetry()

// Auto-retry with custom config
FailureResolutionConfig::autoRetry(maxRetries: 5)

// Auto-compensate with full scope
FailureResolutionConfig::autoCompensate()

// Auto-compensate with specific scope
FailureResolutionConfig::autoCompensate(CompensationScope::FromStep)
```

## Complete Example

```php
<?php

declare(strict_types=1);

namespace App\Workflows;

use Maestro\Workflow\Definition\Builders\WorkflowDefinitionBuilder;
use Maestro\Workflow\Definition\Config\{AutoRetryConfig, FailureResolutionConfig};
use Maestro\Workflow\Definition\WorkflowDefinition;
use Maestro\Workflow\Enums\{
    CancelBehavior,
    CompensationScope,
    FailurePolicy,
    FailureResolutionStrategy,
    TimeoutBehavior,
};

final class PaymentWorkflow extends WorkflowDefinition
{
    public function define(WorkflowDefinitionBuilder $builder): void
    {
        $builder
            ->name('Payment Processing')
            ->version(1)

            // Workflow-level failure resolution
            ->failureResolution(
                FailureResolutionConfig::create()
                    // First, try auto-retry
                    ->strategy(FailureResolutionStrategy::AutoRetry)
                    ->autoRetryConfig(new AutoRetryConfig(
                        maxRetries: 3,
                        delaySeconds: 300,
                        backoffMultiplier: 2.0,
                        maxDelaySeconds: 3600,
                        // Fall back to awaiting decision after retries exhausted
                        fallbackStrategy: FailureResolutionStrategy::AwaitDecision,
                    ))
                    // Always compensate on cancel
                    ->cancelBehavior(CancelBehavior::Compensate)
                    // Compensate all steps when triggered
                    ->compensationScope(CompensationScope::All)
                    // On timeout, await decision
                    ->timeoutBehavior(TimeoutBehavior::AwaitDecision)
            )

            ->step('authorize')
                ->job(AuthorizePaymentJob::class)
                ->failurePolicy(FailurePolicy::RetryStep)
                ->retryable(maxAttempts: 3)
                ->compensation(VoidAuthorizationJob::class)
                ->build()

            ->step('capture')
                ->job(CapturePaymentJob::class)
                ->failurePolicy(FailurePolicy::FailWorkflow)
                ->compensation(RefundPaymentJob::class)
                ->build()

            ->step('notify')
                ->job(SendReceiptJob::class)
                ->failurePolicy(FailurePolicy::SkipStep)
                ->build();
    }
}
```

## Operator Workflow

Typical operator workflow for failed workflows:

```
1. Alert received: Workflow failed
   │
2. Investigate cause
   │
   ├─► Transient failure (API down)
   │   └─► Decision: Retry
   │
   ├─► Data issue
   │   └─► Fix data, then Decision: Retry or MarkResolved
   │
   ├─► Permanent failure
   │   └─► Decision: Compensate or Cancel
   │
   └─► Unknown
       └─► Decision: Pause and investigate further
```

## Next Steps

- [Auto-Retry](auto-retry.md) - Detailed auto-retry configuration
- [Compensation](compensation.md) - Rollback patterns
- [Recovery Operations](recovery.md) - Advanced recovery scenarios
