# Single Job Steps

Single job steps execute one job per workflow execution. They are the building blocks for sequential workflow logic.

## Basic Configuration

```php
use Maestro\Workflow\Definition\Builders\WorkflowDefinitionBuilder;

$builder
    ->step('validate_order')
        ->name('Validate Order')
        ->job(ValidateOrderJob::class)
        ->build();
```

## Job Implementation

Jobs extend `OrchestratedJob` and implement the `execute()` method:

```php
<?php

declare(strict_types=1);

namespace App\Jobs\Workflow;

use Maestro\Workflow\Application\Job\OrchestratedJob;

final class ValidateOrderJob extends OrchestratedJob
{
    protected function execute(): void
    {
        // Access workflow context
        $context = $this->contextAs(OrderContext::class);

        // Access outputs from previous steps
        $previousOutput = $this->output(CartOutput::class);

        // Perform business logic
        $validationResult = $this->validateOrder($context->orderId);

        // Store output for downstream steps
        $this->store(new OrderValidationOutput(
            isValid: $validationResult->passed,
            orderId: $context->orderId,
            errors: $validationResult->errors,
        ));
    }

    private function validateOrder(string $orderId): ValidationResult
    {
        // Business logic here
    }
}
```

## Typed Outputs

### Producing Output

Declare what output a step produces:

```php
->step('validate')
    ->job(ValidateOrderJob::class)
    ->produces(OrderValidationOutput::class)
    ->build()
```

The output class must implement `StepOutput`:

```php
<?php

declare(strict_types=1);

namespace App\Outputs;

use Maestro\Workflow\Contracts\StepOutput;

final readonly class OrderValidationOutput implements StepOutput
{
    public function __construct(
        public bool $isValid,
        public string $orderId,
        public array $errors = [],
    ) {}
}
```

Store the output in your job:

```php
protected function execute(): void
{
    $result = $this->validate();

    $this->store(new OrderValidationOutput(
        isValid: $result->isValid,
        orderId: $this->context()->orderId,
        errors: $result->errors,
    ));
}
```

### Requiring Output

Declare dependencies on previous step outputs:

```php
->step('process_payment')
    ->job(ProcessPaymentJob::class)
    ->requires('validate', OrderValidationOutput::class)
    ->build()
```

Access required outputs in your job:

```php
protected function execute(): void
{
    $validation = $this->output(OrderValidationOutput::class);

    if (!$validation->isValid) {
        throw new OrderNotValidException($validation->errors);
    }

    // Process payment with validated order data
}
```

### Multiple Requirements

Steps can require outputs from multiple previous steps:

```php
->step('finalize')
    ->job(FinalizeOrderJob::class)
    ->requires('validate', OrderValidationOutput::class)
    ->requires('payment', PaymentOutput::class)
    ->requires('inventory', InventoryOutput::class)
    ->build()
```

## Failure Policies

Configure how step failures affect the workflow:

### Fail Workflow (Default)

```php
->step('critical_step')
    ->job(CriticalJob::class)
    ->failurePolicy(FailurePolicy::FailWorkflow)
    ->build()
```

Workflow immediately transitions to `Failed` state.

### Pause Workflow

```php
->step('needs_review')
    ->job(ProcessJob::class)
    ->failurePolicy(FailurePolicy::PauseWorkflow)
    ->build()
```

Workflow pauses for manual intervention. Resume via API or console.

### Retry Step

```php
->step('flaky_api')
    ->job(ExternalApiJob::class)
    ->failurePolicy(FailurePolicy::RetryStep)
    ->retryable(maxAttempts: 5, delaySeconds: 30)
    ->build()
```

Step automatically retries with configurable backoff.

### Skip Step

```php
->step('optional_notification')
    ->job(SendNotificationJob::class)
    ->failurePolicy(FailurePolicy::SkipStep)
    ->build()
```

Workflow continues to next step even if this one fails.

## Retry Configuration

Configure automatic retry behavior:

```php
->step('api_call')
    ->job(CallApiJob::class)
    ->failurePolicy(FailurePolicy::RetryStep)
    ->retryable(
        maxAttempts: 5,           // Try up to 5 times
        delaySeconds: 30,         // Initial delay: 30 seconds
        backoffMultiplier: 2.0,   // Double delay each retry
        maxDelaySeconds: 600      // Cap at 10 minutes
    )
    ->build()
```

Retry schedule example:
1. Attempt 1: Immediate
2. Attempt 2: After 30 seconds
3. Attempt 3: After 60 seconds
4. Attempt 4: After 120 seconds
5. Attempt 5: After 240 seconds

## Timeout Configuration

Set execution time limits:

```php
->step('long_process')
    ->job(LongRunningJob::class)
    ->timeout(seconds: 300)  // 5-minute limit
    ->build()
```

Jobs exceeding the timeout are marked as failed.

## Queue Configuration

Control where and how jobs are queued:

```php
->step('heavy_computation')
    ->job(HeavyJob::class)
    ->onQueue('compute-intensive')
    ->onConnection('redis')
    ->build()
```

## Conditional Execution

Execute steps only when conditions are met:

```php
->step('premium_processing')
    ->job(PremiumProcessingJob::class)
    ->condition(IsPremiumCustomerCondition::class)
    ->build()
```

Implement the condition:

```php
<?php

declare(strict_types=1);

namespace App\Conditions;

use Maestro\Workflow\Contracts\StepCondition;
use Maestro\Workflow\Contracts\StepOutputReader;
use Maestro\Workflow\Contracts\WorkflowContext;

final readonly class IsPremiumCustomerCondition implements StepCondition
{
    public function evaluate(
        WorkflowContext $context,
        StepOutputReader $outputs,
    ): bool {
        return $context->customer->isPremium;
    }
}
```

When conditions return `false`, the step is skipped with `SkipReason::ConditionFalse`.

## Compensation

Define rollback jobs for cleanup when compensation is triggered:

```php
->step('charge_payment')
    ->job(ChargePaymentJob::class)
    ->compensation(RefundPaymentJob::class)
    ->produces(PaymentOutput::class)
    ->build()
```

The compensation job receives the same context and can access the original step's output:

```php
<?php

declare(strict_types=1);

namespace App\Jobs\Compensation;

use Maestro\Workflow\Application\Job\OrchestratedJob;

final class RefundPaymentJob extends OrchestratedJob
{
    protected function execute(): void
    {
        $payment = $this->output(PaymentOutput::class);

        $this->paymentGateway->refund($payment->transactionId);
    }
}
```

## Complete Example

```php
<?php

declare(strict_types=1);

namespace App\Workflows;

use App\Conditions\IsHighValueOrder;
use App\Jobs\Workflow\{
    ValidateOrderJob,
    ProcessPaymentJob,
    AllocateInventoryJob,
    SendConfirmationJob,
    NotifyWarehouseJob,
};
use App\Jobs\Compensation\{
    RefundPaymentJob,
    ReleaseInventoryJob,
};
use App\Outputs\{
    OrderValidationOutput,
    PaymentOutput,
    InventoryAllocationOutput,
};
use Maestro\Workflow\Definition\Builders\WorkflowDefinitionBuilder;
use Maestro\Workflow\Definition\WorkflowDefinition;
use Maestro\Workflow\Enums\FailurePolicy;

final class OrderWorkflow extends WorkflowDefinition
{
    public function define(WorkflowDefinitionBuilder $builder): void
    {
        $builder
            ->name('Order Processing')
            ->version(1)
            ->contextLoader(OrderContextLoader::class)

            // Validation - fail workflow if invalid
            ->step('validate')
                ->name('Validate Order')
                ->job(ValidateOrderJob::class)
                ->produces(OrderValidationOutput::class)
                ->failurePolicy(FailurePolicy::FailWorkflow)
                ->build()

            // Payment - retry with compensation
            ->step('payment')
                ->name('Process Payment')
                ->job(ProcessPaymentJob::class)
                ->requires('validate', OrderValidationOutput::class)
                ->produces(PaymentOutput::class)
                ->failurePolicy(FailurePolicy::RetryStep)
                ->retryable(maxAttempts: 3, delaySeconds: 30)
                ->compensation(RefundPaymentJob::class)
                ->build()

            // Inventory - only for high value orders
            ->step('inventory')
                ->name('Allocate Inventory')
                ->job(AllocateInventoryJob::class)
                ->requires('payment', PaymentOutput::class)
                ->produces(InventoryAllocationOutput::class)
                ->condition(IsHighValueOrder::class)
                ->compensation(ReleaseInventoryJob::class)
                ->build()

            // Notification - skip on failure (non-critical)
            ->step('notify')
                ->name('Send Confirmation')
                ->job(SendConfirmationJob::class)
                ->requires('payment', PaymentOutput::class)
                ->failurePolicy(FailurePolicy::SkipStep)
                ->build()

            // Warehouse notification - different queue
            ->step('warehouse')
                ->name('Notify Warehouse')
                ->job(NotifyWarehouseJob::class)
                ->onQueue('warehouse-notifications')
                ->build();
    }
}
```

## Next Steps

- [Fan-Out Steps](fan-out.md) - Process collections in parallel
- [Polling Steps](polling.md) - Long-running operation patterns
- [Failure Handling](../failure-handling/overview.md) - Advanced error recovery
