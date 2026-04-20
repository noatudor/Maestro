# Quick Start Guide

This guide walks you through creating and running your first Maestro workflow.

## Step 1: Create a Job Class

Every workflow step executes through job classes. Create a job that extends `OrchestratedJob`:

```php
<?php

namespace App\Jobs\Workflow;

use Maestro\Workflow\Application\Job\OrchestratedJob;

final class ProcessOrderJob extends OrchestratedJob
{
    protected function execute(): void
    {
        // Access workflow context if configured
        $orderId = $this->context()?->orderId;

        // Your business logic here
        logger()->info('Processing order', ['order_id' => $orderId]);

        // Job completes successfully by returning normally
        // Throw an exception to mark the job as failed
    }
}
```

## Step 2: Define a Workflow

Create a workflow definition using the fluent builder:

```php
<?php

namespace App\Workflows;

use App\Jobs\Workflow\ProcessOrderJob;
use App\Jobs\Workflow\SendConfirmationJob;
use Maestro\Workflow\Definition\Builders\SingleJobStepBuilder;
use Maestro\Workflow\Definition\Builders\WorkflowDefinitionBuilder;
use Maestro\Workflow\Definition\WorkflowDefinition;

final class OrderWorkflow
{
    public static function definition(): WorkflowDefinition
    {
        return WorkflowDefinitionBuilder::create('order-processing')
            ->displayName('Order Processing')
            ->singleJob('process-order', fn (SingleJobStepBuilder $step) => $step
                ->displayName('Process Order')
                ->job(ProcessOrderJob::class))
            ->singleJob('send-confirmation', fn (SingleJobStepBuilder $step) => $step
                ->displayName('Send Confirmation')
                ->job(SendConfirmationJob::class))
            ->build();
    }
}
```

## Step 3: Register the Workflow

Register your workflow in a service provider:

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
        $registry->register(OrderWorkflow::definition());
    }
}
```

## Step 4: Start the Workflow

Start a workflow instance from anywhere in your application:

```php
use Maestro\Workflow\Maestro;
use Maestro\Workflow\ValueObjects\DefinitionKey;

// Start a new workflow instance
$workflow = Maestro::startWorkflow(
    DefinitionKey::fromString('order-processing'),
);

// Get the workflow ID for tracking
$workflowId = $workflow->id;

echo "Started workflow: {$workflowId->value}";
```

## Step 5: Monitor Progress

Check the workflow status:

```php
use Maestro\Workflow\Maestro;
use Maestro\Workflow\ValueObjects\WorkflowId;

$status = Maestro::getStatus(WorkflowId::fromString($workflowId));

echo "Workflow state: {$status->state()->value}";
echo "Current step: {$status->currentStepKey()?->toString()}";
```

## Complete Example

Here's a complete example putting it all together:

```php
// Controller or Command
use App\Workflows\OrderWorkflow;
use Maestro\Workflow\Definition\WorkflowDefinitionRegistry;
use Maestro\Workflow\Maestro;
use Maestro\Workflow\ValueObjects\DefinitionKey;

public function processOrder(Request $request, WorkflowDefinitionRegistry $registry)
{
    // Ensure workflow is registered
    if (!$registry->has(DefinitionKey::fromString('order-processing'))) {
        $registry->register(OrderWorkflow::definition());
    }

    // Start the workflow
    $workflow = Maestro::startWorkflow(
        DefinitionKey::fromString('order-processing'),
    );

    return response()->json([
        'workflow_id' => $workflow->id->value,
        'status' => $workflow->state()->value,
        'message' => 'Order processing started',
    ]);
}
```

## What Happens Next

1. The workflow transitions to `running` state
2. The first step (`process-order`) is dispatched to the queue
3. A queue worker picks up and executes `ProcessOrderJob`
4. On success, the workflow advances to `send-confirmation`
5. `SendConfirmationJob` executes
6. On success, the workflow transitions to `succeeded` state

## Next Steps

- [Understanding Workflows](../concepts/workflows.md) - Deep dive into workflow concepts
- [Defining Steps](../guide/defining-steps.md) - Learn about different step types
- [Fan-Out & Fan-In](../guide/fan-out-fan-in.md) - Process collections in parallel
- [Failure Handling](../guide/failure-handling.md) - Configure retry and failure policies
