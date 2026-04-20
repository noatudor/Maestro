# Fan-Out Steps

Fan-out steps execute multiple jobs in parallel, one for each item in a collection. They enable efficient batch processing with configurable success criteria.

## Basic Configuration

```php
use Maestro\Workflow\Definition\Builders\WorkflowDefinitionBuilder;
use Maestro\Workflow\Enums\SuccessCriteria;

$builder
    ->fanOut('process_items')
        ->name('Process Line Items')
        ->job(ProcessLineItemJob::class)
        ->items(fn($context, $outputs) => $context->order->lineItems)
        ->successCriteria(SuccessCriteria::All)
        ->build();
```

## Item Iterator

The `items()` method defines what collection to iterate over:

### From Context

```php
->items(fn($context, $outputs) => $context->order->lineItems)
```

### From Previous Step Output

```php
->items(fn($context, $outputs) => $outputs->get(OrderOutput::class)->items)
```

### From Multiple Sources

```php
->items(function ($context, $outputs) {
    $order = $outputs->get(OrderOutput::class);
    return collect($order->items)
        ->merge($order->addOns)
        ->all();
})
```

## Job Arguments

Customize arguments passed to each job instance:

```php
->fanOut('process_items')
    ->job(ProcessLineItemJob::class)
    ->items(fn($ctx, $out) => $ctx->lineItems)
    ->jobArguments(fn($item, $index) => [
        'item' => $item,
        'index' => $index,
        'batchId' => uniqid('batch_'),
    ])
    ->build()
```

Access in your job:

```php
final class ProcessLineItemJob extends OrchestratedJob
{
    public function __construct(
        public readonly LineItem $item,
        public readonly int $index,
        public readonly string $batchId,
    ) {}

    protected function execute(): void
    {
        // Use $this->item, $this->index, $this->batchId
    }
}
```

## Success Criteria

Configure how many jobs must succeed for the step to succeed:

### All (Default)

Every job must succeed:

```php
->successCriteria(SuccessCriteria::All)
```

### Majority

More than 50% of jobs must succeed:

```php
->successCriteria(SuccessCriteria::Majority)
```

### Best Effort

Step succeeds if at least one job succeeds:

```php
->successCriteria(SuccessCriteria::BestEffort)
```

### N of M

Exactly N jobs out of M must succeed:

```php
use Maestro\Workflow\Definition\Config\NOfMCriteria;

->successCriteria(new NOfMCriteria(required: 3, total: 5))
```

## Parallelism Control

Limit concurrent job execution:

```php
->fanOut('api_calls')
    ->job(CallExternalApiJob::class)
    ->items(fn($ctx, $out) => $ctx->endpoints)
    ->parallelism(5)  // Max 5 concurrent jobs
    ->build()
```

This prevents overwhelming external services or resource exhaustion.

## Output Aggregation

Fan-out steps aggregate outputs from all successful jobs:

### Producing Aggregated Output

```php
->fanOut('process_items')
    ->job(ProcessLineItemJob::class)
    ->items(fn($ctx, $out) => $ctx->items)
    ->produces(LineItemResultsOutput::class)
    ->build()
```

Each job stores its individual output:

```php
final class ProcessLineItemJob extends OrchestratedJob
{
    protected function execute(): void
    {
        $result = $this->processItem($this->item);

        $this->store(new LineItemResult(
            itemId: $this->item->id,
            status: $result->status,
            processedAt: now(),
        ));
    }
}
```

### Accessing Aggregated Results

Downstream steps receive all results:

```php
final class SummarizeResultsJob extends OrchestratedJob
{
    protected function execute(): void
    {
        // Get all results from fan-out step
        $results = $this->output(LineItemResultsOutput::class);

        // $results is a collection of LineItemResult
        $successful = $results->filter(fn($r) => $r->status === 'success');
        $failed = $results->filter(fn($r) => $r->status === 'failed');

        $this->store(new SummaryOutput(
            totalProcessed: $results->count(),
            successCount: $successful->count(),
            failedCount: $failed->count(),
        ));
    }
}
```

## Compensation

Compensation runs for each completed job in a fan-out step:

```php
->fanOut('reserve_inventory')
    ->job(ReserveInventoryJob::class)
    ->items(fn($ctx, $out) => $out->get(OrderOutput::class)->items)
    ->compensation(ReleaseInventoryJob::class)
    ->build()
```

When compensation is triggered:
1. Compensation job runs for each item that was successfully processed
2. Jobs run in parallel (respecting parallelism limits)
3. Failed compensation jobs can be retried

## Failure Handling

### With All Criteria

If any job fails, the step fails:

```php
->fanOut('critical_processing')
    ->successCriteria(SuccessCriteria::All)
    ->failurePolicy(FailurePolicy::FailWorkflow)
```

### With Partial Criteria

Continue if enough jobs succeed:

```php
->fanOut('best_effort_processing')
    ->successCriteria(SuccessCriteria::Majority)
    ->failurePolicy(FailurePolicy::ContinueWithPartial)
```

### Retry Failed Jobs Only

Retry only the jobs that failed:

```php
->fanOut('api_calls')
    ->successCriteria(SuccessCriteria::All)
    ->failurePolicy(FailurePolicy::RetryStep)
    ->retryable(
        maxAttempts: 3,
        delaySeconds: 30,
        retryScope: RetryScope::FailedOnly  // Only retry failed jobs
    )
```

## Complete Example

```php
<?php

declare(strict_types=1);

namespace App\Workflows;

use App\Jobs\Workflow\{
    ValidateOrderJob,
    ProcessLineItemJob,
    NotifySupplierJob,
    GenerateInvoiceJob,
};
use App\Jobs\Compensation\{
    CancelLineItemJob,
    CancelSupplierNotificationJob,
};
use App\Outputs\{
    OrderValidationOutput,
    LineItemResultsOutput,
    SupplierNotificationOutput,
};
use Maestro\Workflow\Definition\Builders\WorkflowDefinitionBuilder;
use Maestro\Workflow\Definition\WorkflowDefinition;
use Maestro\Workflow\Enums\{FailurePolicy, SuccessCriteria};

final class OrderFulfillmentWorkflow extends WorkflowDefinition
{
    public function define(WorkflowDefinitionBuilder $builder): void
    {
        $builder
            ->name('Order Fulfillment')
            ->version(1)

            // Single job: Validate the entire order
            ->step('validate')
                ->name('Validate Order')
                ->job(ValidateOrderJob::class)
                ->produces(OrderValidationOutput::class)
                ->build()

            // Fan-out: Process each line item in parallel
            ->fanOut('process_items')
                ->name('Process Line Items')
                ->job(ProcessLineItemJob::class)
                ->requires('validate', OrderValidationOutput::class)
                ->items(fn($ctx, $out) => $out->get(OrderValidationOutput::class)->items)
                ->jobArguments(fn($item, $index) => [
                    'item' => $item,
                    'sequence' => $index + 1,
                ])
                ->successCriteria(SuccessCriteria::All)
                ->parallelism(10)
                ->produces(LineItemResultsOutput::class)
                ->compensation(CancelLineItemJob::class)
                ->build()

            // Fan-out: Notify suppliers (best effort)
            ->fanOut('notify_suppliers')
                ->name('Notify Suppliers')
                ->job(NotifySupplierJob::class)
                ->items(function ($ctx, $out) {
                    $results = $out->get(LineItemResultsOutput::class);
                    return $results
                        ->pluck('supplierId')
                        ->unique()
                        ->values()
                        ->all();
                })
                ->successCriteria(SuccessCriteria::BestEffort)
                ->parallelism(5)
                ->failurePolicy(FailurePolicy::SkipStep)
                ->produces(SupplierNotificationOutput::class)
                ->build()

            // Single job: Generate final invoice
            ->step('invoice')
                ->name('Generate Invoice')
                ->job(GenerateInvoiceJob::class)
                ->requires('process_items', LineItemResultsOutput::class)
                ->build();
    }
}
```

## Job Implementation Pattern

```php
<?php

declare(strict_types=1);

namespace App\Jobs\Workflow;

use App\Models\LineItem;
use Maestro\Workflow\Application\Job\OrchestratedJob;

final class ProcessLineItemJob extends OrchestratedJob
{
    public function __construct(
        public readonly LineItem $item,
        public readonly int $sequence,
    ) {}

    protected function execute(): void
    {
        // Access workflow context if needed
        $context = $this->contextAs(OrderContext::class);

        // Process this specific item
        $result = $this->processItem($this->item);

        // Store individual result (will be aggregated)
        $this->store(new LineItemResult(
            itemId: $this->item->id,
            sequence: $this->sequence,
            status: $result->status,
            processedAt: now(),
            details: $result->details,
        ));
    }

    private function processItem(LineItem $item): ProcessingResult
    {
        // Business logic here
    }
}
```

## Performance Considerations

### Parallelism Tuning

- Start with conservative limits (5-10)
- Monitor queue depth and processing times
- Increase gradually based on capacity

### Memory Management

For large collections, consider:

```php
->items(function ($ctx, $out) {
    // Use generators for very large collections
    return LazyCollection::make(function () use ($ctx) {
        foreach ($ctx->getItemsCursor() as $item) {
            yield $item;
        }
    });
})
```

### Database Considerations

- Fan-out creates one job record per item
- Large fan-outs may impact database performance
- Consider batching for very large collections

## Next Steps

- [Polling Steps](polling.md) - Long-running operation patterns
- [Compensation](../failure-handling/compensation.md) - Rollback strategies
- [Failure Handling](../failure-handling/overview.md) - Error recovery patterns
