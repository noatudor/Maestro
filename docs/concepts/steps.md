# Steps

Steps are the building blocks of workflows. Each step performs a discrete unit of work and can produce output for downstream steps.

## Step Types

Maestro provides three step types for different execution patterns:

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                            Step Types                                        │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│   Single Job                Fan-Out                   Polling                │
│   ──────────                ────────                  ───────                │
│                                                                              │
│   ┌─────────┐           ┌─────────┐              ┌─────────┐               │
│   │  Job A  │           │ Job A-1 │              │  Poll   │               │
│   └────┬────┘           ├─────────┤              └────┬────┘               │
│        │                │ Job A-2 │                   │                     │
│        │                ├─────────┤              ┌────┴────┐               │
│        ▼                │ Job A-3 │              │ Continue?│               │
│   One job per           ├─────────┤              └────┬────┘               │
│   step execution        │   ...   │                   │                     │
│                         └────┬────┘              ┌────┴────┐               │
│                              │                   │    or   │               │
│                              ▼                   ▼         ▼               │
│                         Multiple jobs        Complete   Timeout            │
│                         in parallel                                        │
│                                                                              │
│   Use for:              Use for:              Use for:                      │
│   • Sequential ops      • Batch processing    • Waiting for                 │
│   • Single API calls    • Parallel items      • external state              │
│   • Database updates    • Notifications       • Long-running ops            │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Single Job Steps

Execute one job per workflow execution:

```php
->step('validate_order')
    ->name('Validate Order')
    ->job(ValidateOrderJob::class)
    ->produces(ValidationOutput::class)
    ->build()
```

[Detailed Single Job Documentation](../guide/step-types/single-job.md)

## Fan-Out Steps

Execute multiple jobs in parallel:

```php
->fanOut('process_items')
    ->name('Process Line Items')
    ->job(ProcessItemJob::class)
    ->items(fn($ctx, $out) => $ctx->order->items)
    ->successCriteria(SuccessCriteria::All)
    ->parallelism(10)
    ->build()
```

[Detailed Fan-Out Documentation](../guide/step-types/fan-out.md)

## Polling Steps

Repeatedly execute until condition met:

```php
->polling('wait_for_payment')
    ->name('Wait for Payment')
    ->job(CheckPaymentJob::class)
    ->polling(
        intervalSeconds: 30,
        maxDurationSeconds: 3600,
        backoffMultiplier: 1.5,
    )
    ->build()
```

[Detailed Polling Documentation](../guide/step-types/polling.md)

## Step Configuration

### Common Options

All step types share these configuration options:

```php
->step('example')
    // Display name for UI/logs
    ->name('Human Readable Name')

    // The job class to execute
    ->job(MyJob::class)

    // Output this step produces
    ->produces(MyOutput::class)

    // Output this step requires (with source step)
    ->requires('previous_step', PreviousOutput::class)

    // Failure handling
    ->failurePolicy(FailurePolicy::RetryStep)
    ->retryable(maxAttempts: 3, delaySeconds: 30)

    // Timeout
    ->timeout(seconds: 300)

    // Queue configuration
    ->onQueue('high-priority')
    ->onConnection('redis')

    // Conditional execution
    ->condition(MyCondition::class)

    // Rollback job
    ->compensation(MyCompensationJob::class)

    // External trigger
    ->pauseTrigger(new PauseTriggerDefinition(...))

    // Branching
    ->branch(new BranchDefinition(...))

    // Early termination
    ->terminationCondition(MyTerminationCondition::class)

    ->build()
```

## Step States

```
┌─────────────────────────────────────────────────────────────────┐
│                      Step State Machine                          │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│                     ┌─────────┐                                 │
│                     │ Pending │                                 │
│                     └────┬────┘                                 │
│                          │                                      │
│         ┌────────────────┼────────────────┐                     │
│         │                │                │                     │
│    start()          startPolling()     skip()                   │
│         │                │                │                     │
│         ▼                ▼                ▼                     │
│    ┌─────────┐     ┌─────────┐      ┌─────────┐                │
│    │ Running │     │ Polling │      │ Skipped │                │
│    └────┬────┘     └────┬────┘      └─────────┘                │
│         │               │            (terminal)                 │
│    ┌────┴────┐     ┌────┴────┐                                 │
│    │         │     │         │                                  │
│    ▼         ▼     ▼         ▼                                  │
│ ┌──────────┐ ┌──────┐ ┌──────────┐ ┌─────────┐                 │
│ │Succeeded │ │Failed│ │Succeeded │ │TimedOut │                 │
│ └──────────┘ └──────┘ └──────────┘ └─────────┘                 │
│  (terminal)  (term*)  (terminal)   (terminal)                  │
│                                                                  │
│   * Can transition to Superseded via retry-from-step            │
│                                                                  │
│   Any → Superseded (when retried from earlier step)             │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

| State | Description |
|-------|-------------|
| `Pending` | Not yet started |
| `Running` | Job(s) executing |
| `Polling` | Polling in progress |
| `Succeeded` | Completed successfully |
| `Failed` | Failed after retries |
| `TimedOut` | Polling timed out |
| `Skipped` | Skipped (condition/branch) |
| `Superseded` | Replaced by retry |

## Step Dependencies

### Output Requirements

Steps can require outputs from previous steps:

```php
// Step A produces output
->step('validate')
    ->job(ValidateJob::class)
    ->produces(ValidationOutput::class)
    ->build()

// Step B requires that output
->step('process')
    ->job(ProcessJob::class)
    ->requires('validate', ValidationOutput::class)
    ->build()
```

### Multiple Dependencies

```php
->step('finalize')
    ->job(FinalizeJob::class)
    ->requires('validate', ValidationOutput::class)
    ->requires('payment', PaymentOutput::class)
    ->requires('shipping', ShippingOutput::class)
    ->build()
```

### Implicit Ordering

Steps execute in definition order, but requirements create explicit dependencies:

```php
// Steps execute: A → B → C
// Even if B doesn't require A's output
->step('a')->job(JobA::class)->build()
->step('b')->job(JobB::class)->build()
->step('c')->job(JobC::class)->build()
```

## Failure Policies

Control what happens when a step fails:

| Policy | Behavior |
|--------|----------|
| `FailWorkflow` | Immediately fail workflow |
| `PauseWorkflow` | Pause for manual intervention |
| `RetryStep` | Retry with backoff |
| `SkipStep` | Skip and continue |
| `ContinueWithPartial` | Fan-out: continue with partial success |

```php
->step('critical')
    ->failurePolicy(FailurePolicy::FailWorkflow)
    ->build()

->step('retryable')
    ->failurePolicy(FailurePolicy::RetryStep)
    ->retryable(maxAttempts: 3, delaySeconds: 30)
    ->build()
```

[Detailed Failure Handling](../guide/failure-handling/overview.md)

## Conditional Execution

### Step Conditions

Execute only when condition passes:

```php
->step('premium_only')
    ->job(PremiumProcessingJob::class)
    ->condition(IsPremiumCustomerCondition::class)
    ->build()
```

```php
final readonly class IsPremiumCustomerCondition implements StepCondition
{
    public function evaluate(
        WorkflowContext $context,
        StepOutputReader $outputs,
    ): ConditionResult {
        if ($context->customer->isPremium) {
            return ConditionResult::pass();
        }
        return ConditionResult::fail(reason: 'Not premium');
    }
}
```

### Branching

Route to different steps based on conditions:

```php
->step('route')
    ->branch(new BranchDefinition(
        conditionClass: OrderTypeCondition::class,
        branchType: BranchType::Exclusive,
        branches: [
            'digital' => ['deliver_digital'],
            'physical' => ['ship_physical'],
        ],
        convergenceStepKey: 'notify',
    ))
    ->build()
```

[Detailed Branching Documentation](../guide/advanced/branching.md)

## Step Compensation

Define rollback jobs for saga pattern:

```php
->step('charge_card')
    ->job(ChargeCardJob::class)
    ->compensation(RefundCardJob::class)
    ->build()
```

When compensation is triggered, compensation jobs run in reverse order.

[Detailed Compensation Documentation](../guide/failure-handling/compensation.md)

## External Triggers

Pause step to wait for external event:

```php
->step('await_approval')
    ->job(RequestApprovalJob::class)
    ->pauseTrigger(new PauseTriggerDefinition(
        triggerKey: 'manager-approval',
        timeoutSeconds: 86400,
        timeoutPolicy: TriggerTimeoutPolicy::SendReminder,
    ))
    ->build()
```

[Detailed Triggers Documentation](../guide/advanced/external-triggers.md)

## Step Run Records

Each step execution creates a `StepRun` record:

```php
// Query step runs for a workflow
$stepRuns = StepRunModel::where('workflow_id', $workflowId)->get();

foreach ($stepRuns as $run) {
    echo "Step: {$run->step_key}\n";
    echo "State: {$run->state}\n";
    echo "Attempt: {$run->attempt_number}\n";
    echo "Started: {$run->started_at}\n";
    echo "Completed: {$run->completed_at}\n";
}
```

## Best Practices

### 1. Keep Steps Atomic

Each step should do one thing well:

```php
// Good: Single responsibility
->step('validate')->job(ValidateJob::class)->build()
->step('charge')->job(ChargeJob::class)->build()
->step('notify')->job(NotifyJob::class)->build()

// Bad: Too much in one step
->step('do_everything')->job(ValidateChargeAndNotifyJob::class)->build()
```

### 2. Name Steps Clearly

```php
// Good: Descriptive
->step('validate_inventory_availability')
->step('process_credit_card_payment')
->step('send_order_confirmation_email')

// Bad: Vague
->step('step1')
->step('process')
->step('do_stuff')
```

### 3. Use Appropriate Failure Policies

Match policy to step importance:

```php
// Critical steps: fail fast
->step('validate')
    ->failurePolicy(FailurePolicy::FailWorkflow)

// External calls: retry
->step('api_call')
    ->failurePolicy(FailurePolicy::RetryStep)
    ->retryable(maxAttempts: 3)

// Non-critical: skip
->step('analytics')
    ->failurePolicy(FailurePolicy::SkipStep)
```

### 4. Define Compensation for Side Effects

Any step that creates external state should have compensation:

```php
->step('reserve_inventory')
    ->compensation(ReleaseInventoryJob::class)

->step('charge_payment')
    ->compensation(RefundPaymentJob::class)
```

## Next Steps

- [Jobs](jobs.md) - Job implementation patterns
- [Typed Outputs](typed-outputs.md) - Data passing between steps
- [Step Types Guide](../guide/step-types/overview.md) - Detailed configuration
