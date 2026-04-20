# Common Issues and Solutions

This guide covers the most frequently encountered issues when working with Maestro.

## Workflow Issues

### Workflow Stuck in Running State

**Symptoms:**
- Workflow shows `running` state but no jobs are being processed
- Current step doesn't change

**Possible Causes and Solutions:**

1. **Queue worker not running**
   ```bash
   # Check if queue worker is running
   php artisan queue:work --queue=workflows

   # Or restart supervisor
   supervisorctl restart all
   ```

2. **Jobs failing silently**
   ```bash
   # Check failed jobs
   php artisan queue:failed

   # View failed job details
   php artisan queue:failed --list
   ```

3. **Database lock not released**
   ```sql
   -- Check for locked workflows (MySQL)
   SELECT * FROM maestro_workflows
   WHERE locked_by IS NOT NULL
   AND locked_at < NOW() - INTERVAL 5 MINUTE;

   -- Clear stale locks
   UPDATE maestro_workflows
   SET locked_by = NULL, locked_at = NULL
   WHERE locked_at < NOW() - INTERVAL 5 MINUTE;
   ```

### Workflow Stuck in Pending State

**Symptoms:**
- Workflow created but never starts
- No step runs created

**Solutions:**

1. **Ensure workflow definition is registered**
   ```php
   $registry = app(WorkflowDefinitionRegistry::class);
   $key = DefinitionKey::fromString('your-workflow');

   if (!$registry->has($key)) {
       throw new \RuntimeException("Workflow not registered");
   }
   ```

2. **Check for registration in service provider**
   ```php
   // In AppServiceProvider or custom provider
   public function boot(WorkflowDefinitionRegistry $registry): void
   {
       $registry->register(YourWorkflow::definition());
   }
   ```

### Workflow Fails Immediately

**Symptoms:**
- Workflow transitions to `failed` state
- First step never completes

**Check:**

1. **Job class exists and is correct**
   ```php
   // Verify the job class extends OrchestratedJob
   use Maestro\Workflow\Application\Job\OrchestratedJob;

   class YourJob extends OrchestratedJob
   {
       protected function execute(): void
       {
           // Your code here
       }
   }
   ```

2. **Step dependencies are satisfied**
   ```php
   // If a step requires output from another step
   ->singleJob('step-2', fn ($step) => $step
       ->requires(Step1Output::class) // This output must exist
       ->job(Step2Job::class))
   ```

## Job Execution Issues

### Jobs Not Being Dispatched

**Check:**

1. **Queue configuration is correct**
   ```php
   // config/maestro.php
   'queue' => [
       'connection' => 'redis', // Make sure this matches your queue driver
       'name' => 'workflows',
   ],
   ```

2. **Queue worker is listening to correct queue**
   ```bash
   php artisan queue:work --queue=workflows
   ```

### Jobs Running Multiple Times

**Symptoms:**
- Same job executes multiple times
- Duplicate entries in job ledger

**Solutions:**

1. **Ensure idempotent job design**
   ```php
   protected function execute(): void
   {
       // Check if already processed
       if ($this->alreadyProcessed()) {
           return;
       }

       // Process...
   }
   ```

2. **Check queue retry configuration**
   ```php
   // In your job class
   public $tries = 1; // Prevent automatic retries
   ```

### Jobs Timeout

**Solutions:**

1. **Increase job timeout**
   ```php
   ->singleJob('long-running', fn ($step) => $step
       ->job(LongRunningJob::class)
       ->timeout(stepTimeoutSeconds: 3600)) // 1 hour
   ```

2. **Break into smaller steps**
   ```php
   // Instead of one long step, use fan-out
   ->fanOut('process-items', fn ($step) => $step
       ->job(ProcessSingleItemJob::class)
       ->iterateOver(fn () => $items))
   ```

## Fan-Out Issues

### Empty Fan-Out Causes Issues

**Symptoms:**
- Fan-out step with zero items behaves unexpectedly

**Note:** Empty fan-out is handled correctly by default. The step succeeds immediately with zero jobs.

### Partial Fan-Out Failures

**Configure success criteria:**

```php
->fanOut('tolerant-step', fn ($step) => $step
    ->job(ProcessItemJob::class)
    ->iterateOver(fn () => $items)
    ->requireMajority() // 50%+ success
    ->continueWithPartial()) // Don't fail workflow
```

## Database Issues

### Lock Contention

**Symptoms:**
- `WorkflowLockedException` errors
- Workflows timeout waiting for locks

**Solutions:**

1. **Increase lock timeout**
   ```php
   // config/maestro.php
   'locking' => [
       'timeout' => 10, // Increase from default 5 seconds
   ],
   ```

2. **Use Redis locks for high-throughput**
   ```php
   'locking' => [
       'driver' => 'redis',
   ],
   ```

### Slow Queries

**Solutions:**

1. **Ensure indexes exist**
   ```bash
   php artisan migrate:status
   # Verify all Maestro migrations have run
   ```

2. **Check query performance**
   ```sql
   -- Check for missing indexes
   EXPLAIN SELECT * FROM maestro_workflows WHERE state = 'running';
   ```

### Connection Timeouts

**For long-running operations:**

```php
// In config/database.php, increase timeout
'mysql' => [
    'options' => [
        PDO::ATTR_TIMEOUT => 120,
    ],
],
```

## Event and Trigger Issues

### Events Not Firing

**Check:**

```php
// Verify events are enabled in config
'events' => [
    'workflow_events' => true,
    'step_events' => true,
],
```

### External Triggers Not Working

**Debug steps:**

1. **Verify trigger authentication**
   ```php
   // Check HMAC signature
   $payload = json_encode($data);
   $signature = hash_hmac('sha256', $payload, config('maestro.triggers.hmac_secret'));
   ```

2. **Check API routes are registered**
   ```bash
   php artisan route:list | grep maestro
   ```

## Performance Issues

### Slow Workflow Creation

**Solutions:**

1. **Use bulk registration**
   ```php
   // Register all workflows at boot time
   public function boot(WorkflowDefinitionRegistry $registry): void
   {
       foreach (WorkflowRegistry::all() as $definition) {
           $registry->register($definition);
       }
   }
   ```

2. **Cache workflow definitions**
   ```bash
   php artisan config:cache
   ```

### Memory Issues with Large Fan-Outs

**Use generators for large collections:**

```php
->fanOut('large-dataset', fn ($step) => $step
    ->iterateOver(fn () => $this->lazyLoadItems())
    ->maxParallel(100)) // Limit concurrent jobs

private function lazyLoadItems(): Generator
{
    foreach (DB::table('items')->cursor() as $item) {
        yield $item;
    }
}
```

## Getting Help

If you're still experiencing issues:

1. Enable debug logging:
   ```php
   // config/logging.php
   'channels' => [
       'maestro' => [
           'driver' => 'daily',
           'path' => storage_path('logs/maestro.log'),
           'level' => 'debug',
       ],
   ],
   ```

2. Check the [FAQ](faq.md) for more answers

3. Search or open an issue on GitHub
