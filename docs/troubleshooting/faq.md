# Frequently Asked Questions

## General Questions

### What is Maestro?

Maestro is a high-performance workflow orchestration package for Laravel. It enables you to define complex business processes as workflows with typed data passing, parallel execution, and robust failure handling.

### How does Maestro differ from Laravel's job chains?

| Feature | Maestro | Laravel Job Chains |
|---------|---------|-------------------|
| State persistence | Full database tracking | None (in-memory) |
| Resume from failure | Yes, with retry policies | No |
| Parallel execution | Fan-out/fan-in support | No |
| Typed data passing | Yes, with outputs | No |
| External triggers | Yes (webhooks, approvals) | No |
| Observability | Complete event stream | Limited |
| Pause/Resume | Yes | No |

### What are the performance characteristics?

- **Workflow creation**: < 5ms
- **Step advancement**: < 10ms
- **Job dispatch**: < 2ms
- **State queries**: < 1ms (indexed lookups)
- **Designed for**: Millions of concurrent workflows

## Workflow Design

### Can I have conditional steps?

Currently, Maestro executes steps sequentially. For conditional logic:

1. **In-job decision making**: Handle conditions within your job
2. **Skip policy**: Use `skipStep()` for non-critical steps
3. **Future feature**: Conditional branching is planned for a future release

### How many steps can a workflow have?

There's no hard limit, but consider:
- Each step adds database overhead
- Very long workflows may benefit from being split into sub-workflows
- Typical workflows have 3-15 steps

### Can workflows call other workflows?

Yes, you can start a child workflow from within a job:

```php
protected function execute(): void
{
    $childWorkflow = Maestro::startWorkflow(
        DefinitionKey::fromString('child-workflow'),
    );

    // Store child workflow ID for tracking
    $this->outputs()?->put(new ChildWorkflowOutput(
        childWorkflowId: $childWorkflow->id->value,
    ));
}
```

## Jobs and Execution

### Can I use existing Laravel jobs with Maestro?

Jobs must extend `OrchestratedJob` to participate in workflows. You can wrap existing logic:

```php
final class MaestroWrapper extends OrchestratedJob
{
    protected function execute(): void
    {
        // Call your existing job logic
        $existingJob = new ExistingJob($this->getData());
        $existingJob->handle();
    }
}
```

### How do I pass data to jobs?

Three ways:

1. **Workflow context**: Shared data across all steps
2. **Step outputs**: Typed data from previous steps
3. **Fan-out arguments**: Per-item data in fan-out steps

### What happens if a job throws an exception?

The job is marked as failed. What happens next depends on the failure policy:

- `failWorkflow()`: Entire workflow fails
- `pauseWorkflow()`: Workflow pauses for manual intervention
- `retryStep()`: Step is retried (up to max attempts)
- `skipStep()`: Step is skipped, workflow continues
- `continueWithPartial()`: For fan-out, continue with successful jobs

### Can jobs be idempotent?

Yes, and they should be! Design jobs to handle re-execution safely:

```php
protected function execute(): void
{
    // Check for existing result
    if (Order::where('workflow_id', $this->workflowId->value)->exists()) {
        return; // Already processed
    }

    // Process normally
    Order::create([...]);
}
```

## Fan-Out and Parallel Execution

### How does fan-out parallelism work?

Fan-out steps dispatch multiple jobs to the queue. Parallelism is controlled by:

1. **Queue worker count**: More workers = more parallelism
2. **`maxParallel()` setting**: Limits concurrent jobs per step

```php
->fanOut('process', fn ($step) => $step
    ->maxParallel(10)) // Max 10 concurrent jobs
```

### What if fan-out produces zero items?

Empty fan-out is handled gracefully - the step succeeds immediately with zero jobs.

### How do I aggregate fan-out results?

Use mergeable outputs:

```php
final readonly class ItemResultOutput implements MergeableOutput
{
    public function mergeWith(MergeableOutput $other): MergeableOutput
    {
        return new self(
            results: [...$this->results, ...$other->results],
        );
    }
}
```

## State Management

### How long is workflow data retained?

Indefinitely by default. Configure cleanup:

```php
'cleanup' => [
    'enabled' => true,
    'archive_after_days' => 30,
    'delete_after_days' => 90,
],
```

### Can I query workflow state from outside Maestro?

Yes, query the database directly for reporting:

```sql
SELECT state, COUNT(*) as count
FROM maestro_workflows
WHERE definition_key = 'order-processing'
GROUP BY state;
```

### How do I cancel a workflow?

```php
Maestro::cancelWorkflow($workflowId);
```

Cancelled workflows cannot be resumed.

## Integration

### How do I trigger workflows from webhooks?

1. Create an endpoint that receives the webhook
2. Start or trigger the workflow:

```php
// Start new workflow
Route::post('/webhooks/order', function (Request $request) {
    Maestro::startWorkflow(DefinitionKey::fromString('order-processing'));
});

// Resume paused workflow
Route::post('/webhooks/approve/{workflowId}', function ($workflowId) {
    $handler = app(ExternalTriggerHandler::class);
    $handler->handleTrigger(
        WorkflowId::fromString($workflowId),
        'approval',
    );
});
```

### How do I integrate with monitoring systems?

Listen to Maestro events:

```php
Event::listen(WorkflowFailed::class, function ($event) {
    // Send to monitoring
    Sentry::captureMessage("Workflow failed: {$event->workflowId->value}");
});
```

### Can I use Maestro with Horizon?

Yes! Configure your horizon.php to process the Maestro queue:

```php
'environments' => [
    'production' => [
        'supervisor-1' => [
            'queue' => ['default', 'workflows'],
        ],
    ],
],
```

## Troubleshooting

### Why isn't my workflow advancing?

See [Common Issues](common-issues.md) for detailed troubleshooting steps.

### How do I debug a stuck workflow?

1. Check workflow state: `Maestro::getStatus($id)`
2. Check step runs in database
3. Check job ledger for job states
4. Enable debug logging
5. Check queue failed_jobs table

### How do I reset a failed workflow?

```php
// Retry from the failed step
Maestro::retryWorkflow($workflowId);
```

## More Questions?

If your question isn't answered here:

1. Check the [Common Issues](common-issues.md) guide
2. Search existing GitHub issues
3. Open a new issue with:
   - Maestro version
   - Laravel version
   - PHP version
   - Steps to reproduce
   - Expected vs actual behavior
