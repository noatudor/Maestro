# Jobs

Jobs are the executable units within workflow steps. Every step dispatches one or more jobs to perform actual work.

## Job Basics

Jobs extend `OrchestratedJob` and implement the `execute()` method:

```php
<?php

declare(strict_types=1);

namespace App\Jobs\Workflow;

use Maestro\Workflow\Application\Job\OrchestratedJob;

final class ProcessOrderJob extends OrchestratedJob
{
    protected function execute(): void
    {
        // Your business logic here
    }
}
```

## Job Lifecycle

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                            Job Lifecycle                                     │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│   ┌─────────────────┐                                                       │
│   │  Step Dispatch  │                                                       │
│   └────────┬────────┘                                                       │
│            │                                                                 │
│            ▼                                                                 │
│   ┌─────────────────┐     ┌───────────────────────────────────────────────┐│
│   │  Job Queued     │────▶│ Queue (Redis, SQS, Database)                  ││
│   └─────────────────┘     └────────────────────┬──────────────────────────┘│
│                                                │                            │
│                                                │ Worker picks up            │
│                                                ▼                            │
│   ┌─────────────────────────────────────────────────────────────────────┐  │
│   │  Job Middleware Pipeline                                             │  │
│   │  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐               │  │
│   │  │   Context    │─▶│  Lifecycle   │─▶│  Idempotency │               │  │
│   │  │   Loading    │  │  Tracking    │  │    Check     │               │  │
│   │  └──────────────┘  └──────────────┘  └──────────────┘               │  │
│   └────────────────────────────┬────────────────────────────────────────┘  │
│                                │                                            │
│                                ▼                                            │
│   ┌─────────────────────────────────────────────────────────────────────┐  │
│   │  execute()                                                           │  │
│   │  • Access context: $this->contextAs(OrderContext::class)             │  │
│   │  • Access outputs: $this->output(PreviousOutput::class)              │  │
│   │  • Store output:   $this->store(new MyOutput(...))                   │  │
│   └────────────────────────────┬────────────────────────────────────────┘  │
│                                │                                            │
│                           ┌────┴────┐                                       │
│                           │         │                                       │
│                           ▼         ▼                                       │
│                      Success     Exception                                  │
│                           │         │                                       │
│                           ▼         ▼                                       │
│   ┌─────────────────┐  ┌─────────────────┐                                 │
│   │  JobSucceeded   │  │   JobFailed     │                                 │
│   │  event          │  │   event         │                                 │
│   └────────┬────────┘  └────────┬────────┘                                 │
│            │                    │                                           │
│            └──────────┬─────────┘                                           │
│                       ▼                                                     │
│   ┌─────────────────────────────────────────────────────────────────────┐  │
│   │  Step Finalizer                                                      │  │
│   │  • Update step state                                                 │  │
│   │  • Apply failure policy                                              │  │
│   │  • Advance workflow                                                  │  │
│   └─────────────────────────────────────────────────────────────────────┘  │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Accessing Workflow Data

### Workflow Context

Shared data across all steps:

```php
protected function execute(): void
{
    // Generic access
    $context = $this->context();

    // Type-safe access (recommended)
    $context = $this->contextAs(OrderContext::class);

    $this->processOrder($context->orderId, $context->customerId);
}
```

### Step Outputs

Data from previous steps:

```php
protected function execute(): void
{
    // Required output (throws if missing)
    $validation = $this->output(ValidationOutput::class);

    // Optional output (returns null if missing)
    $optional = $this->outputOrNull(OptionalOutput::class);

    if ($validation->isValid) {
        // Process...
    }
}
```

### Workflow Metadata

Additional workflow data:

```php
protected function execute(): void
{
    $metadata = $this->workflowMetadata();
    $source = $metadata['source'] ?? 'unknown';
}
```

### Workflow ID

Access the current workflow's ID:

```php
protected function execute(): void
{
    $workflowId = $this->workflowId();

    Log::info('Processing', ['workflow_id' => $workflowId->value]);
}
```

## Storing Output

Store output for downstream steps:

```php
protected function execute(): void
{
    $result = $this->processPayment();

    $this->store(new PaymentOutput(
        transactionId: $result->id,
        amount: $result->amount,
        processedAt: now()->toImmutable(),
    ));
}
```

Output classes must implement `StepOutput`:

```php
<?php

declare(strict_types=1);

namespace App\Outputs;

use Maestro\Workflow\Contracts\StepOutput;

final readonly class PaymentOutput implements StepOutput
{
    public function __construct(
        public string $transactionId,
        public float $amount,
        public \DateTimeImmutable $processedAt,
    ) {}
}
```

## Job Types

### Standard Job

For single-job steps:

```php
final class ProcessPaymentJob extends OrchestratedJob
{
    protected function execute(): void
    {
        // Process payment
    }
}
```

### Fan-Out Job

Receives per-item arguments:

```php
final class ProcessItemJob extends OrchestratedJob
{
    public function __construct(
        public readonly Item $item,
        public readonly int $index,
    ) {}

    protected function execute(): void
    {
        // Process this specific item
        $this->process($this->item);

        // Store individual result (will be aggregated)
        $this->store(new ItemResultOutput(
            itemId: $this->item->id,
            status: 'processed',
        ));
    }
}
```

### Polling Job

Returns `PollResult`:

```php
use Maestro\Workflow\Application\Job\PollingJob;
use Maestro\Workflow\Contracts\PollResult;
use Maestro\Workflow\ValueObjects\{
    CompletedPollResult,
    ContinuePollResult,
    AbortedPollResult,
};

final class CheckPaymentStatusJob extends PollingJob
{
    protected function poll(): PollResult
    {
        $status = $this->paymentService->check($this->transactionId);

        return match ($status) {
            'confirmed' => new CompletedPollResult(
                output: new PaymentConfirmedOutput($this->transactionId),
            ),
            'pending' => new ContinuePollResult(
                message: 'Still pending',
            ),
            'failed' => new AbortedPollResult(
                reason: 'Payment was declined',
            ),
        };
    }
}
```

### Compensation Job

Runs during rollback:

```php
final class RefundPaymentJob extends OrchestratedJob
{
    protected function execute(): void
    {
        // Access original step's output
        $payment = $this->output(PaymentOutput::class);

        // Idempotent check
        if ($this->paymentService->isRefunded($payment->transactionId)) {
            return;
        }

        $this->paymentService->refund($payment->transactionId);
    }
}
```

## Dependency Injection

Inject services via constructor:

```php
final class ProcessPaymentJob extends OrchestratedJob
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly AuditLogger $logger,
    ) {}

    protected function execute(): void
    {
        $context = $this->contextAs(OrderContext::class);

        $result = $this->gateway->charge(
            amount: $context->totalAmount,
            customerId: $context->customerId,
        );

        $this->logger->log('payment_processed', [
            'workflow_id' => $this->workflowId()->value,
            'transaction_id' => $result->id,
        ]);

        $this->store(new PaymentOutput(...));
    }
}
```

## Error Handling

### Throwing Exceptions

Throw exceptions to fail the job:

```php
protected function execute(): void
{
    $result = $this->gateway->charge(...);

    if (!$result->success) {
        throw new PaymentFailedException($result->errorMessage);
    }
}
```

### Custom Exception Handling

Handle specific exceptions:

```php
protected function execute(): void
{
    try {
        $this->externalApi->call();
    } catch (RateLimitException $e) {
        // Re-throw to trigger retry
        throw $e;
    } catch (InvalidDataException $e) {
        // Log and fail permanently
        Log::error('Invalid data', ['error' => $e->getMessage()]);
        throw new UnrecoverableException($e->getMessage());
    }
}
```

## Idempotency

Design jobs to handle re-execution safely:

```php
protected function execute(): void
{
    $context = $this->contextAs(OrderContext::class);

    // Check if already processed
    $existing = Order::where('external_id', $context->externalOrderId)->first();

    if ($existing) {
        // Already processed - store output and return
        $this->store(new OrderOutput(
            orderId: $existing->id,
            alreadyExisted: true,
        ));
        return;
    }

    // Process normally
    $order = Order::create([
        'external_id' => $context->externalOrderId,
        // ...
    ]);

    $this->store(new OrderOutput(
        orderId: $order->id,
        alreadyExisted: false,
    ));
}
```

## Job Events

Jobs emit events during execution:

```php
JobDispatched::class  // Job queued
JobStarted::class     // Job execution started
JobSucceeded::class   // Job completed successfully
JobFailed::class      // Job threw exception
```

Listen to events:

```php
Event::listen(JobFailed::class, function ($event) {
    Log::error('Job failed', [
        'workflow_id' => $event->workflowId->value,
        'step_key' => $event->stepKey->value,
        'error' => $event->exception->getMessage(),
    ]);
});
```

## Testing Jobs

### Unit Testing

```php
<?php

use App\Jobs\Workflow\ProcessPaymentJob;
use App\Outputs\PaymentOutput;

it('processes payment successfully', function () {
    // Arrange
    $gateway = Mockery::mock(PaymentGateway::class);
    $gateway->shouldReceive('charge')
        ->once()
        ->andReturn(new ChargeResult(id: 'txn-123', success: true));

    $job = new ProcessPaymentJob($gateway);

    // Inject test context
    setJobContext($job, new OrderContext(
        orderId: 'order-1',
        customerId: 'cust-1',
        totalAmount: 100.00,
    ));

    // Act
    $job->execute();

    // Assert
    $output = getJobOutput($job, PaymentOutput::class);
    expect($output->transactionId)->toBe('txn-123');
});
```

### Integration Testing

```php
it('completes workflow with payment step', function () {
    // Start workflow
    $workflow = startWorkflow(OrderWorkflow::class, [
        'order' => Order::factory()->create(),
    ]);

    // Process
    processWorkflow($workflow);

    // Assert
    expect($workflow->fresh()->state)->toBe(WorkflowState::Succeeded);
    expect($this->getOutput(PaymentOutput::class))->not->toBeNull();
});
```

## Best Practices

### 1. Keep Jobs Focused

Each job should do one thing:

```php
// Good
final class ValidateOrderJob extends OrchestratedJob { ... }
final class ProcessPaymentJob extends OrchestratedJob { ... }
final class SendConfirmationJob extends OrchestratedJob { ... }

// Bad
final class DoEverythingJob extends OrchestratedJob { ... }
```

### 2. Make Jobs Idempotent

Jobs may run multiple times - design for it:

```php
// Check before creating
if (!$this->alreadyExists($id)) {
    $this->create($id);
}
```

### 3. Use Type-Safe Context Access

```php
// Good
$context = $this->contextAs(OrderContext::class);
$orderId = $context->orderId;  // IDE autocomplete works

// Avoid
$context = $this->context();
$orderId = $context->orderId;  // No type safety
```

### 4. Log Important Actions

```php
protected function execute(): void
{
    Log::info('Processing payment', [
        'workflow_id' => $this->workflowId()->value,
        'amount' => $context->amount,
    ]);

    // Process...

    Log::info('Payment processed', [
        'workflow_id' => $this->workflowId()->value,
        'transaction_id' => $result->id,
    ]);
}
```

### 5. Handle Failures Gracefully

```php
protected function execute(): void
{
    try {
        $this->process();
    } catch (TemporaryException $e) {
        // Will retry
        throw $e;
    } catch (PermanentException $e) {
        // Log details for debugging
        Log::error('Permanent failure', [
            'workflow_id' => $this->workflowId()->value,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        throw $e;
    }
}
```

## Next Steps

- [Typed Outputs](typed-outputs.md) - Data passing patterns
- [Failure Handling](../guide/failure-handling/overview.md) - Error recovery
- [Testing](../advanced/testing.md) - Testing strategies
