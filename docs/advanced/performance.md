# Performance Optimization

This guide covers strategies for optimizing Maestro workflows for high throughput and low latency.

## Performance Targets

Maestro is designed to meet these performance benchmarks:

| Operation | Target | Notes |
|-----------|--------|-------|
| Workflow creation | < 5ms | Includes DB insert and first job dispatch |
| Step advancement | < 10ms | Lock acquisition + state update + next dispatch |
| Job dispatch | < 2ms | Queue push operation |
| State queries | < 1ms | Indexed lookups only |

## Database Optimization

### Index Strategy

Ensure all Maestro indexes are in place:

```bash
# Verify migrations have run
php artisan migrate:status | grep maestro
```

Critical indexes for performance:

```sql
-- Workflow state lookups (most common query)
CREATE INDEX idx_workflows_state ON maestro_workflows(state);
CREATE INDEX idx_workflows_definition_state ON maestro_workflows(definition_key, state);

-- Step run queries
CREATE INDEX idx_step_runs_workflow ON maestro_step_runs(workflow_id, state);
CREATE INDEX idx_step_runs_step_key ON maestro_step_runs(workflow_id, step_key);

-- Job lookups
CREATE INDEX idx_jobs_step_run ON maestro_job_records(step_run_id, state);

-- Trigger queries
CREATE INDEX idx_triggers_workflow ON maestro_trigger_payloads(workflow_id);
```

### Query Optimization

#### Avoid N+1 Queries

```php
// Bad: N+1 query
$workflows = WorkflowModel::where('state', 'running')->get();
foreach ($workflows as $workflow) {
    $steps = $workflow->stepRuns; // N queries
}

// Good: Eager load
$workflows = WorkflowModel::with('stepRuns')
    ->where('state', 'running')
    ->get();
```

#### Use Chunking for Bulk Operations

```php
// Process large datasets in chunks
WorkflowModel::where('state', 'failed')
    ->where('created_at', '<', now()->subDays(30))
    ->chunkById(1000, function ($workflows) {
        foreach ($workflows as $workflow) {
            // Archive or cleanup
        }
    });
```

### Connection Pooling

Configure connection pooling for high-throughput scenarios:

```php
// config/database.php
'mysql' => [
    'driver' => 'mysql',
    'host' => env('DB_HOST'),
    // ...
    'options' => [
        PDO::ATTR_PERSISTENT => true, // Enable persistent connections
    ],
],
```

## Queue Optimization

### Redis Queue Driver

Use Redis for optimal queue performance:

```php
// config/queue.php
'redis' => [
    'driver' => 'redis',
    'connection' => 'default',
    'queue' => 'workflows',
    'retry_after' => 90,
    'block_for' => 5, // Long polling for efficiency
],
```

### Queue Prioritization

```php
// config/maestro.php
'queue' => [
    'connection' => 'redis',
    'name' => 'workflows',
],

// Process high-priority workflows first
// Run multiple workers with different queues
// php artisan queue:work --queue=workflows-high,workflows,workflows-low
```

### Job Batching

For fan-out steps, Maestro batches job dispatch:

```php
->fanOut('process_items')
    ->job(ProcessItemJob::class)
    ->items(fn($ctx, $out) => $ctx->items)
    ->parallelism(100) // Dispatch in batches of 100
    ->build()
```

## Locking Optimization

### Redis Locks (Recommended)

For high-throughput systems, use Redis locks instead of database locks:

```php
// config/maestro.php
'locking' => [
    'driver' => 'redis',
    'connection' => 'default',
    'timeout' => 5,
    'retry_delay_ms' => 50,
    'retry_count' => 10,
],
```

### Lock Timeout Tuning

Adjust lock timeouts based on your job duration:

```php
'locking' => [
    // Short timeout for fast workflows
    'timeout' => 3, // seconds

    // Or longer for complex operations
    'timeout' => 30,
],
```

### Lock Contention Monitoring

Monitor lock acquisition times:

```php
Event::listen(JobStarted::class, function ($event) {
    $lockTime = $event->lockAcquisitionMs;

    if ($lockTime > 100) {
        Log::warning('Slow lock acquisition', [
            'workflow_id' => $event->workflowId->value,
            'lock_time_ms' => $lockTime,
        ]);
    }
});
```

## Memory Optimization

### Lazy Loading for Large Collections

```php
// Use generators for large fan-out datasets
->fanOut('large_batch')
    ->job(ProcessJob::class)
    ->items(function ($ctx, $out) {
        // Returns a generator, not array
        return $this->loadItemsLazily($ctx->batchId);
    })
    ->build()

private function loadItemsLazily(string $batchId): Generator
{
    foreach (DB::table('items')
        ->where('batch_id', $batchId)
        ->cursor() as $item) {
        yield $item;
    }
}
```

### Output Size Management

Keep step outputs small to minimize memory and storage:

```php
// Bad: Large output
final readonly class LargeOutput implements StepOutput
{
    public function __construct(
        public array $allRecords, // 10,000 records
    ) {}
}

// Good: Reference to data
final readonly class ReferenceOutput implements StepOutput
{
    public function __construct(
        public string $batchId, // Reference only
        public int $recordCount,
    ) {}
}
```

### Context Loading Optimization

Load only necessary data in context loaders:

```php
final readonly class OptimizedContextLoader implements ContextLoader
{
    public function load(WorkflowId $workflowId): WorkflowContext
    {
        // Load minimal data
        $order = Order::select(['id', 'customer_id', 'total', 'status'])
            ->where('workflow_id', $workflowId->value)
            ->firstOrFail();

        // Don't load relationships unless needed
        return new OrderContext(
            orderId: $order->id,
            customerId: $order->customer_id,
            total: $order->total,
        );
    }
}
```

## Caching Strategies

### Definition Caching

Workflow definitions are cached automatically. Ensure config caching is enabled:

```bash
php artisan config:cache
```

### Output Caching

For expensive computations accessed multiple times:

```php
protected function execute(): void
{
    $cacheKey = "workflow:{$this->workflowId()->value}:computed";

    $result = Cache::remember($cacheKey, 3600, function () {
        return $this->expensiveComputation();
    });
}
```

## Horizontal Scaling

### Worker Scaling

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                         Horizontal Scaling                                   │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│   Load Balancer                                                              │
│        │                                                                     │
│        ▼                                                                     │
│   ┌────────────┬────────────┬────────────┐                                  │
│   │  Web App   │  Web App   │  Web App   │  (stateless API)                │
│   └─────┬──────┴─────┬──────┴─────┬──────┘                                  │
│         │            │            │                                          │
│         └────────────┼────────────┘                                          │
│                      ▼                                                       │
│               Redis Queue                                                    │
│                      │                                                       │
│         ┌────────────┼────────────┐                                          │
│         ▼            ▼            ▼                                          │
│   ┌──────────┐ ┌──────────┐ ┌──────────┐                                    │
│   │ Worker 1 │ │ Worker 2 │ │ Worker N │  (scale workers independently)    │
│   └────┬─────┘ └────┬─────┘ └────┬─────┘                                    │
│        │            │            │                                          │
│        └────────────┼────────────┘                                          │
│                     ▼                                                        │
│            Database (MySQL/Postgres)                                         │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

Scale workers based on queue depth:

```bash
# Auto-scaling with Kubernetes
# HPA based on Redis queue length
kubectl autoscale deployment workers --min=2 --max=20 --cpu-percent=70
```

### Database Read Replicas

For read-heavy workloads:

```php
// config/database.php
'mysql' => [
    'read' => [
        'host' => ['mysql-read-1', 'mysql-read-2'],
    ],
    'write' => [
        'host' => 'mysql-write',
    ],
],
```

## Profiling and Benchmarking

### Built-in Benchmarks

Run Maestro's benchmark suite:

```bash
./vendor/bin/sail composer bench
```

### Custom Profiling

```php
use Illuminate\Support\Benchmark;

$result = Benchmark::measure([
    'workflow_creation' => fn() => Maestro::startWorkflow(
        DefinitionKey::fromString('benchmark'),
    ),
    'status_query' => fn() => Maestro::getStatus($workflowId),
]);

// Output timing results
```

### Database Query Logging

```php
// Enable query logging in development
DB::enableQueryLog();

// Run workflow operations...

// Analyze queries
$queries = DB::getQueryLog();
foreach ($queries as $query) {
    if ($query['time'] > 50) { // > 50ms
        Log::warning('Slow query', $query);
    }
}
```

## Performance Checklist

Use this checklist when optimizing:

- [ ] All Maestro migrations have run
- [ ] Indexes verified with EXPLAIN
- [ ] Redis queue driver configured
- [ ] Redis locking enabled for high throughput
- [ ] Connection pooling enabled
- [ ] Queue workers scaled appropriately
- [ ] Large outputs avoided (use references)
- [ ] Generators used for large fan-out collections
- [ ] Query logging enabled in development
- [ ] Benchmarks run and baselines established
- [ ] Monitoring in place for lock times and query durations

## Next Steps

- [Scaling Guide](scaling.md) - Multi-server deployments
- [Monitoring](monitoring.md) - Observability setup
- [Database Schema](../internals/database-schema.md) - Index details
