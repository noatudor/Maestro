# Job Execution Pipeline

This document describes how Maestro dispatches and executes jobs within the workflow engine.

## Execution Flow Overview

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                        Job Execution Pipeline                                │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│   ┌─────────────────┐                                                       │
│   │ Step Dispatcher │                                                       │
│   │                 │                                                       │
│   │ • Create StepRun│                                                       │
│   │ • Create JobRec │                                                       │
│   │ • Dispatch to Q │                                                       │
│   └────────┬────────┘                                                       │
│            │                                                                 │
│            ▼                                                                 │
│   ┌─────────────────────────────────────────────────────────────────────┐   │
│   │                        Laravel Queue                                 │   │
│   │  ┌─────────────────────────────────────────────────────────────────┐│   │
│   │  │  Redis / SQS / Database                                         ││   │
│   │  │                                                                  ││   │
│   │  │  { job_class, workflow_id, step_run_id, job_record_id, ... }   ││   │
│   │  └─────────────────────────────────────────────────────────────────┘│   │
│   └────────────────────────────┬────────────────────────────────────────┘   │
│                                │                                             │
│                                │ Worker picks up                             │
│                                ▼                                             │
│   ┌─────────────────────────────────────────────────────────────────────┐   │
│   │                     Job Middleware Pipeline                          │   │
│   │                                                                      │   │
│   │   ┌──────────────────┐                                              │   │
│   │   │ Context Loader   │  Load workflow context                       │   │
│   │   └────────┬─────────┘                                              │   │
│   │            ▼                                                         │   │
│   │   ┌──────────────────┐                                              │   │
│   │   │ Lifecycle Middle │  Track job start, acquire lock              │   │
│   │   └────────┬─────────┘                                              │   │
│   │            ▼                                                         │   │
│   │   ┌──────────────────┐                                              │   │
│   │   │ Idempotency      │  Check if already completed                 │   │
│   │   │ Check            │                                              │   │
│   │   └────────┬─────────┘                                              │   │
│   │            ▼                                                         │   │
│   │   ┌──────────────────┐                                              │   │
│   │   │ Polling Middle   │  (Only for PollingJob)                      │   │
│   │   └────────┬─────────┘                                              │   │
│   │            │                                                         │   │
│   └────────────┼─────────────────────────────────────────────────────────┘   │
│                │                                                             │
│                ▼                                                             │
│   ┌─────────────────────────────────────────────────────────────────────┐   │
│   │                    OrchestratedJob::execute()                        │   │
│   │                                                                      │   │
│   │   • Access context: $this->contextAs(Context::class)                │   │
│   │   • Access outputs: $this->output(PreviousOutput::class)            │   │
│   │   • Execute business logic                                          │   │
│   │   • Store output: $this->store(new MyOutput(...))                   │   │
│   │                                                                      │   │
│   └────────────────────────────┬────────────────────────────────────────┘   │
│                                │                                             │
│                           ┌────┴────┐                                       │
│                           │         │                                       │
│                           ▼         ▼                                       │
│                      Success     Exception                                  │
│                           │         │                                       │
│                           ▼         ▼                                       │
│   ┌─────────────────┐  ┌─────────────────┐                                 │
│   │  Job Completed  │  │   Job Failed    │                                 │
│   │  Listener       │  │   Listener      │                                 │
│   └────────┬────────┘  └────────┬────────┘                                 │
│            │                    │                                           │
│            └─────────┬──────────┘                                           │
│                      ▼                                                      │
│   ┌─────────────────────────────────────────────────────────────────────┐   │
│   │                      Step Finalizer                                  │   │
│   │                                                                      │   │
│   │   • Update step state (Succeeded/Failed)                            │   │
│   │   • Apply failure policy                                            │   │
│   │   • Trigger workflow advancement                                     │   │
│   │                                                                      │   │
│   └────────────────────────────┬────────────────────────────────────────┘   │
│                                ▼                                             │
│   ┌─────────────────────────────────────────────────────────────────────┐   │
│   │                     Workflow Advancer                                │   │
│   │                                                                      │   │
│   │   • Check all conditions                                            │   │
│   │   • Dispatch next step(s)                                           │   │
│   │   • Or complete workflow                                            │   │
│   │                                                                      │   │
│   └─────────────────────────────────────────────────────────────────────┘   │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Step Dispatcher

The `StepDispatcher` is responsible for creating job records and dispatching jobs to the queue.

### Dispatch Process

```php
// Simplified flow
final class StepDispatcher
{
    public function dispatch(
        WorkflowInstance $workflow,
        StepDefinition $step,
    ): StepDispatchResult {
        // 1. Create StepRun record
        $stepRun = $this->createStepRun($workflow, $step);

        // 2. Create JobRecord(s)
        $jobRecords = $this->createJobRecords($stepRun, $step);

        // 3. Dispatch to queue
        foreach ($jobRecords as $jobRecord) {
            $this->dispatchJob($step, $workflow, $stepRun, $jobRecord);
        }

        // 4. Fire events
        $this->events->dispatch(new StepStarted(...));

        return StepDispatchResult::dispatched($stepRun);
    }
}
```

### Job Types by Step Type

| Step Type | Job Class | Jobs Created |
|-----------|-----------|--------------|
| Single Job | `OrchestratedJob` | 1 |
| Fan-Out | `OrchestratedJob` | N (one per item) |
| Polling | `PollingJob` | 1 (may execute multiple times) |
| Compensation | `CompensationJob` | 1 per compensatable step |

## Job Middleware

Jobs pass through a middleware pipeline before execution.

### JobLifecycleMiddleware

Handles job start/completion tracking and lock acquisition:

```php
final class JobLifecycleMiddleware
{
    public function handle(object $job, callable $next): void
    {
        // 1. Acquire workflow lock
        $lock = $this->acquireLock($job->workflowId);

        try {
            // 2. Mark job as started
            $this->markJobStarted($job);

            // 3. Fire JobStarted event
            $this->events->dispatch(new JobStarted(...));

            // 4. Execute job
            $next($job);

            // 5. Mark job as succeeded
            $this->markJobSucceeded($job);

            // 6. Fire JobSucceeded event
            $this->events->dispatch(new JobSucceeded(...));

        } catch (Throwable $e) {
            // 7. Mark job as failed
            $this->markJobFailed($job, $e);

            // 8. Fire JobFailed event
            $this->events->dispatch(new JobFailed(...));

            throw $e;
        } finally {
            // 9. Release lock
            $lock->release();
        }
    }
}
```

### Idempotency Check

Prevents duplicate job execution:

```php
// In middleware
if ($jobRecord->state === JobState::Succeeded) {
    // Job already completed, skip execution
    return;
}
```

### PollingJobMiddleware

Additional middleware for polling jobs:

```php
final class PollingJobMiddleware
{
    public function handle(PollingJob $job, callable $next): void
    {
        // 1. Create poll attempt record
        $attempt = $this->createPollAttempt($job);

        // 2. Execute poll
        $result = $next($job);

        // 3. Handle result
        match ($result::class) {
            CompletedPollResult::class => $this->handleCompletion($job, $result),
            ContinuePollResult::class => $this->scheduleNextPoll($job, $result),
            AbortedPollResult::class => $this->handleAbort($job, $result),
        };
    }
}
```

## OrchestratedJob Base Class

All workflow jobs extend `OrchestratedJob`:

```php
abstract class OrchestratedJob implements ShouldQueue, DispatchableWorkflowJob
{
    use InteractsWithQueue;

    // Injected by middleware
    protected ?WorkflowContext $loadedContext = null;
    protected ?StepOutputReader $outputReader = null;
    protected ?StepOutputStore $outputStore = null;

    // Properties set by dispatcher
    public WorkflowId $workflowId;
    public StepRunId $stepRunId;
    public JobRecordId $jobRecordId;

    /**
     * Called by Laravel queue worker
     */
    public function handle(): void
    {
        $this->execute();
    }

    /**
     * Implement business logic here
     */
    abstract protected function execute(): void;

    // Helper methods available in execute()

    protected function context(): WorkflowContext
    {
        return $this->loadedContext;
    }

    protected function contextAs(string $class): object
    {
        $context = $this->context();
        assert($context instanceof $class);
        return $context;
    }

    protected function output(string $outputClass): object
    {
        return $this->outputReader->get($outputClass);
    }

    protected function outputOrNull(string $outputClass): ?object
    {
        return $this->outputReader->getOrNull($outputClass);
    }

    protected function store(StepOutput $output): void
    {
        $this->outputStore->store($this->stepRunId, $output);
    }

    protected function workflowId(): WorkflowId
    {
        return $this->workflowId;
    }

    protected function workflowMetadata(): array
    {
        return $this->loadedWorkflow->metadata;
    }
}
```

## Job Record Lifecycle

```
┌─────────────────────────────────────────────────────────────────┐
│                    Job Record States                             │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│   ┌─────────┐                                                   │
│   │ Pending │  ← Created, waiting in queue                      │
│   └────┬────┘                                                   │
│        │ Worker picks up                                        │
│        ▼                                                        │
│   ┌─────────┐                                                   │
│   │ Running │  ← execute() in progress                          │
│   └────┬────┘                                                   │
│        │                                                        │
│   ┌────┴────┐                                                   │
│   │         │                                                   │
│   ▼         ▼                                                   │
│ ┌──────────┐ ┌────────┐                                         │
│ │Succeeded │ │ Failed │                                         │
│ └──────────┘ └────────┘                                         │
│  (terminal)   (terminal)                                        │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

### Job Record Data

```php
final readonly class JobRecord
{
    public function __construct(
        public JobRecordId $id,
        public StepRunId $stepRunId,
        public string $jobClass,
        public JobState $state,
        public int $attempt,
        public ?array $args,           // Fan-out item data
        public ?CarbonImmutable $startedAt,
        public ?CarbonImmutable $completedAt,
        public ?string $errorMessage,
        public ?string $errorTrace,
    ) {}
}
```

## Step Finalizer

The `StepFinalizer` handles post-job logic:

```php
final class StepFinalizer
{
    public function finalize(StepRun $stepRun): void
    {
        // 1. Check if all jobs complete
        if (!$this->allJobsComplete($stepRun)) {
            return; // Wait for more jobs
        }

        // 2. Determine step outcome
        $outcome = $this->determineOutcome($stepRun);

        // 3. Update step state
        $stepRun->transitionTo($outcome->state);

        // 4. Fire event
        $this->events->dispatch(
            $outcome->success
                ? new StepSucceeded(...)
                : new StepFailed(...)
        );

        // 5. Apply failure policy (if failed)
        if (!$outcome->success) {
            $this->failurePolicyHandler->handle($stepRun);
        }

        // 6. Trigger workflow advancement
        $this->workflowAdvancer->advance($stepRun->workflowId);
    }

    private function determineOutcome(StepRun $stepRun): StepOutcome
    {
        $step = $this->getStepDefinition($stepRun);

        // For single job steps
        if ($step instanceof SingleJobStepDefinition) {
            return $stepRun->jobRecords[0]->succeeded()
                ? StepOutcome::succeeded()
                : StepOutcome::failed();
        }

        // For fan-out steps
        if ($step instanceof FanOutStepDefinition) {
            return $this->evaluateFanOutOutcome($stepRun, $step);
        }
    }

    private function evaluateFanOutOutcome(
        StepRun $stepRun,
        FanOutStepDefinition $step,
    ): StepOutcome {
        $succeeded = $stepRun->jobRecords->filter(fn($j) => $j->succeeded())->count();
        $total = $stepRun->jobRecords->count();

        return match ($step->successCriteria) {
            SuccessCriteria::All => $succeeded === $total
                ? StepOutcome::succeeded()
                : StepOutcome::failed(),

            SuccessCriteria::Majority => $succeeded > ($total / 2)
                ? StepOutcome::succeeded()
                : StepOutcome::failed(),

            SuccessCriteria::Any => $succeeded > 0
                ? StepOutcome::succeeded()
                : StepOutcome::failed(),

            SuccessCriteria::AtLeast(n: $n) => $succeeded >= $n
                ? StepOutcome::succeeded()
                : StepOutcome::failed(),
        };
    }
}
```

## Fan-Out Execution

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                       Fan-Out Execution Flow                                 │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│   Step Definition                                                            │
│   ┌──────────────────────────────────────────────────────────────────────┐  │
│   │ ->fanOut('process_items')                                            │  │
│   │     ->job(ProcessItemJob::class)                                     │  │
│   │     ->items(fn($ctx, $out) => $ctx->items)  // Returns [A, B, C]    │  │
│   │     ->parallelism(2)                                                 │  │
│   │     ->successCriteria(SuccessCriteria::All)                         │  │
│   └──────────────────────────────────────────────────────────────────────┘  │
│                                                                              │
│   Dispatch Phase                                                             │
│   ┌──────────────────────────────────────────────────────────────────────┐  │
│   │                                                                      │  │
│   │   Items: [A, B, C]                                                   │  │
│   │                                                                      │  │
│   │   ┌─────────────┐  ┌─────────────┐  ┌─────────────┐                │  │
│   │   │ JobRecord 1 │  │ JobRecord 2 │  │ JobRecord 3 │                │  │
│   │   │ item: A     │  │ item: B     │  │ item: C     │                │  │
│   │   │ index: 0    │  │ index: 1    │  │ index: 2    │                │  │
│   │   └──────┬──────┘  └──────┬──────┘  └──────┬──────┘                │  │
│   │          │                │                │                        │  │
│   │          └────────────────┼────────────────┘                        │  │
│   │                           ▼                                         │  │
│   │                    ┌─────────────┐                                  │  │
│   │                    │    Queue    │  (parallelism=2 limits          │  │
│   │                    │  A, B       │   concurrent dispatch)           │  │
│   │                    │  [C waits]  │                                  │  │
│   │                    └─────────────┘                                  │  │
│   └──────────────────────────────────────────────────────────────────────┘  │
│                                                                              │
│   Execution Phase (Workers process in parallel)                              │
│   ┌──────────────────────────────────────────────────────────────────────┐  │
│   │                                                                      │  │
│   │   Worker 1              Worker 2              Worker 1               │  │
│   │   ┌─────────┐          ┌─────────┐          ┌─────────┐            │  │
│   │   │Process A│ ────────▶│Process B│ ────────▶│Process C│            │  │
│   │   │ ✓       │          │ ✗       │          │ ✓       │            │  │
│   │   └─────────┘          └─────────┘          └─────────┘            │  │
│   │                                                                      │  │
│   └──────────────────────────────────────────────────────────────────────┘  │
│                                                                              │
│   Finalization Phase                                                         │
│   ┌──────────────────────────────────────────────────────────────────────┐  │
│   │                                                                      │  │
│   │   Results: [A: ✓, B: ✗, C: ✓]                                       │  │
│   │   Success Criteria: All                                              │  │
│   │   Evaluation: 2/3 succeeded ≠ 3/3 required                          │  │
│   │   Step Outcome: FAILED                                               │  │
│   │                                                                      │  │
│   │   (If SuccessCriteria::Majority → 2/3 > 50% → SUCCEEDED)            │  │
│   │                                                                      │  │
│   └──────────────────────────────────────────────────────────────────────┘  │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Fan-Out Job Arguments

```php
final class ProcessItemJob extends OrchestratedJob
{
    public function __construct(
        public readonly Item $item,   // Injected per-item
        public readonly int $index,   // Position in collection
    ) {}

    protected function execute(): void
    {
        // Process this specific item
        $result = $this->processItem($this->item);

        // Store per-item result
        $this->store(new ItemResultOutput(
            itemId: $this->item->id,
            status: $result->status,
        ));
    }
}
```

## Polling Execution

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                        Polling Execution Flow                                │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│   Initial Dispatch                                                           │
│   ┌──────────────────────────────────────────────────────────────────────┐  │
│   │                                                                      │  │
│   │   Step Config:                                                       │  │
│   │   • interval: 30 seconds                                             │  │
│   │   • maxDuration: 3600 seconds                                        │  │
│   │   • backoffMultiplier: 1.5                                          │  │
│   │                                                                      │  │
│   │   ┌─────────────┐                                                   │  │
│   │   │ First Poll  │  → Queue (delay: 0)                               │  │
│   │   │ Attempt 1   │                                                   │  │
│   │   └─────────────┘                                                   │  │
│   │                                                                      │  │
│   └──────────────────────────────────────────────────────────────────────┘  │
│                                                                              │
│   Poll Loop                                                                  │
│   ┌──────────────────────────────────────────────────────────────────────┐  │
│   │                                                                      │  │
│   │   Attempt 1 (t=0)                                                    │  │
│   │   ┌─────────────────────────────────────────────────────────────┐   │  │
│   │   │ poll() → ContinuePollResult("Still pending")                │   │  │
│   │   │ → Schedule next poll in 30s                                  │   │  │
│   │   └─────────────────────────────────────────────────────────────┘   │  │
│   │              │                                                       │  │
│   │              ▼ (30s delay)                                          │  │
│   │   Attempt 2 (t=30)                                                   │  │
│   │   ┌─────────────────────────────────────────────────────────────┐   │  │
│   │   │ poll() → ContinuePollResult("Processing")                   │   │  │
│   │   │ → Schedule next poll in 45s (30 * 1.5)                      │   │  │
│   │   └─────────────────────────────────────────────────────────────┘   │  │
│   │              │                                                       │  │
│   │              ▼ (45s delay)                                          │  │
│   │   Attempt 3 (t=75)                                                   │  │
│   │   ┌─────────────────────────────────────────────────────────────┐   │  │
│   │   │ poll() → CompletedPollResult(PaymentConfirmedOutput)        │   │  │
│   │   │ → Step Succeeded                                             │   │  │
│   │   └─────────────────────────────────────────────────────────────┘   │  │
│   │                                                                      │  │
│   └──────────────────────────────────────────────────────────────────────┘  │
│                                                                              │
│   Possible Outcomes                                                          │
│   ┌──────────────────────────────────────────────────────────────────────┐  │
│   │                                                                      │  │
│   │   CompletedPollResult  →  Step Succeeded                            │  │
│   │   AbortedPollResult    →  Step Failed                               │  │
│   │   Timeout (maxDuration)→  Step TimedOut (based on timeoutPolicy)   │  │
│   │                                                                      │  │
│   └──────────────────────────────────────────────────────────────────────┘  │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

### PollingJob Base Class

```php
abstract class PollingJob extends OrchestratedJob
{
    /**
     * Implement polling logic here
     */
    abstract protected function poll(): PollResult;

    /**
     * Called by framework - do not override
     */
    final protected function execute(): void
    {
        // This is handled by PollingJobMiddleware
        throw new \LogicException('PollingJob::execute() should not be called directly');
    }
}
```

## Events

### Job Events

```php
// Job queued
JobDispatched::class
// Properties: workflowId, stepKey, jobRecordId, jobClass, dispatchedAt

// Job started processing
JobStarted::class
// Properties: workflowId, stepKey, jobRecordId, jobClass, startedAt

// Job completed successfully
JobSucceeded::class
// Properties: workflowId, stepKey, jobRecordId, jobClass, completedAt, durationMs

// Job threw exception
JobFailed::class
// Properties: workflowId, stepKey, jobRecordId, jobClass, exception, failedAt
```

### Poll Events

```php
// Poll attempt executed
PollAttempted::class
// Properties: workflowId, stepKey, attemptNumber, result, attemptedAt

// Polling completed successfully
PollCompleted::class
// Properties: workflowId, stepKey, totalAttempts, output, completedAt

// Polling aborted by job
PollAborted::class
// Properties: workflowId, stepKey, reason, abortedAt

// Polling exceeded max duration
PollTimedOut::class
// Properties: workflowId, stepKey, totalAttempts, timedOutAt
```

## Error Handling

### Retryable vs Non-Retryable Errors

```php
protected function execute(): void
{
    try {
        $this->externalApi->call();
    } catch (RateLimitException $e) {
        // Retryable - re-throw to trigger retry
        throw $e;
    } catch (InvalidDataException $e) {
        // Non-retryable - wrap and throw
        throw new UnrecoverableJobException($e->getMessage(), previous: $e);
    }
}
```

### Exception Flow

```
Exception in execute()
        │
        ▼
JobLifecycleMiddleware catches
        │
        ├─── Mark JobRecord as Failed
        │
        ├─── Fire JobFailed event
        │
        ├─── Release lock
        │
        └─── Re-throw to Laravel

Laravel Queue Handler
        │
        ├─── If job has retries left → Re-queue
        │
        └─── If max attempts reached → Move to failed_jobs
```

## Next Steps

- [Concurrency Control](concurrency.md) - Locking mechanisms
- [State Machine](state-machine.md) - State transitions
- [Database Schema](database-schema.md) - Data structures
