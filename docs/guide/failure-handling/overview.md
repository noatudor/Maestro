# Failure Handling Overview

Maestro provides a comprehensive failure handling system operating at two levels: **step-level** (immediate response to job failures) and **workflow-level** (overall failure recovery strategy).

## Failure Handling Architecture

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                        Failure Handling Flow                                 │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│     Job Fails                                                                │
│         │                                                                    │
│         ▼                                                                    │
│   ┌─────────────────┐                                                       │
│   │ Step Failure    │  ◄── First line of defense                            │
│   │ Policy          │      (FailurePolicy enum)                             │
│   └────────┬────────┘                                                       │
│            │                                                                 │
│   ┌────────┼────────┬────────────┬────────────┐                             │
│   │        │        │            │            │                             │
│   ▼        ▼        ▼            ▼            ▼                             │
│ Fail    Pause    Retry        Skip      Continue                            │
│ Workflow Workflow  Step        Step      Partial                            │
│   │        │        │            │            │                             │
│   │        │        │ (retries   │            │                             │
│   │        │        │ exhausted) │            │                             │
│   │        │        │            │            │                             │
│   ▼        ▼        ▼            ▼            ▼                             │
│   ┌─────────────────────────────────────────────────────────────┐           │
│   │              Workflow Failure Resolution                     │           │
│   │              (FailureResolutionStrategy)                     │           │
│   └─────────────────────────────────────────────────────────────┘           │
│            │                                                                 │
│   ┌────────┼────────┬────────────┐                                          │
│   │        │        │            │                                          │
│   ▼        ▼        ▼            ▼                                          │
│ Await   Auto     Auto        Manual                                         │
│ Decision Retry   Compensate  Resolution                                     │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Step Failure Policies

Step failure policies define the **immediate response** when a job fails:

| Policy | Behavior | Use Case |
|--------|----------|----------|
| `FailWorkflow` | Immediately fail the workflow | Critical steps that must succeed |
| `PauseWorkflow` | Pause for manual intervention | Steps requiring human review |
| `RetryStep` | Retry with exponential backoff | Transient failures (API calls) |
| `SkipStep` | Skip and continue to next step | Non-critical steps |
| `ContinueWithPartial` | Continue with partial results | Fan-out with acceptable failures |

```php
use Maestro\Workflow\Enums\FailurePolicy;

->step('payment')
    ->failurePolicy(FailurePolicy::RetryStep)
    ->retryable(maxAttempts: 3)
    ->build()
```

[Learn more about Step Failure Policies](step-policies.md)

## Workflow Resolution Strategies

When a step ultimately fails (after retries are exhausted or with `FailWorkflow` policy), the **workflow resolution strategy** determines what happens next:

| Strategy | Behavior | Use Case |
|----------|----------|----------|
| `AwaitDecision` | Wait for manual decision | Human review required |
| `AutoRetry` | Automatically retry failed step | Fully automated recovery |
| `AutoCompensate` | Trigger compensation immediately | Automatic rollback |

```php
use Maestro\Workflow\Definition\Config\FailureResolutionConfig;

$builder
    ->failureResolution(
        FailureResolutionConfig::autoRetry(maxRetries: 3)
    )
```

[Learn more about Workflow Resolution](workflow-resolution.md)

## Resolution Decision Flow

When using `AwaitDecision` strategy, operators can make manual decisions:

```
┌─────────────────────────────────────────────────────────────┐
│                  Resolution Decisions                        │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│   Workflow in FAILED state, awaiting decision                │
│                    │                                         │
│   ┌────────────────┼────────────────────────┐               │
│   │                │                        │               │
│   ▼                ▼                        ▼               │
│ Retry          Compensate               Cancel              │
│   │                │                        │               │
│   ▼                ▼                        ▼               │
│ Retry from    Run compensation        Mark as              │
│ failed step   for completed steps    cancelled             │
│                                                              │
│                    │                                         │
│                    ▼                                         │
│              RetryFromStep                                   │
│              (start from specific step)                      │
│                                                              │
│                    │                                         │
│                    ▼                                         │
│              MarkResolved                                    │
│              (manual fix applied)                            │
│                                                              │
└─────────────────────────────────────────────────────────────┘
```

## Auto-Retry System

Auto-retry operates at the **workflow level**, providing an additional retry layer when step-level retries are exhausted:

```php
use Maestro\Workflow\Definition\Config\AutoRetryConfig;

$builder
    ->failureResolution(
        FailureResolutionConfig::autoRetry()
            ->withAutoRetryConfig(new AutoRetryConfig(
                maxRetries: 3,
                delaySeconds: 300,
                backoffMultiplier: 2.0,
                maxDelaySeconds: 3600,
            ))
    )
```

[Learn more about Auto-Retry](auto-retry.md)

## Compensation

Compensation provides **rollback capability** by running cleanup jobs for completed steps:

```php
->step('charge_card')
    ->job(ChargeCardJob::class)
    ->compensation(RefundChargeJob::class)  // Runs during compensation
    ->build()
```

Compensation can be triggered:
- Automatically via `AutoCompensate` strategy
- Manually via resolution decision
- Via console command

[Learn more about Compensation](compensation.md)

## Recovery Operations

For advanced recovery scenarios, Maestro supports:

### Retry from Specific Step

```bash
php artisan maestro:retry-from-step {workflowId} {stepKey}
```

Supersedes failed steps and restarts from a specific point.

### Retry with Compensation

```bash
php artisan maestro:retry-from-step {workflowId} {stepKey} --compensate
```

Compensates steps between the failure and restart point before retrying.

[Learn more about Recovery Operations](recovery.md)

## Configuration Example

Complete failure handling configuration:

```php
<?php

declare(strict_types=1);

namespace App\Workflows;

use Maestro\Workflow\Definition\Builders\WorkflowDefinitionBuilder;
use Maestro\Workflow\Definition\Config\{AutoRetryConfig, FailureResolutionConfig};
use Maestro\Workflow\Definition\WorkflowDefinition;
use Maestro\Workflow\Enums\{FailurePolicy, FailureResolutionStrategy};

final class RobustWorkflow extends WorkflowDefinition
{
    public function define(WorkflowDefinitionBuilder $builder): void
    {
        $builder
            ->name('Robust Order Processing')
            ->version(1)

            // Workflow-level failure resolution
            ->failureResolution(
                FailureResolutionConfig::create()
                    ->strategy(FailureResolutionStrategy::AutoRetry)
                    ->autoRetryConfig(new AutoRetryConfig(
                        maxRetries: 3,
                        delaySeconds: 300,
                        backoffMultiplier: 2.0,
                        fallbackStrategy: FailureResolutionStrategy::AwaitDecision,
                    ))
            )

            // Critical step - fail immediately on error
            ->step('validate')
                ->job(ValidateOrderJob::class)
                ->failurePolicy(FailurePolicy::FailWorkflow)
                ->build()

            // Payment - retry with compensation
            ->step('payment')
                ->job(ChargePaymentJob::class)
                ->failurePolicy(FailurePolicy::RetryStep)
                ->retryable(maxAttempts: 3, delaySeconds: 30)
                ->compensation(RefundPaymentJob::class)
                ->build()

            // Inventory - pause for manual review on failure
            ->step('inventory')
                ->job(AllocateInventoryJob::class)
                ->failurePolicy(FailurePolicy::PauseWorkflow)
                ->compensation(ReleaseInventoryJob::class)
                ->build()

            // Notification - non-critical, skip on failure
            ->step('notify')
                ->job(SendNotificationJob::class)
                ->failurePolicy(FailurePolicy::SkipStep)
                ->build();
    }
}
```

## Failure Handling Best Practices

### 1. Layer Your Defenses

Use step-level retries for transient failures, workflow-level auto-retry for persistent issues:

```php
// Step level: Quick retries for network hiccups
->retryable(maxAttempts: 3, delaySeconds: 5)

// Workflow level: Longer delays for service outages
->failureResolution(FailureResolutionConfig::autoRetry()
    ->withAutoRetryConfig(new AutoRetryConfig(
        maxRetries: 3,
        delaySeconds: 300,  // 5 minutes
    )))
```

### 2. Define Compensation for Side Effects

Any step that creates external state should have compensation:

```php
->step('create_subscription')
    ->job(CreateSubscriptionJob::class)
    ->compensation(CancelSubscriptionJob::class)
    ->build()
```

### 3. Use Appropriate Failure Policies

Match the policy to the step's importance:

| Step Type | Recommended Policy |
|-----------|-------------------|
| Critical business logic | `FailWorkflow` |
| External API calls | `RetryStep` |
| Notifications | `SkipStep` |
| Complex operations | `PauseWorkflow` |

### 4. Set Up Monitoring

Listen to failure events for alerting:

```php
Event::listen(WorkflowFailed::class, function ($event) {
    Alert::critical("Workflow {$event->workflowId} failed");
});

Event::listen(AutoRetryExhausted::class, function ($event) {
    Alert::warning("Workflow {$event->workflowId} exhausted auto-retries");
});
```

## Next Steps

- [Step Failure Policies](step-policies.md) - Detailed policy configuration
- [Workflow Resolution](workflow-resolution.md) - Resolution strategies
- [Auto-Retry](auto-retry.md) - Automatic retry configuration
- [Compensation](compensation.md) - Rollback patterns
- [Recovery Operations](recovery.md) - Manual intervention
