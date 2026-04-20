# Recovery Operations

Recovery operations allow operators to manually intervene in failed workflows. These operations go beyond simple retry or compensation to handle complex recovery scenarios.

## Retry from Step

Retry a workflow from a specific step, superseding all subsequent step runs.

### Use Case

When a workflow failed partway through, but you need to restart from an earlier point:

```
Executed: Step A (✓) → Step B (✓) → Step C (✓) → Step D (✗)

Retry from Step B:
- Supersede Step B, C, D runs
- Re-execute from Step B
```

### Console Command

```bash
# Retry from specific step
php artisan maestro:retry-from-step {workflowId} {stepKey}

# Retry from step with compensation
php artisan maestro:retry-from-step {workflowId} {stepKey} --compensate

# Example
php artisan maestro:retry-from-step abc-123 payment
```

### API

```http
POST /api/maestro/workflows/{workflowId}/retry-from-step
Content-Type: application/json

{
    "step_key": "payment",
    "compensate_intermediate_steps": false
}
```

### Programmatically

```php
use Maestro\Workflow\Contracts\WorkflowManager;
use Maestro\Workflow\ValueObjects\{RetryFromStepRequest, StepKey, WorkflowId};
use Maestro\Workflow\Enums\RetryMode;

$request = new RetryFromStepRequest(
    workflowId: WorkflowId::fromString('abc-123'),
    stepKey: StepKey::fromString('payment'),
    retryMode: RetryMode::RetryOnly,  // or CompensateThenRetry
);

$result = $workflowManager->retryFromStep($request);
```

### Retry Modes

#### RetryOnly (Default)

Simply supersede and restart:

```php
RetryMode::RetryOnly
```

Steps between retry point and failure are marked as `Superseded` but not compensated.

**Use when:**
- Steps are idempotent
- External state doesn't need cleanup
- Speed is important

#### CompensateThenRetry

Run compensation for intermediate steps before retrying:

```php
RetryMode::CompensateThenRetry
```

```
1. Compensate Step D (failed)
2. Compensate Step C
3. Compensate Step B
4. Mark all as Superseded
5. Re-execute from Step B
```

**Use when:**
- Steps created external state that needs cleanup
- Idempotency can't be guaranteed
- Clean slate is required

## Superseding Step Runs

When retrying, original step runs are marked as `Superseded`:

```php
// Original run
StepRun {
    id: 'run-001',
    stepKey: 'payment',
    state: 'failed',
    supersededBy: 'run-002',  // Points to new run
    supersededAt: '2024-01-15 10:30:00',
}

// New run
StepRun {
    id: 'run-002',
    stepKey: 'payment',
    state: 'running',
    supersedes: 'run-001',  // Points to original
}
```

Query supersession history:

```php
$stepRuns = $stepRunRepository->findByWorkflowId($workflowId);

$currentRuns = $stepRuns->filter(
    fn($run) => $run->supersededBy === null
);

$supersededRuns = $stepRuns->filter(
    fn($run) => $run->supersededBy !== null
);
```

## Resolution Workflow

Complete operator workflow for recovering a failed workflow:

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                        Recovery Decision Flow                                │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│   Workflow Failed                                                            │
│         │                                                                    │
│         ▼                                                                    │
│   ┌───────────────────┐                                                     │
│   │ Investigate cause │                                                     │
│   └─────────┬─────────┘                                                     │
│             │                                                                │
│   ┌─────────┼─────────┬─────────────┬─────────────┐                         │
│   │         │         │             │             │                         │
│   ▼         ▼         ▼             ▼             ▼                         │
│ Transient  Data      Logic        External    Permanent                     │
│ failure    issue     bug          dependency  failure                       │
│   │         │         │             │             │                         │
│   │         │         │             │             │                         │
│   ▼         ▼         ▼             ▼             ▼                         │
│ Retry    Fix data,  Deploy fix,  Wait for    Compensate                     │
│          retry from retry from   service,    & cancel                       │
│          step       step         then retry                                 │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Example Scenarios

#### Scenario 1: Transient API Failure

```
Diagnosis: Third-party API was temporarily down
Action: Simple retry
```

```bash
php artisan maestro:resolve {workflowId} --decision=retry
```

#### Scenario 2: Data Issue

```
Diagnosis: Invalid customer email caused notification failure
Action: Fix data, retry from notification step
```

```bash
# Fix the data first
php artisan app:fix-customer-email {customerId}

# Retry from the notification step
php artisan maestro:retry-from-step {workflowId} notification
```

#### Scenario 3: Logic Bug Fixed

```
Diagnosis: Bug in payment processing, now deployed fix
Action: Compensate completed payment, retry from payment step
```

```bash
php artisan maestro:retry-from-step {workflowId} payment --compensate
```

#### Scenario 4: Unrecoverable Failure

```
Diagnosis: Customer cancelled order during processing
Action: Compensate all steps and cancel
```

```bash
php artisan maestro:compensate {workflowId}
php artisan maestro:cancel {workflowId}
```

## Console Commands Reference

### Process Auto-Retries

```bash
# Process all pending auto-retries
php artisan maestro:process-auto-retries
```

### Process Scheduled Resumes

```bash
# Resume workflows with scheduled resume times
php artisan maestro:process-scheduled-resumes
```

### Check Trigger Timeouts

```bash
# Handle workflows waiting for triggers that have timed out
php artisan maestro:check-trigger-timeouts
```

### Resolve Workflow

```bash
# Make a resolution decision
php artisan maestro:resolve {workflowId} --decision={decision}

# Decisions: retry, compensate, cancel, mark-resolved
php artisan maestro:resolve abc-123 --decision=retry
php artisan maestro:resolve abc-123 --decision=compensate
php artisan maestro:resolve abc-123 --decision=cancel
php artisan maestro:resolve abc-123 --decision=mark-resolved --reason="Fixed manually"
```

### Retry Compensation

```bash
# Retry failed compensation
php artisan maestro:retry-compensation {workflowId}
```

### Skip Compensation

```bash
# Skip compensation for a specific step
php artisan maestro:skip-compensation {workflowId} --step={stepKey}
php artisan maestro:skip-compensation abc-123 --step=payment --reason="Already refunded manually"
```

## Events

Recovery operations dispatch these events:

```php
// Retry from step
RetryFromStepInitiated::class
// Properties: workflowId, stepKey, retryMode, supersededSteps

RetryFromStepCompleted::class
// Properties: workflowId, stepKey, newStepRunId

// Resolution decisions
ResolutionDecisionMade::class
// Properties: workflowId, decision, reason, madeBy

// Supersession
StepRunSuperseded::class
// Properties: workflowId, stepRunId, supersededBy, reason
```

Monitor recovery operations:

```php
Event::listen(RetryFromStepInitiated::class, function ($event) {
    AuditLog::record([
        'action' => 'retry_from_step',
        'workflow_id' => $event->workflowId->value,
        'step_key' => $event->stepKey->value,
        'mode' => $event->retryMode->value,
        'operator' => auth()->user()?->email,
    ]);
});
```

## Querying Recovery State

### Find Workflows Needing Attention

```php
// Workflows awaiting resolution
$awaiting = WorkflowModel::query()
    ->where('state', WorkflowState::Failed->value)
    ->whereNull('auto_retry_scheduled_at')
    ->orderBy('failed_at')
    ->get();

// Workflows with failed compensation
$compensationFailed = WorkflowModel::query()
    ->where('state', WorkflowState::CompensationFailed->value)
    ->get();

// Workflows stuck in compensating
$stuckCompensating = WorkflowModel::query()
    ->where('state', WorkflowState::Compensating->value)
    ->where('updated_at', '<', now()->subHours(2))
    ->get();
```

### Resolution History

```php
// Get all resolution decisions for a workflow
$decisions = $resolutionDecisionRepository->findByWorkflowId($workflowId);

foreach ($decisions as $decision) {
    echo "{$decision->createdAt}: {$decision->decision->value}";
    echo " - {$decision->reason}\n";
}
```

## Best Practices

### 1. Document Recovery Procedures

Create runbooks for common failure scenarios:

```markdown
## API Timeout Recovery

**Symptoms:** Step failed with "Connection timeout" error
**Diagnosis:** Check if API is responsive
**Recovery:**
1. Verify API is back online
2. Run: `php artisan maestro:resolve {id} --decision=retry`
```

### 2. Use Audit Logging

Track all recovery actions:

```php
Event::listen(ResolutionDecisionMade::class, function ($event) {
    AuditLog::create([
        'entity_type' => 'workflow',
        'entity_id' => $event->workflowId->value,
        'action' => "resolution:{$event->decision->value}",
        'reason' => $event->reason,
        'user_id' => auth()->id(),
        'ip_address' => request()->ip(),
    ]);
});
```

### 3. Automate Where Possible

Use auto-retry for transient failures:

```php
->failureResolution(
    FailureResolutionConfig::autoRetry()
        ->withAutoRetryConfig(new AutoRetryConfig(
            maxRetries: 3,
            delaySeconds: 300,
            fallbackStrategy: FailureResolutionStrategy::AwaitDecision,
        ))
)
```

### 4. Set Up Alerting

Alert on workflows needing manual intervention:

```php
$schedule->call(function () {
    $staleFailures = WorkflowModel::query()
        ->where('state', WorkflowState::Failed->value)
        ->whereNull('auto_retry_scheduled_at')
        ->where('failed_at', '<', now()->subHour())
        ->count();

    if ($staleFailures > 0) {
        Alert::send("{$staleFailures} workflows awaiting resolution");
    }
})->hourly();
```

### 5. Test Recovery Paths

Include recovery scenarios in tests:

```php
it('recovers from payment failure via retry from step', function () {
    // Setup: Workflow failed at step 3
    $workflow = createFailedWorkflow(failedAt: 'payment');

    // Fix the underlying issue
    $this->mockPaymentSuccess();

    // Execute recovery
    $result = $this->workflowManager->retryFromStep(
        new RetryFromStepRequest(
            workflowId: $workflow->id,
            stepKey: StepKey::fromString('payment'),
        )
    );

    // Verify recovery
    expect($result->success)->toBeTrue();

    // Process the workflow
    processWorkflow($workflow);

    // Verify completion
    expect($workflow->fresh()->state)->toBe(WorkflowState::Succeeded);
});
```

## Next Steps

- [Console Commands](../../operations/console-commands.md) - Complete command reference
- [Events Reference](../../operations/events.md) - All recovery events
- [Compensation](compensation.md) - Rollback patterns
