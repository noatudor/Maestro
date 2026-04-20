# Workflows

A **workflow** is a coordinated sequence of steps that together accomplish a business goal. Workflows provide reliability, visibility, and recoverability for complex operations.

## Workflow Anatomy

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                            Workflow Structure                                │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│   Workflow Definition (immutable blueprint)                                  │
│   ┌───────────────────────────────────────────────────────────────────────┐ │
│   │ • Definition Key: "order-processing"                                   │ │
│   │ • Version: 1                                                           │ │
│   │ • Steps: [validate, payment, fulfill, notify]                          │ │
│   │ • Context Loader: OrderContextLoader::class                            │ │
│   │ • Failure Resolution: AutoRetry with fallback                          │ │
│   └───────────────────────────────────────────────────────────────────────┘ │
│                                    │                                         │
│                                    │ instantiate                             │
│                                    ▼                                         │
│   Workflow Instance (runtime state)                                          │
│   ┌───────────────────────────────────────────────────────────────────────┐ │
│   │ • ID: "abc-123-def-456"                                                │ │
│   │ • State: Running                                                       │ │
│   │ • Current Step: "payment"                                              │ │
│   │ • Created: 2024-01-15 10:00:00                                         │ │
│   │ • Step Runs: [validate✓, payment→, fulfill○, notify○]                  │ │
│   └───────────────────────────────────────────────────────────────────────┘ │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Workflow Definition

A workflow definition is an immutable blueprint that describes:
- What steps to execute
- In what order (dependencies)
- How to handle failures
- What data flows between steps

### Creating a Definition

```php
<?php

declare(strict_types=1);

namespace App\Workflows;

use Maestro\Workflow\Definition\Builders\WorkflowDefinitionBuilder;
use Maestro\Workflow\Definition\WorkflowDefinition;

final class OrderWorkflow extends WorkflowDefinition
{
    public function define(WorkflowDefinitionBuilder $builder): void
    {
        $builder
            ->name('Order Processing')
            ->version(1)
            ->contextLoader(OrderContextLoader::class)

            ->step('validate')
                ->name('Validate Order')
                ->job(ValidateOrderJob::class)
                ->produces(ValidationOutput::class)
                ->build()

            ->step('payment')
                ->name('Process Payment')
                ->job(ProcessPaymentJob::class)
                ->requires('validate', ValidationOutput::class)
                ->build();
    }
}
```

### Registering Definitions

Register workflows in a service provider:

```php
<?php

namespace App\Providers;

use App\Workflows\OrderWorkflow;
use Illuminate\Support\ServiceProvider;
use Maestro\Workflow\Definition\WorkflowDefinitionRegistry;

final class WorkflowServiceProvider extends ServiceProvider
{
    public function boot(WorkflowDefinitionRegistry $registry): void
    {
        $registry->register(new OrderWorkflow());
    }
}
```

## Workflow Instance

A workflow instance is a running (or completed) execution of a workflow definition.

### Starting a Workflow

```php
use Maestro\Workflow\Maestro;
use Maestro\Workflow\ValueObjects\DefinitionKey;

$workflow = Maestro::startWorkflow(
    DefinitionKey::fromString('order-processing'),
);

echo "Started workflow: {$workflow->id->value}";
// Started workflow: 018e0e5a-4b3c-7d1e-8f00-1234567890ab
```

### Checking Status

```php
$status = Maestro::getStatus($workflow->id);

echo "State: {$status->state->value}";           // running
echo "Current: {$status->currentStepKey->value}"; // payment
echo "Started: {$status->startedAt}";             // 2024-01-15 10:00:00
```

### Getting Detailed Information

```php
$detail = Maestro::getDetail($workflow->id);

foreach ($detail->steps as $step) {
    echo "{$step->key}: {$step->state->value}\n";
    foreach ($step->jobs as $job) {
        echo "  Job {$job->id}: {$job->state->value}\n";
    }
}
```

## Workflow States

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                         Workflow State Machine                               │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│                           ┌─────────┐                                       │
│                           │ Pending │ ← Created, not started                │
│                           └────┬────┘                                       │
│                                │ start()                                    │
│                                ▼                                            │
│                           ┌─────────┐                                       │
│               ┌───────────│ Running │───────────┐                           │
│               │           └────┬────┘           │                           │
│               │                │                │                           │
│     pause()   │    complete()  │    fail()     │ cancel()                  │
│               │                │                │                           │
│               ▼                ▼                ▼                           │
│          ┌────────┐      ┌──────────┐     ┌────────┐                       │
│          │ Paused │      │Succeeded │     │ Failed │                       │
│          └───┬────┘      └──────────┘     └───┬────┘                       │
│              │            (terminal)          │                             │
│    resume()  │                                │ compensate()                │
│              │                                ▼                             │
│              │                         ┌─────────────┐                      │
│              └────────────────────────▶│Compensating │                      │
│                                        └──────┬──────┘                      │
│                                               │                             │
│                                          ┌────┴────┐                        │
│                                          │         │                        │
│                                          ▼         ▼                        │
│                                   ┌──────────┐ ┌──────────────────┐        │
│                                   │Compensated│ │CompensationFailed│        │
│                                   └──────────┘ └──────────────────┘        │
│                                    (terminal)                               │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

| State | Description | Terminal |
|-------|-------------|----------|
| `Pending` | Created but not started | No |
| `Running` | Executing steps | No |
| `Paused` | Waiting for resume or trigger | No |
| `Succeeded` | All steps completed | Yes |
| `Failed` | Step failed, awaiting resolution | No |
| `Cancelled` | Manually cancelled | Yes |
| `Compensating` | Running compensation | No |
| `Compensated` | Compensation complete | Yes |
| `CompensationFailed` | Compensation failed | No |

## Workflow Context

Context provides shared data available to all steps:

### Defining Context

```php
<?php

declare(strict_types=1);

namespace App\Contexts;

use Maestro\Workflow\Contracts\WorkflowContext;

final readonly class OrderContext implements WorkflowContext
{
    public function __construct(
        public string $orderId,
        public string $customerId,
        public float $totalAmount,
        public array $items,
    ) {}
}
```

### Context Loader

```php
<?php

declare(strict_types=1);

namespace App\ContextLoaders;

use App\Contexts\OrderContext;
use App\Models\Order;
use Maestro\Workflow\Contracts\ContextLoader;
use Maestro\Workflow\Contracts\WorkflowContext;
use Maestro\Workflow\ValueObjects\WorkflowId;

final readonly class OrderContextLoader implements ContextLoader
{
    public function load(WorkflowId $workflowId): WorkflowContext
    {
        $order = Order::where('workflow_id', $workflowId->value)->firstOrFail();

        return new OrderContext(
            orderId: $order->id,
            customerId: $order->customer_id,
            totalAmount: $order->total,
            items: $order->items->toArray(),
        );
    }
}
```

### Accessing Context in Jobs

```php
final class ProcessPaymentJob extends OrchestratedJob
{
    protected function execute(): void
    {
        // Type-safe context access
        $context = $this->contextAs(OrderContext::class);

        $this->paymentService->charge(
            customerId: $context->customerId,
            amount: $context->totalAmount,
        );
    }
}
```

## Workflow Operations

### Pause

```php
Maestro::pause($workflowId, reason: 'Manual review required');
```

### Resume

```php
Maestro::resume($workflowId);
```

### Cancel

```php
Maestro::cancel($workflowId, compensate: true);
```

### Retry Failed

```php
Maestro::retry($workflowId);
```

### Compensate

```php
Maestro::compensate($workflowId, scope: CompensationScope::All);
```

## Workflow Versioning

Version workflows to track changes:

```php
$builder
    ->name('Order Processing')
    ->version(2)  // Increment when changing step structure
```

Running workflows continue with their original version. New workflows use the latest registered version.

## Workflow Metadata

Store additional data with workflows:

```php
$workflow = Maestro::startWorkflow(
    DefinitionKey::fromString('order-processing'),
    metadata: [
        'source' => 'web',
        'user_agent' => $request->userAgent(),
        'ip_address' => $request->ip(),
    ],
);
```

Access metadata in jobs:

```php
$metadata = $this->workflowMetadata();
$source = $metadata['source'] ?? 'unknown';
```

## Best Practices

### 1. Keep Workflows Focused

Each workflow should accomplish one business goal:

```php
// Good: Single purpose
OrderProcessingWorkflow
PaymentRefundWorkflow
UserOnboardingWorkflow

// Bad: Multiple purposes
DoEverythingWorkflow
```

### 2. Use Meaningful Names

```php
$builder
    ->name('Order Processing')  // Clear display name

    ->step('validate_inventory')  // Descriptive step key
        ->name('Validate Inventory Availability')
```

### 3. Version Thoughtfully

Increment version when:
- Adding or removing steps
- Changing step order
- Modifying failure policies

Don't increment for:
- Bug fixes in job logic
- Output class changes (if backward compatible)

### 4. Handle Workflow Linking

Link workflows to domain entities:

```php
// When creating order
$order = Order::create([...]);

$workflow = Maestro::startWorkflow(
    DefinitionKey::fromString('order-processing'),
);

$order->update(['workflow_id' => $workflow->id->value]);
```

## Next Steps

- [Steps](steps.md) - Understanding step types
- [Jobs](jobs.md) - Job implementation patterns
- [Typed Outputs](typed-outputs.md) - Data passing between steps
