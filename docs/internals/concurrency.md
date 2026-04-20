# Concurrency Control

This document explains how Maestro handles concurrent operations to ensure data consistency and prevent race conditions.

## Concurrency Challenges

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                      Concurrency Scenarios                                   │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│   Scenario 1: Parallel Job Completion (Fan-Out)                             │
│   ┌─────────────────────────────────────────────────────────────────────┐   │
│   │                                                                     │   │
│   │   Worker A                 Worker B                Worker C         │   │
│   │      │                        │                       │             │   │
│   │      ▼                        ▼                       ▼             │   │
│   │   Job 1 done              Job 2 done              Job 3 done        │   │
│   │      │                        │                       │             │   │
│   │      └────────────────────────┼───────────────────────┘             │   │
│   │                               ▼                                     │   │
│   │                    Who finalizes the step?                          │   │
│   │                    (Only one should!)                               │   │
│   │                                                                     │   │
│   └─────────────────────────────────────────────────────────────────────┘   │
│                                                                              │
│   Scenario 2: Concurrent Trigger + Timeout                                  │
│   ┌─────────────────────────────────────────────────────────────────────┐   │
│   │                                                                     │   │
│   │   Trigger Handler              Timeout Checker                      │   │
│   │         │                            │                              │   │
│   │         ▼                            ▼                              │   │
│   │   Resume workflow             Timeout workflow                      │   │
│   │         │                            │                              │   │
│   │         └────────────────────────────┘                              │   │
│   │                       │                                             │   │
│   │                       ▼                                             │   │
│   │            Race condition!                                          │   │
│   │            (Both try to change state)                               │   │
│   │                                                                     │   │
│   └─────────────────────────────────────────────────────────────────────┘   │
│                                                                              │
│   Scenario 3: Duplicate Job Dispatch                                        │
│   ┌─────────────────────────────────────────────────────────────────────┐   │
│   │                                                                     │   │
│   │   Workflow Advancer A          Workflow Advancer B                  │   │
│   │   (from Job 1 complete)        (from Job 2 complete)                │   │
│   │         │                            │                              │   │
│   │         ▼                            ▼                              │   │
│   │   Dispatch next step           Dispatch next step                   │   │
│   │         │                            │                              │   │
│   │         └────────────────────────────┘                              │   │
│   │                       │                                             │   │
│   │                       ▼                                             │   │
│   │            Double dispatch!                                         │   │
│   │            (Step runs twice)                                        │   │
│   │                                                                     │   │
│   └─────────────────────────────────────────────────────────────────────┘   │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Locking Strategy

Maestro uses distributed locks to ensure only one process modifies a workflow at a time.

### Lock Architecture

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                        Locking Architecture                                  │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│   ┌───────────────────────────────────────────────────────────────────┐     │
│   │                     Lock Manager                                   │     │
│   │                                                                    │     │
│   │   acquire(workflowId) ──────┐                                     │     │
│   │                             │                                      │     │
│   │                             ▼                                      │     │
│   │         ┌─────────────────────────────────────┐                   │     │
│   │         │        Lock Backend                  │                   │     │
│   │         │  ┌───────────┐  ┌───────────┐       │                   │     │
│   │         │  │   Redis   │  │  Database │       │                   │     │
│   │         │  │   (fast)  │  │ (fallback)│       │                   │     │
│   │         │  └───────────┘  └───────────┘       │                   │     │
│   │         └─────────────────────────────────────┘                   │     │
│   │                             │                                      │     │
│   │                             ▼                                      │     │
│   │   ┌─────────────────────────────────────────────────────────────┐ │     │
│   │   │                      Lock Entry                              │ │     │
│   │   │                                                              │ │     │
│   │   │  Key: "maestro:workflow:{uuid}:lock"                        │ │     │
│   │   │  Value: "worker-hostname-pid-timestamp"                      │ │     │
│   │   │  TTL: 30 seconds (auto-release)                             │ │     │
│   │   │                                                              │ │     │
│   │   └─────────────────────────────────────────────────────────────┘ │     │
│   │                                                                    │     │
│   └───────────────────────────────────────────────────────────────────┘     │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Lock Acquisition Flow

```php
final class WorkflowLockManager
{
    public function acquire(WorkflowId $workflowId): WorkflowLock
    {
        $key = "maestro:workflow:{$workflowId->value}:lock";
        $owner = $this->generateOwner();

        $attempts = 0;
        $maxAttempts = $this->config['retry_count'];

        while ($attempts < $maxAttempts) {
            // Try to acquire
            $acquired = $this->backend->set(
                $key,
                $owner,
                'NX', // Only if not exists
                'EX', // With expiry
                $this->config['timeout'],
            );

            if ($acquired) {
                return new WorkflowLock(
                    key: $key,
                    owner: $owner,
                    backend: $this->backend,
                );
            }

            // Wait before retry
            usleep($this->config['retry_delay_ms'] * 1000);
            $attempts++;
        }

        throw new WorkflowLockedException($workflowId);
    }

    private function generateOwner(): string
    {
        return sprintf(
            '%s-%d-%s',
            gethostname(),
            getmypid(),
            Uuid::uuid4()->toString(),
        );
    }
}
```

### Lock Release

```php
final readonly class WorkflowLock
{
    public function release(): void
    {
        // Lua script for atomic check-and-delete
        $script = <<<'LUA'
            if redis.call("GET", KEYS[1]) == ARGV[1] then
                return redis.call("DEL", KEYS[1])
            else
                return 0
            end
        LUA;

        $this->backend->eval($script, [$this->key], [$this->owner]);
    }
}
```

## Database Lock Backend

For environments without Redis, database-based locking is available:

```php
// config/maestro.php
'locking' => [
    'driver' => 'database',
    'table' => 'maestro_locks',
    'timeout' => 30,
],
```

```sql
CREATE TABLE maestro_locks (
    workflow_id VARCHAR(36) PRIMARY KEY,
    owner VARCHAR(255) NOT NULL,
    acquired_at TIMESTAMP NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    INDEX idx_expires (expires_at)
);
```

```php
final class DatabaseLockBackend implements LockBackend
{
    public function acquire(string $key, string $owner, int $ttl): bool
    {
        $now = CarbonImmutable::now();
        $expiresAt = $now->addSeconds($ttl);

        // Clean up expired locks
        DB::table('maestro_locks')
            ->where('expires_at', '<', $now)
            ->delete();

        // Try to insert (will fail if exists)
        try {
            DB::table('maestro_locks')->insert([
                'workflow_id' => $key,
                'owner' => $owner,
                'acquired_at' => $now,
                'expires_at' => $expiresAt,
            ]);
            return true;
        } catch (QueryException $e) {
            // Lock exists
            return false;
        }
    }
}
```

## Critical Sections

### Job Completion Critical Section

```php
final class JobLifecycleMiddleware
{
    public function handle(object $job, callable $next): void
    {
        // ┌─────────────────────────────────────────────────┐
        // │ CRITICAL SECTION START - Lock acquired         │
        // └─────────────────────────────────────────────────┘

        $lock = $this->lockManager->acquire($job->workflowId);

        try {
            // All of this runs atomically:
            // 1. Check workflow state (still valid?)
            // 2. Check job state (not already complete?)
            // 3. Execute job
            // 4. Update job record
            // 5. Update step run (if all jobs done)
            // 6. Advance workflow (dispatch next step)

            $workflow = $this->workflows->findOrFail($job->workflowId);

            if ($workflow->state->isTerminal()) {
                // Workflow already finished, skip
                return;
            }

            $jobRecord = $this->jobRecords->findOrFail($job->jobRecordId);

            if ($jobRecord->state !== JobState::Pending) {
                // Job already processed, skip (idempotency)
                return;
            }

            // Mark as running
            $this->jobRecords->markStarted($jobRecord);

            // Execute
            $next($job);

            // Mark as succeeded
            $this->jobRecords->markSucceeded($jobRecord);

            // Finalize step
            $this->stepFinalizer->finalize($jobRecord->stepRunId);

        } catch (Throwable $e) {
            $this->jobRecords->markFailed($jobRecord, $e);
            throw $e;
        } finally {
            $lock->release();
        }

        // ┌─────────────────────────────────────────────────┐
        // │ CRITICAL SECTION END - Lock released           │
        // └─────────────────────────────────────────────────┘
    }
}
```

### Workflow State Transition Critical Section

```php
final class WorkflowManagementService
{
    public function pause(WorkflowId $workflowId, string $reason): void
    {
        $lock = $this->lockManager->acquire($workflowId);

        try {
            $workflow = $this->workflows->findOrFail($workflowId);

            // Validate transition
            if (!$workflow->canPause()) {
                throw new InvalidStateTransitionException(
                    $workflow->state,
                    WorkflowState::Paused,
                );
            }

            // Atomic state change
            $workflow->pause($reason);
            $this->workflows->save($workflow);

            // Fire event
            $this->events->dispatch(new WorkflowPaused(...));

        } finally {
            $lock->release();
        }
    }
}
```

## Optimistic Concurrency

For read-heavy operations, Maestro uses optimistic concurrency with version checking:

```php
// Workflow model includes version column
final class WorkflowModel extends Model
{
    protected $guarded = [];

    protected $casts = [
        'version' => 'integer',
    ];
}
```

```php
final class EloquentWorkflowRepository implements WorkflowRepository
{
    public function save(WorkflowInstance $workflow): void
    {
        $currentVersion = $workflow->version;
        $newVersion = $currentVersion + 1;

        $affected = WorkflowModel::where('id', $workflow->id->value)
            ->where('version', $currentVersion)
            ->update([
                'state' => $workflow->state->value,
                'version' => $newVersion,
                // ... other fields
            ]);

        if ($affected === 0) {
            throw new ConcurrentModificationException(
                "Workflow {$workflow->id->value} was modified by another process"
            );
        }

        $workflow->version = $newVersion;
    }
}
```

## Race Condition Prevention

### Fan-Out Finalization

Only the last completing job should finalize the step:

```php
final class StepFinalizer
{
    public function tryFinalize(StepRunId $stepRunId): bool
    {
        // Atomic increment and check
        $result = DB::table('maestro_step_runs')
            ->where('id', $stepRunId->value)
            ->where('completed_jobs', '<', DB::raw('total_jobs'))
            ->increment('completed_jobs');

        if ($result === 0) {
            // Another process already finalized
            return false;
        }

        $stepRun = $this->stepRuns->find($stepRunId);

        // Check if we were the last one
        if ($stepRun->completedJobs >= $stepRun->totalJobs) {
            $this->finalizeStep($stepRun);
            return true;
        }

        return false;
    }
}
```

### Trigger Handling

Prevent duplicate trigger processing:

```php
final class ExternalTriggerHandler
{
    public function handleTrigger(
        WorkflowId $workflowId,
        StepKey $triggerKey,
        TriggerPayload $payload,
    ): TriggerResult {
        $lock = $this->lockManager->acquire($workflowId);

        try {
            $workflow = $this->workflows->findOrFail($workflowId);

            // Check if trigger already received
            if ($workflow->triggerReceivedAt !== null) {
                return TriggerResult::alreadyTriggered();
            }

            // Check if timed out
            if ($workflow->triggerTimedOutAt !== null) {
                return TriggerResult::timedOut();
            }

            // Process trigger
            $workflow->receiveTrigger($payload);
            $this->workflows->save($workflow);

            // Resume workflow
            $this->workflowAdvancer->advance($workflowId);

            return TriggerResult::success();

        } finally {
            $lock->release();
        }
    }
}
```

## Lock Configuration

```php
// config/maestro.php
'locking' => [
    // Lock backend driver
    'driver' => env('MAESTRO_LOCK_DRIVER', 'redis'),

    // Redis connection name
    'connection' => env('MAESTRO_LOCK_CONNECTION', 'default'),

    // Lock timeout in seconds (auto-release after this time)
    'timeout' => env('MAESTRO_LOCK_TIMEOUT', 30),

    // Milliseconds to wait between retry attempts
    'retry_delay_ms' => env('MAESTRO_LOCK_RETRY_DELAY', 50),

    // Maximum number of retry attempts
    'retry_count' => env('MAESTRO_LOCK_RETRY_COUNT', 10),
],
```

## Deadlock Prevention

Maestro prevents deadlocks through:

1. **Single lock per operation**: Only one workflow lock at a time
2. **Lock ordering**: Always acquire locks in workflow ID order when multiple needed
3. **Lock timeouts**: Locks auto-expire preventing indefinite waits
4. **No nested locks**: Critical sections don't acquire additional locks

```php
// If you need to operate on multiple workflows, acquire locks in order:
public function transferBetweenWorkflows(
    WorkflowId $sourceId,
    WorkflowId $targetId,
): void {
    // Always lock in consistent order (alphabetically by ID)
    $ids = [$sourceId->value, $targetId->value];
    sort($ids);

    $locks = [];

    try {
        foreach ($ids as $id) {
            $locks[] = $this->lockManager->acquire(WorkflowId::fromString($id));
        }

        // Perform operation...

    } finally {
        foreach (array_reverse($locks) as $lock) {
            $lock->release();
        }
    }
}
```

## Monitoring Lock Contention

```php
Event::listen(JobStarted::class, function ($event) {
    // $event->lockAcquisitionMs contains time spent waiting for lock

    if ($event->lockAcquisitionMs > 100) {
        Log::warning('Lock contention detected', [
            'workflow_id' => $event->workflowId->value,
            'lock_time_ms' => $event->lockAcquisitionMs,
        ]);

        Metrics::histogram(
            'maestro.lock.acquisition_time',
            $event->lockAcquisitionMs
        );
    }
});
```

## Best Practices

1. **Keep critical sections short**: Minimize time holding locks
2. **Use Redis for high throughput**: Database locks add latency
3. **Monitor lock wait times**: High wait times indicate contention
4. **Design idempotent jobs**: Even with locks, design for re-execution
5. **Test concurrent scenarios**: Use parallel test runners

## Next Steps

- [Job Execution](job-execution.md) - Execution pipeline
- [State Machine](state-machine.md) - State transitions
- [Performance](../advanced/performance.md) - Optimization
