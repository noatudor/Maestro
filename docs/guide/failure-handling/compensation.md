# Compensation

Compensation provides rollback capability by running cleanup jobs for completed steps when a workflow fails or is cancelled. This implements the **Saga pattern** for distributed transactions.

## How Compensation Works

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                        Compensation Flow                                     │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│   Workflow execution:                                                        │
│   Step A (✓) → Step B (✓) → Step C (✓) → Step D (✗)                        │
│      │            │            │            │                                │
│      │            │            │            ▼                                │
│      │            │            │       Failure!                              │
│      │            │            │                                             │
│   Compensation triggered (reverse order):                                    │
│      │            │            │                                             │
│      │            │            ▼                                             │
│      │            │       Compensate C                                       │
│      │            ▼                                                          │
│      │       Compensate B                                                    │
│      ▼                                                                       │
│   Compensate A                                                               │
│      │                                                                       │
│      ▼                                                                       │
│   Workflow state: Compensated                                                │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Defining Compensation Jobs

### Step Configuration

```php
->step('charge_payment')
    ->job(ChargePaymentJob::class)
    ->compensation(RefundPaymentJob::class)
    ->produces(PaymentOutput::class)
    ->build()
```

### Compensation Job Implementation

```php
<?php

declare(strict_types=1);

namespace App\Jobs\Compensation;

use App\Outputs\PaymentOutput;
use Maestro\Workflow\Application\Job\OrchestratedJob;

final class RefundPaymentJob extends OrchestratedJob
{
    protected function execute(): void
    {
        // Access the original step's output
        $payment = $this->output(PaymentOutput::class);

        // Perform the reversal
        $this->paymentGateway->refund(
            transactionId: $payment->transactionId,
            amount: $payment->amount,
        );

        // Optionally store compensation output
        $this->store(new RefundOutput(
            refundId: $refund->id,
            originalTransactionId: $payment->transactionId,
            refundedAt: now(),
        ));
    }
}
```

## Compensation Scopes

Control which steps are compensated:

### All (Default)

Compensate all completed steps in reverse order:

```php
use Maestro\Workflow\Enums\CompensationScope;

$builder
    ->failureResolution(
        FailureResolutionConfig::create()
            ->compensationScope(CompensationScope::All)
    )
```

### FailedStepOnly

Only compensate the step that failed:

```php
->compensationScope(CompensationScope::FailedStepOnly)
```

Useful when:
- Only the failed step created external state
- Other steps' effects should remain

### FromStep

Compensate from a specific step onwards:

```php
use Maestro\Workflow\ValueObjects\StepKey;

->compensationScope(CompensationScope::FromStep)
->compensationFromStep(StepKey::fromString('payment'))
```

Compensates `payment` and all subsequent completed steps.

## Compensation Configuration

### Per-Step Configuration

```php
->step('reserve_inventory')
    ->job(ReserveInventoryJob::class)
    ->compensation(
        job: ReleaseInventoryJob::class,
        timeout: 120,
        queue: 'compensation',
        retries: 3,
    )
    ->build()
```

### Compensation Retry

Compensation jobs can be retried independently:

```php
->compensation(
    job: RefundPaymentJob::class,
    retries: 5,
    retryDelay: 60,
)
```

## Triggering Compensation

### Automatic (AutoCompensate Strategy)

```php
$builder
    ->failureResolution(
        FailureResolutionConfig::create()
            ->strategy(FailureResolutionStrategy::AutoCompensate)
    )
```

### Manual via Console

```bash
# Compensate a failed workflow
php artisan maestro:compensate {workflowId}

# Compensate with specific scope
php artisan maestro:compensate {workflowId} --scope=all

# Compensate from specific step
php artisan maestro:compensate {workflowId} --from-step=payment
```

### Manual via API

```http
POST /api/maestro/workflows/{workflowId}/compensate
Content-Type: application/json

{
    "scope": "all"
}
```

### Programmatically

```php
use Maestro\Workflow\Contracts\WorkflowManager;
use Maestro\Workflow\Enums\CompensationScope;

$workflowManager->compensate(
    workflowId: $workflowId,
    scope: CompensationScope::All,
);
```

## Compensation on Cancel

Configure whether cancellation triggers compensation:

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
| `Compensate` | Run compensation before finalizing cancel |

## Compensation States

### Workflow States

```
Running → Failed → Compensating → Compensated
                       │
                       └──► CompensationFailed
```

### Compensation Run Status

```
Pending → Running → Succeeded
             │
             └──► Failed → Skipped (after manual skip)
```

## Handling Compensation Failures

When compensation fails, you have options:

### Retry Compensation

```bash
php artisan maestro:retry-compensation {workflowId}
```

### Skip Failed Compensation

```bash
# Skip specific step's compensation
php artisan maestro:skip-compensation {workflowId} --step=reserve_inventory

# Skip with reason
php artisan maestro:skip-compensation {workflowId} --step=reserve_inventory \
    --reason="Manually released inventory"
```

### Programmatically

```php
$workflowManager->retryCompensation($workflowId);

// Or skip
$workflowManager->skipCompensation(
    workflowId: $workflowId,
    stepKey: StepKey::fromString('reserve_inventory'),
    reason: 'Manually handled',
);
```

## Events

Compensation dispatches these events:

```php
// Compensation phase started
CompensationStarted::class
// Properties: workflowId, scope, stepsToCompensate

// Individual compensation step
CompensationStepStarted::class
CompensationStepSucceeded::class
CompensationStepFailed::class

// Compensation phase completed
CompensationCompleted::class  // All compensations succeeded
CompensationFailed::class     // One or more failed
```

Monitor compensation:

```php
Event::listen(CompensationStarted::class, function ($event) {
    Log::info('Compensation started', [
        'workflow_id' => $event->workflowId->value,
        'scope' => $event->scope->value,
        'steps' => $event->stepsToCompensate,
    ]);
});

Event::listen(CompensationFailed::class, function ($event) {
    Alert::critical("Compensation failed for workflow {$event->workflowId}");
});
```

## Fan-Out Compensation

For fan-out steps, compensation runs for each completed job:

```php
->fanOut('reserve_items')
    ->job(ReserveItemJob::class)
    ->items(fn($ctx, $out) => $ctx->items)
    ->compensation(ReleaseItemJob::class)
    ->build()
```

When compensation triggers:
1. Each successfully reserved item gets a `ReleaseItemJob`
2. Jobs run in parallel (respecting parallelism limits)
3. Individual failures are tracked separately

## Complete Example

```php
<?php

declare(strict_types=1);

namespace App\Workflows;

use App\Jobs\Workflow\{
    ValidateOrderJob,
    ChargePaymentJob,
    ReserveInventoryJob,
    NotifyShippingJob,
    SendConfirmationJob,
};
use App\Jobs\Compensation\{
    RefundPaymentJob,
    ReleaseInventoryJob,
    CancelShippingJob,
};
use App\Outputs\{
    OrderValidationOutput,
    PaymentOutput,
    InventoryOutput,
    ShippingOutput,
};
use Maestro\Workflow\Definition\Builders\WorkflowDefinitionBuilder;
use Maestro\Workflow\Definition\Config\FailureResolutionConfig;
use Maestro\Workflow\Definition\WorkflowDefinition;
use Maestro\Workflow\Enums\{
    CancelBehavior,
    CompensationScope,
    FailurePolicy,
    FailureResolutionStrategy,
};

final class OrderWorkflow extends WorkflowDefinition
{
    public function define(WorkflowDefinitionBuilder $builder): void
    {
        $builder
            ->name('Order Processing with Compensation')
            ->version(1)

            ->failureResolution(
                FailureResolutionConfig::create()
                    ->strategy(FailureResolutionStrategy::AwaitDecision)
                    ->compensationScope(CompensationScope::All)
                    ->cancelBehavior(CancelBehavior::Compensate)
            )

            // No compensation - validation has no side effects
            ->step('validate')
                ->job(ValidateOrderJob::class)
                ->produces(OrderValidationOutput::class)
                ->build()

            // Payment with refund compensation
            ->step('payment')
                ->job(ChargePaymentJob::class)
                ->requires('validate', OrderValidationOutput::class)
                ->produces(PaymentOutput::class)
                ->compensation(RefundPaymentJob::class)
                ->failurePolicy(FailurePolicy::RetryStep)
                ->retryable(maxAttempts: 3)
                ->build()

            // Inventory with release compensation
            ->step('inventory')
                ->job(ReserveInventoryJob::class)
                ->requires('validate', OrderValidationOutput::class)
                ->produces(InventoryOutput::class)
                ->compensation(ReleaseInventoryJob::class)
                ->build()

            // Shipping with cancel compensation
            ->step('shipping')
                ->job(NotifyShippingJob::class)
                ->requires('inventory', InventoryOutput::class)
                ->produces(ShippingOutput::class)
                ->compensation(CancelShippingJob::class)
                ->build()

            // No compensation - notification can't be "un-sent"
            ->step('confirmation')
                ->job(SendConfirmationJob::class)
                ->requires('payment', PaymentOutput::class)
                ->requires('shipping', ShippingOutput::class)
                ->failurePolicy(FailurePolicy::SkipStep)
                ->build();
    }
}
```

## Best Practices

### 1. Define Compensation for External Side Effects

Any step that modifies external state should have compensation:

```php
// Good: Reversible operations have compensation
->step('reserve_inventory')
    ->job(ReserveInventoryJob::class)
    ->compensation(ReleaseInventoryJob::class)
    ->build()

// OK: Read-only operations don't need compensation
->step('validate')
    ->job(ValidateOrderJob::class)
    ->build()
```

### 2. Make Compensation Idempotent

Compensation jobs may run multiple times (retries):

```php
protected function execute(): void
{
    // Idempotent: Check if already refunded
    if ($this->paymentGateway->isRefunded($this->transactionId)) {
        return; // Already compensated
    }

    $this->paymentGateway->refund($this->transactionId);
}
```

### 3. Log Compensation Actions

Track what was undone for audit:

```php
protected function execute(): void
{
    $payment = $this->output(PaymentOutput::class);

    $refund = $this->paymentGateway->refund($payment->transactionId);

    Log::info('Payment refunded', [
        'workflow_id' => $this->workflowId()->value,
        'transaction_id' => $payment->transactionId,
        'refund_id' => $refund->id,
    ]);

    $this->store(new RefundOutput(
        refundId: $refund->id,
        originalTransactionId: $payment->transactionId,
    ));
}
```

### 4. Handle Partial Compensation

Some compensations may not be possible:

```php
protected function execute(): void
{
    $shipping = $this->output(ShippingOutput::class);

    if ($shipping->status === 'delivered') {
        // Can't cancel delivered shipment
        throw new CompensationNotPossibleException(
            "Shipment {$shipping->id} already delivered"
        );
    }

    $this->shippingService->cancel($shipping->id);
}
```

### 5. Test Compensation Paths

Write tests for compensation scenarios:

```php
it('refunds payment when inventory allocation fails', function () {
    $workflow = startWorkflow(OrderWorkflow::class);

    // Mock inventory failure
    $this->mock(InventoryService::class)
        ->shouldReceive('reserve')
        ->andThrow(new InsufficientInventoryException());

    // Process workflow
    processWorkflow($workflow);

    // Trigger compensation
    $this->workflowManager->compensate($workflow->id);

    // Verify refund was issued
    expect($this->paymentGateway->wasRefunded($workflow->paymentId))
        ->toBeTrue();
});
```

## Next Steps

- [Recovery Operations](recovery.md) - Advanced recovery scenarios
- [Workflow Resolution](workflow-resolution.md) - Resolution strategies
- [Events Reference](../../operations/events.md) - Compensation events
