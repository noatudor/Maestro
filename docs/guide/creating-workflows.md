# Creating Workflows

This guide covers how to create and configure workflow definitions in Maestro.

## Workflow Definition Basics

A workflow definition describes the structure and behavior of a workflow. It includes:

- A unique identifier (definition key)
- A version for tracking changes
- A sequence of steps to execute
- Optional configuration (context loader, timeout settings)

## Using the Workflow Builder

The `WorkflowDefinitionBuilder` provides a fluent interface for creating workflows:

```php
use Maestro\Workflow\Definition\Builders\WorkflowDefinitionBuilder;
use Maestro\Workflow\Definition\Builders\SingleJobStepBuilder;

$workflow = WorkflowDefinitionBuilder::create('user-onboarding')
    ->version('2.0.0')
    ->displayName('User Onboarding Workflow')
    ->singleJob('create-account', fn (SingleJobStepBuilder $step) => $step
        ->displayName('Create Account')
        ->job(CreateAccountJob::class))
    ->singleJob('send-welcome-email', fn (SingleJobStepBuilder $step) => $step
        ->displayName('Send Welcome Email')
        ->job(SendWelcomeEmailJob::class))
    ->singleJob('setup-defaults', fn (SingleJobStepBuilder $step) => $step
        ->displayName('Setup Default Settings')
        ->job(SetupDefaultsJob::class))
    ->build();
```

## Step Types

Maestro supports multiple step types:

### Single Job Steps

Execute a single job instance:

```php
->singleJob('process-payment', fn (SingleJobStepBuilder $step) => $step
    ->displayName('Process Payment')
    ->job(ProcessPaymentJob::class)
    ->failWorkflow()) // Fail workflow on error
```

### Fan-Out Steps

Execute multiple jobs in parallel for each item in a collection:

```php
use Maestro\Workflow\Definition\Builders\FanOutStepBuilder;

->fanOut('process-line-items', fn (FanOutStepBuilder $step) => $step
    ->displayName('Process Line Items')
    ->job(ProcessLineItemJob::class)
    ->iterateOver(fn ($context, $outputs) => $outputs->get(OrderOutput::class)->lineItems)
    ->withJobArguments(fn ($item) => ['lineItem' => $item])
    ->requireAllSuccess())
```

## Data Passing Between Steps

### Defining Output Types

Create output classes that implement `StepOutput`:

```php
use Maestro\Workflow\Contracts\StepOutput;
use Maestro\Workflow\ValueObjects\StepKey;

final readonly class OrderValidationOutput implements StepOutput
{
    public function __construct(
        public bool $isValid,
        public string $orderId,
        public array $validatedItems,
    ) {}

    public function stepKey(): StepKey
    {
        return StepKey::fromString('validate-order');
    }
}
```

### Producing Output

Configure a step to produce output:

```php
->singleJob('validate-order', fn (SingleJobStepBuilder $step) => $step
    ->job(ValidateOrderJob::class)
    ->produces(OrderValidationOutput::class))
```

Write output in your job:

```php
final class ValidateOrderJob extends OrchestratedJob
{
    protected function execute(): void
    {
        $validation = $this->validateOrder();

        $this->outputs()?->put(new OrderValidationOutput(
            isValid: $validation->passed,
            orderId: $this->orderId,
            validatedItems: $validation->items,
        ));
    }
}
```

### Requiring Output

Configure a step to require output from a previous step:

```php
->singleJob('process-order', fn (SingleJobStepBuilder $step) => $step
    ->job(ProcessOrderJob::class)
    ->requires(OrderValidationOutput::class))
```

Access required output in your job:

```php
final class ProcessOrderJob extends OrchestratedJob
{
    protected function execute(): void
    {
        $validation = $this->outputs()?->get(OrderValidationOutput::class);

        if (!$validation?->isValid) {
            throw new InvalidOrderException();
        }

        foreach ($validation->validatedItems as $item) {
            $this->processItem($item);
        }
    }
}
```

## Workflow Context

### Defining a Context Loader

Create a context loader to provide workflow-specific data:

```php
use Maestro\Workflow\Contracts\ContextLoader;
use Maestro\Workflow\Contracts\WorkflowContext;
use Maestro\Workflow\ValueObjects\WorkflowId;

final class OrderContextLoader implements ContextLoader
{
    public function __construct(
        private OrderRepository $orderRepository,
    ) {}

    public function load(WorkflowId $workflowId): WorkflowContext
    {
        $order = $this->orderRepository->findByWorkflowId($workflowId);

        return new OrderWorkflowContext(
            orderId: $order->id,
            customerId: $order->customer_id,
            items: $order->items,
        );
    }
}
```

### Using Context in Workflows

Configure the context loader:

```php
$workflow = WorkflowDefinitionBuilder::create('order-processing')
    ->contextLoader(OrderContextLoader::class)
    ->singleJob('process', fn ($step) => $step->job(ProcessOrderJob::class))
    ->build();
```

Access context in jobs:

```php
final class ProcessOrderJob extends OrchestratedJob
{
    protected function execute(): void
    {
        // Generic context access
        $context = $this->context();

        // Type-safe context access
        $orderContext = $this->contextAs(OrderWorkflowContext::class);

        $this->processOrder($orderContext->orderId);
    }
}
```

## Workflow Versioning

Track workflow changes with versions:

```php
$v1 = WorkflowDefinitionBuilder::create('order-processing')
    ->version('1.0.0')
    // Original steps
    ->build();

$v2 = WorkflowDefinitionBuilder::create('order-processing')
    ->version('2.0.0')
    // Updated steps
    ->build();

// Register both versions
$registry->register($v1);
$registry->register($v2);

// Start specific version
$instance = Maestro::startWorkflow(
    DefinitionKey::fromString('order-processing'),
    // Uses latest version by default
);
```

## Complete Example

```php
<?php

namespace App\Workflows;

use App\Jobs\Workflow\CreateOrderJob;
use App\Jobs\Workflow\ProcessPaymentJob;
use App\Jobs\Workflow\FulfillLineItemJob;
use App\Jobs\Workflow\SendConfirmationJob;
use App\Outputs\OrderCreatedOutput;
use App\Outputs\PaymentOutput;
use App\Context\OrderContextLoader;
use Maestro\Workflow\Definition\Builders\SingleJobStepBuilder;
use Maestro\Workflow\Definition\Builders\FanOutStepBuilder;
use Maestro\Workflow\Definition\Builders\WorkflowDefinitionBuilder;
use Maestro\Workflow\Definition\WorkflowDefinition;

final class OrderFulfillmentWorkflow
{
    public static function definition(): WorkflowDefinition
    {
        return WorkflowDefinitionBuilder::create('order-fulfillment')
            ->version('1.0.0')
            ->displayName('Order Fulfillment Workflow')
            ->contextLoader(OrderContextLoader::class)

            // Step 1: Create the order record
            ->singleJob('create-order', fn (SingleJobStepBuilder $step) => $step
                ->displayName('Create Order')
                ->job(CreateOrderJob::class)
                ->produces(OrderCreatedOutput::class)
                ->failWorkflow())

            // Step 2: Process payment
            ->singleJob('process-payment', fn (SingleJobStepBuilder $step) => $step
                ->displayName('Process Payment')
                ->job(ProcessPaymentJob::class)
                ->requires(OrderCreatedOutput::class)
                ->produces(PaymentOutput::class)
                ->retryStep()
                ->retry(maxAttempts: 3, delaySeconds: 30))

            // Step 3: Fulfill each line item in parallel
            ->fanOut('fulfill-items', fn (FanOutStepBuilder $step) => $step
                ->displayName('Fulfill Line Items')
                ->job(FulfillLineItemJob::class)
                ->requires(OrderCreatedOutput::class)
                ->iterateOver(fn ($ctx, $out) => $out->get(OrderCreatedOutput::class)->lineItems)
                ->withJobArguments(fn ($item) => ['item' => $item])
                ->requireAllSuccess()
                ->onQueue('fulfillment'))

            // Step 4: Send confirmation
            ->singleJob('send-confirmation', fn (SingleJobStepBuilder $step) => $step
                ->displayName('Send Confirmation')
                ->job(SendConfirmationJob::class)
                ->requires(OrderCreatedOutput::class, PaymentOutput::class)
                ->skipStep()) // Non-critical, skip on failure

            ->build();
    }
}
```

## Next Steps

- [Defining Steps](defining-steps.md) - Detailed step configuration options
- [Fan-Out & Fan-In](fan-out-fan-in.md) - Parallel processing patterns
- [Failure Handling](failure-handling.md) - Error handling strategies
