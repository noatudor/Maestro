# Step Types Overview

Maestro supports three step types, each designed for different execution patterns. Understanding when to use each type is key to designing effective workflows.

## Step Type Comparison

| Feature | Single Job | Fan-Out | Polling |
|---------|-----------|---------|---------|
| **Jobs per execution** | 1 | N (dynamic) | 1+ (repeated) |
| **Use case** | Sequential tasks | Parallel processing | Long-running ops |
| **Success criteria** | Job succeeds | Configurable | Condition met |
| **Output** | Single value | Aggregated results | Final value |

## Single Job Steps

The most common step type. Executes a single job and advances the workflow on completion.

```php
->step('process_payment')
    ->name('Process Payment')
    ->job(ProcessPaymentJob::class)
    ->produces(PaymentOutput::class)
    ->build()
```

**Use when:**
- Performing a single operation (API call, database update, notification)
- Steps must execute sequentially
- One job handles the entire operation

[Learn more about Single Job Steps](single-job.md)

## Fan-Out Steps

Executes multiple jobs in parallel, one for each item in a collection. Supports configurable success criteria.

```php
->fanOut('process_line_items')
    ->name('Process Line Items')
    ->job(ProcessLineItemJob::class)
    ->items(fn($ctx, $out) => $out->get(OrderOutput::class)->items)
    ->successCriteria(SuccessCriteria::All)
    ->parallelism(10)
    ->build()
```

**Use when:**
- Processing collections of items in parallel
- Independent operations on multiple entities
- Batch processing with configurable success thresholds

[Learn more about Fan-Out Steps](fan-out.md)

## Polling Steps

Repeatedly executes a job until a condition is met or timeout occurs. Built-in support for exponential backoff.

```php
->polling('wait_for_approval')
    ->name('Wait for Approval')
    ->job(CheckApprovalJob::class)
    ->polling(
        intervalSeconds: 30,
        maxDurationSeconds: 86400,
        backoffMultiplier: 1.5
    )
    ->build()
```

**Use when:**
- Waiting for external system state changes
- Monitoring long-running external processes
- Periodic checks until a condition is satisfied

[Learn more about Polling Steps](polling.md)

## Common Step Configuration

All step types share these configuration options:

### Output Configuration

```php
->produces(PaymentOutput::class)      // Step produces this output
->requires('validate', OrderOutput::class)  // Step requires this output
```

### Failure Policy

```php
->failurePolicy(FailurePolicy::FailWorkflow)   // Default
->failurePolicy(FailurePolicy::PauseWorkflow)  // Pause for intervention
->failurePolicy(FailurePolicy::RetryStep)      // Retry with backoff
->failurePolicy(FailurePolicy::SkipStep)       // Skip and continue
```

### Retry Configuration

```php
->retryable(
    maxAttempts: 3,
    delaySeconds: 30,
    backoffMultiplier: 2.0,
    maxDelaySeconds: 300
)
```

### Timeout

```php
->timeout(seconds: 300)  // 5-minute timeout
```

### Queue Configuration

```php
->onQueue('high-priority')
->onConnection('redis')
```

### Conditional Execution

```php
->condition(OrderValueCondition::class)  // Only execute if condition passes
```

### Compensation

```php
->compensation(RefundPaymentJob::class)  // Rollback job if compensation triggered
```

## Step Execution Flow

```
┌─────────────────────────────────────────────────────────────────┐
│                     Step Execution Flow                          │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│   ┌──────────┐     ┌──────────┐     ┌──────────┐               │
│   │ Pending  │────▶│ Running  │────▶│Succeeded │               │
│   └──────────┘     └──────────┘     └──────────┘               │
│        │                │                                        │
│        │                │ (failure)                              │
│        │                ▼                                        │
│        │           ┌──────────┐                                 │
│        │           │  Failed  │                                 │
│        │           └──────────┘                                 │
│        │                │                                        │
│        │                │ (retry/supersede)                      │
│        │                ▼                                        │
│        │          ┌───────────┐                                 │
│        │          │Superseded │                                 │
│        │          └───────────┘                                 │
│        │                                                         │
│        │ (condition false / not on branch)                      │
│        ▼                                                         │
│   ┌──────────┐                                                  │
│   │ Skipped  │                                                  │
│   └──────────┘                                                  │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

## Next Steps

- [Single Job Steps](single-job.md) - Detailed single job configuration
- [Fan-Out Steps](fan-out.md) - Parallel processing patterns
- [Polling Steps](polling.md) - Long-running operation patterns
