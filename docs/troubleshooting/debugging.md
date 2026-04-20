# Debugging Workflows

This guide covers techniques for debugging Maestro workflows during development and in production.

## Debugging Tools

### CLI Commands

```bash
# View workflow status
php artisan maestro:detail {workflow_id}

# List all workflows with filtering
php artisan maestro:list --state=failed --limit=10

# View workflow graph (visual representation)
php artisan maestro:graph {definition_key}
```

### Programmatic Inspection

```php
use Maestro\Workflow\Maestro;

// Get workflow status
$status = Maestro::getStatus($workflowId);
dump($status);

// Get detailed information
$detail = Maestro::getDetail($workflowId);
foreach ($detail->steps as $step) {
    dump("{$step->key}: {$step->state->value}");
}
```

## Debugging Workflow

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                      Debugging Decision Tree                                 │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│   Workflow Issue                                                             │
│        │                                                                     │
│        ▼                                                                     │
│   ┌─────────────────────────────────────────────────────────────────────┐   │
│   │ What is the workflow state?                                          │   │
│   └──────────────────────────┬──────────────────────────────────────────┘   │
│                              │                                               │
│         ┌────────────────────┼────────────────────┬─────────────────┐       │
│         ▼                    ▼                    ▼                 ▼       │
│      Pending             Running              Paused             Failed     │
│         │                    │                    │                 │       │
│         ▼                    ▼                    ▼                 ▼       │
│   Check:                Check:               Check:             Check:      │
│   • Definition         • Queue workers      • Trigger config   • Step logs │
│     registered?        • Current step       • Pause reason     • Job errors│
│   • Workflow           • Job state          • Timeout          • Retry     │
│     started?           • Lock contention                         status    │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Common Debugging Scenarios

### 1. Workflow Stuck in Pending

**Symptoms:** Workflow created but never starts

**Check definition registration:**
```php
$registry = app(WorkflowDefinitionRegistry::class);
$key = DefinitionKey::fromString('your-workflow');

if ($registry->has($key)) {
    dump("Definition registered: " . $registry->get($key)->name());
} else {
    dump("Definition NOT registered!");
}
```

**Check service provider:**
```php
// In AppServiceProvider or custom provider
public function boot(WorkflowDefinitionRegistry $registry): void
{
    $registry->register(new YourWorkflow());
}
```

### 2. Workflow Stuck in Running

**Symptoms:** Workflow shows running but no progress

**Check current step:**
```bash
php artisan maestro:detail {workflow_id}
```

**Check queue workers:**
```bash
# Verify workers are processing the right queue
php artisan queue:work --queue=maestro --verbose
```

**Check for lock contention:**
```sql
-- Check for stale locks (if using database locks)
SELECT * FROM maestro_workflows
WHERE locked_by IS NOT NULL
AND locked_at < NOW() - INTERVAL 5 MINUTE;
```

**Check job status:**
```php
$workflow = WorkflowModel::find($workflowId);
$stepRuns = $workflow->stepRuns()->where('state', 'running')->get();

foreach ($stepRuns as $stepRun) {
    $jobs = $stepRun->jobRecords;
    foreach ($jobs as $job) {
        dump("{$job->job_class}: {$job->state}");
        if ($job->error_message) {
            dump("Error: {$job->error_message}");
        }
    }
}
```

### 3. Step Failing Repeatedly

**Check job exceptions:**
```php
$stepRun = StepRunModel::where('workflow_id', $workflowId)
    ->where('step_key', 'failing_step')
    ->first();

foreach ($stepRun->jobRecords as $job) {
    if ($job->state === 'failed') {
        dump("Error: {$job->error_message}");
        dump("Trace: {$job->error_trace}");
    }
}
```

**Check Laravel failed_jobs:**
```bash
php artisan queue:failed
```

### 4. Fan-Out Not Completing

**Check individual jobs:**
```php
$stepRun = StepRunModel::where('workflow_id', $workflowId)
    ->where('step_key', 'fan_out_step')
    ->first();

dump("Total jobs: {$stepRun->total_jobs}");
dump("Successful: {$stepRun->successful_jobs}");
dump("Failed: {$stepRun->failed_jobs}");

$pendingJobs = $stepRun->jobRecords()->where('state', 'pending')->count();
dump("Pending: {$pendingJobs}");
```

### 5. Polling Never Completing

**Check poll attempts:**
```php
$stepRun = StepRunModel::where('workflow_id', $workflowId)
    ->where('step_key', 'polling_step')
    ->first();

$attempts = PollAttemptModel::where('step_run_id', $stepRun->id)->get();

foreach ($attempts as $attempt) {
    dump("Attempt {$attempt->attempt_number}: {$attempt->result}");
    dump("Message: {$attempt->message}");
}
```

## Logging Configuration

### Enable Debug Logging

```php
// config/logging.php
'channels' => [
    'maestro' => [
        'driver' => 'daily',
        'path' => storage_path('logs/maestro.log'),
        'level' => 'debug',
        'days' => 14,
    ],
],
```

### Log Workflow Events

```php
// AppServiceProvider
Event::listen('Maestro\Workflow\Domain\Events\*', function ($event) {
    Log::channel('maestro')->debug(class_basename($event), [
        'workflow_id' => $event->workflowId?->value,
        'step_key' => $event->stepKey?->value ?? null,
        'timestamp' => now()->toIso8601String(),
    ]);
});
```

### Job-Level Logging

```php
protected function execute(): void
{
    Log::debug('Job started', [
        'job' => static::class,
        'workflow_id' => $this->workflowId()->value,
    ]);

    try {
        // Job logic...

        Log::debug('Job completed', [
            'job' => static::class,
            'workflow_id' => $this->workflowId()->value,
        ]);
    } catch (\Throwable $e) {
        Log::error('Job failed', [
            'job' => static::class,
            'workflow_id' => $this->workflowId()->value,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        throw $e;
    }
}
```

## Database Queries

### Useful Debug Queries

```sql
-- Find stuck workflows
SELECT id, definition_key, state, current_step_key, updated_at
FROM maestro_workflows
WHERE state = 'running'
AND updated_at < NOW() - INTERVAL 30 MINUTE;

-- Find failed steps with errors
SELECT sr.workflow_id, sr.step_key, jr.error_message
FROM maestro_step_runs sr
JOIN maestro_job_ledger jr ON jr.step_run_id = sr.id
WHERE sr.state = 'failed'
ORDER BY sr.created_at DESC
LIMIT 20;

-- Check job distribution by state
SELECT state, COUNT(*) as count
FROM maestro_job_ledger
WHERE created_at > NOW() - INTERVAL 1 HOUR
GROUP BY state;

-- Find workflows by metadata
SELECT *
FROM maestro_workflows
WHERE JSON_EXTRACT(metadata, '$.order_id') = '12345';
```

### Query Helpers

```php
// Find workflows stuck in running state
$stuckWorkflows = WorkflowModel::where('state', 'running')
    ->where('updated_at', '<', now()->subMinutes(30))
    ->get();

// Find failed workflows from today
$failedToday = WorkflowModel::where('state', 'failed')
    ->whereDate('updated_at', today())
    ->with('stepRuns.jobRecords')
    ->get();
```

## Tinker Debugging

```bash
php artisan tinker
```

```php
// Load workflow
$workflow = WorkflowModel::find('uuid-here');

// Check step runs
$workflow->stepRuns->each(fn($sr) => dump("{$sr->step_key}: {$sr->state}"));

// Check specific step
$step = $workflow->stepRuns->firstWhere('step_key', 'payment');
$step->jobRecords->each(fn($j) => dump("{$j->state}: {$j->error_message}"));

// Check outputs
$outputs = StepOutputModel::where('workflow_id', $workflow->id)->get();
$outputs->each(fn($o) => dump("{$o->step_key}: " . substr($o->payload, 0, 100)));

// Manually advance (use with caution!)
$advancer = app(WorkflowAdvancer::class);
$advancer->advance(WorkflowId::fromString($workflow->id));
```

## Testing Workflows Locally

### Using Sync Queue

```php
// config/queue.php (for testing)
'default' => env('QUEUE_CONNECTION', 'sync'),
```

### Step-by-Step Execution

```php
// Start workflow
$workflow = Maestro::startWorkflow(DefinitionKey::fromString('my-workflow'));

// Process one job at a time
$this->artisan('queue:work --once');

// Check status after each step
dump(Maestro::getStatus($workflow->id));
```

### Debug Test

```php
it('debugs workflow execution', function () {
    config(['queue.default' => 'sync']);

    $workflow = Maestro::startWorkflow(
        DefinitionKey::fromString('my-workflow'),
    );

    // Workflow runs synchronously - check each step
    $detail = Maestro::getDetail($workflow->id);

    foreach ($detail->steps as $step) {
        dump("Step: {$step->key}");
        dump("State: {$step->state->value}");

        if ($step->state === StepState::Failed) {
            foreach ($step->jobs as $job) {
                dump("Job error: {$job->errorMessage}");
            }
        }
    }
});
```

## Debugging Production Issues

### Safe Investigation

```php
// Read-only investigation
$workflow = WorkflowModel::find($workflowId);

// Don't modify state directly - use commands
// WRONG: $workflow->update(['state' => 'running']);
// RIGHT: php artisan maestro:resume {workflow_id}
```

### Export for Analysis

```php
// Export workflow data for offline analysis
$workflow = WorkflowModel::with([
    'stepRuns.jobRecords',
    'outputs',
])->find($workflowId);

$export = [
    'workflow' => $workflow->toArray(),
    'events' => DB::table('maestro_events')
        ->where('workflow_id', $workflowId)
        ->get()
        ->toArray(),
];

file_put_contents(
    "workflow-debug-{$workflowId}.json",
    json_encode($export, JSON_PRETTY_PRINT)
);
```

## Common Pitfalls

### 1. Not Checking Queue Workers

Always verify queue workers are running and processing the correct queue.

### 2. Ignoring Failed Jobs Table

Laravel's `failed_jobs` table contains valuable error information.

### 3. Missing Idempotency

Jobs that fail partway through may have side effects. Design for re-execution.

### 4. Timeout Issues

Long-running jobs may timeout. Check job timeout configuration.

### 5. Lock Contention

High-throughput systems may experience lock waiting. Consider Redis locks.

## Debug Checklist

- [ ] Workflow definition is registered
- [ ] Queue workers are running
- [ ] Correct queue name is configured
- [ ] Check workflow state and current step
- [ ] Check step run states and job records
- [ ] Check failed_jobs table
- [ ] Check application logs
- [ ] Check for lock contention
- [ ] Verify context loader returns valid data
- [ ] Check output classes are serializable

## Next Steps

- [Common Issues](common-issues.md) - Known problems and solutions
- [FAQ](faq.md) - Frequently asked questions
- [Monitoring](../advanced/monitoring.md) - Production monitoring
