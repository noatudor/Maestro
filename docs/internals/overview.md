# Architecture Overview

This document explains how Maestro works internally, covering the core components, data flow, and design decisions.

## High-Level Architecture

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                              Maestro Architecture                            │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  ┌──────────────────────────────────────────────────────────────────────┐   │
│  │                          Application Layer                            │   │
│  │  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐  │   │
│  │  │  Workflow   │  │    Step     │  │    Job      │  │   Query     │  │   │
│  │  │  Manager    │  │  Dispatcher │  │  Dispatch   │  │   Service   │  │   │
│  │  └─────────────┘  └─────────────┘  └─────────────┘  └─────────────┘  │   │
│  │  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐  │   │
│  │  │  Workflow   │  │   Step      │  │  Failure    │  │ Compensation│  │   │
│  │  │  Advancer   │  │  Finalizer  │  │  Handler    │  │  Executor   │  │   │
│  │  └─────────────┘  └─────────────┘  └─────────────┘  └─────────────┘  │   │
│  └──────────────────────────────────────────────────────────────────────┘   │
│                                    │                                         │
│                                    ▼                                         │
│  ┌──────────────────────────────────────────────────────────────────────┐   │
│  │                            Domain Layer                               │   │
│  │  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐  │   │
│  │  │  Workflow   │  │   StepRun   │  │   Events    │  │  Conditions │  │   │
│  │  │  Instance   │  │             │  │             │  │             │  │   │
│  │  └─────────────┘  └─────────────┘  └─────────────┘  └─────────────┘  │   │
│  └──────────────────────────────────────────────────────────────────────┘   │
│                                    │                                         │
│                                    ▼                                         │
│  ┌──────────────────────────────────────────────────────────────────────┐   │
│  │                        Infrastructure Layer                           │   │
│  │  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐  │   │
│  │  │ Repositories│  │   Models    │  │   Queue     │  │    HTTP     │  │   │
│  │  │             │  │             │  │  Workers    │  │  Controllers│  │   │
│  │  └─────────────┘  └─────────────┘  └─────────────┘  └─────────────┘  │   │
│  └──────────────────────────────────────────────────────────────────────┘   │
│                                    │                                         │
│                                    ▼                                         │
│  ┌──────────────────────────────────────────────────────────────────────┐   │
│  │                           External Systems                            │   │
│  │     ┌──────────┐      ┌──────────┐      ┌──────────┐                 │   │
│  │     │ Database │      │  Queue   │      │  Events  │                 │   │
│  │     │ (MySQL/  │      │ (Redis/  │      │(Laravel) │                 │   │
│  │     │ Postgres)│      │  SQS)    │      │          │                 │   │
│  │     └──────────┘      └──────────┘      └──────────┘                 │   │
│  └──────────────────────────────────────────────────────────────────────┘   │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Core Components

### Workflow Manager

The main entry point for workflow operations:

```php
interface WorkflowManager
{
    public function start(DefinitionKey $definition): WorkflowInstance;
    public function pause(WorkflowId $id): void;
    public function resume(WorkflowId $id): void;
    public function cancel(WorkflowId $id): void;
    public function trigger(WorkflowId $id, string $key, TriggerPayload $payload): TriggerResult;
    public function resolve(WorkflowId $id, ResolutionDecision $decision): void;
    public function compensate(WorkflowId $id, CompensationScope $scope): void;
    public function retryFromStep(RetryFromStepRequest $request): RetryFromStepResult;
}
```

### Workflow Advancer

Determines and executes the next step:

```
┌─────────────────────────────────────────────────────────────────┐
│                     Workflow Advancer Flow                       │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│   advance(workflowId)                                            │
│         │                                                        │
│         ▼                                                        │
│   ┌───────────────────┐                                         │
│   │ Acquire Lock      │ ◄── Prevents concurrent advancement     │
│   └─────────┬─────────┘                                         │
│             │                                                    │
│             ▼                                                    │
│   ┌───────────────────┐                                         │
│   │ Load Workflow     │                                         │
│   └─────────┬─────────┘                                         │
│             │                                                    │
│             ▼                                                    │
│   ┌───────────────────┐                                         │
│   │ Find Next Step    │ ◄── Based on definition & completed    │
│   └─────────┬─────────┘     steps                               │
│             │                                                    │
│        ┌────┴────┐                                              │
│        │         │                                              │
│        ▼         ▼                                              │
│   No more     Found                                             │
│   steps       next step                                         │
│        │         │                                              │
│        │         ▼                                              │
│        │   ┌───────────────────┐                                │
│        │   │ Evaluate Condition│                                │
│        │   └─────────┬─────────┘                                │
│        │             │                                          │
│        │        ┌────┴────┐                                     │
│        │        │         │                                     │
│        │        ▼         ▼                                     │
│        │     False      True                                    │
│        │        │         │                                     │
│        │        ▼         ▼                                     │
│        │   Skip step   Dispatch                                 │
│        │        │       step                                    │
│        │        │         │                                     │
│        └────────┴─────────┘                                     │
│                 │                                                │
│                 ▼                                                │
│   ┌───────────────────┐                                         │
│   │ Release Lock      │                                         │
│   └───────────────────┘                                         │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

### Step Dispatcher

Handles job dispatch for different step types:

```php
interface StepDispatcher
{
    public function dispatch(
        WorkflowInstance $workflow,
        StepDefinition $step,
    ): StepDispatchResult;
}
```

Implementations:
- `SingleJobStepDispatcher` - Dispatches one job
- `FanOutStepDispatcher` - Dispatches multiple parallel jobs
- `PollingStepDispatcher` - Dispatches polling job with schedule

### Step Finalizer

Handles step completion and workflow advancement:

```
┌─────────────────────────────────────────────────────────────────┐
│                     Step Finalizer Flow                          │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│   finalize(stepRunId, result)                                    │
│         │                                                        │
│         ▼                                                        │
│   ┌───────────────────┐                                         │
│   │ Update Step State │                                         │
│   └─────────┬─────────┘                                         │
│             │                                                    │
│        ┌────┴────┐                                              │
│        │         │                                              │
│        ▼         ▼                                              │
│    Success    Failure                                           │
│        │         │                                              │
│        │         ▼                                              │
│        │   ┌───────────────────┐                                │
│        │   │ Apply Failure     │                                │
│        │   │ Policy            │                                │
│        │   └─────────┬─────────┘                                │
│        │             │                                          │
│        │    ┌────────┼────────┬────────┐                        │
│        │    │        │        │        │                        │
│        │    ▼        ▼        ▼        ▼                        │
│        │  Retry    Pause    Skip     Fail                       │
│        │    │        │        │    Workflow                     │
│        │    │        │        │        │                        │
│        └────┴────────┴────────┴────────┘                        │
│                      │                                          │
│                      ▼                                          │
│   ┌───────────────────────────────┐                             │
│   │ Check Workflow Completion     │                             │
│   └─────────────┬─────────────────┘                             │
│                 │                                                │
│            ┌────┴────┐                                          │
│            │         │                                          │
│            ▼         ▼                                          │
│        Complete   Continue                                      │
│            │         │                                          │
│            ▼         ▼                                          │
│       Mark as    Advance to                                     │
│       Succeeded  Next Step                                      │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

### Failure Policy Handler

Routes failures based on step configuration:

```php
final class FailurePolicyHandler
{
    public function handle(
        WorkflowInstance $workflow,
        StepRun $stepRun,
        Throwable $exception,
    ): FailureHandlingResult {
        return match ($stepRun->failurePolicy()) {
            FailurePolicy::FailWorkflow => $this->failWorkflow($workflow, $stepRun),
            FailurePolicy::PauseWorkflow => $this->pauseWorkflow($workflow),
            FailurePolicy::RetryStep => $this->retryStep($workflow, $stepRun),
            FailurePolicy::SkipStep => $this->skipStep($stepRun),
            FailurePolicy::ContinueWithPartial => $this->continueWithPartial($stepRun),
        };
    }
}
```

### Compensation Executor

Runs compensation jobs in reverse order:

```
┌─────────────────────────────────────────────────────────────────┐
│                   Compensation Executor Flow                     │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│   execute(workflowId, scope)                                     │
│         │                                                        │
│         ▼                                                        │
│   ┌───────────────────┐                                         │
│   │ Identify Steps    │ ◄── Based on scope                      │
│   │ to Compensate     │                                         │
│   └─────────┬─────────┘                                         │
│             │                                                    │
│             ▼                                                    │
│   ┌───────────────────┐                                         │
│   │ Reverse Order     │ ◄── Last completed → First             │
│   └─────────┬─────────┘                                         │
│             │                                                    │
│             ▼                                                    │
│   ┌───────────────────┐                                         │
│   │ For each step:    │                                         │
│   │ Dispatch comp job │                                         │
│   └─────────┬─────────┘                                         │
│             │                                                    │
│   ┌─────────┼─────────┐                                         │
│   │         │         │                                         │
│   ▼         ▼         ▼                                         │
│ Comp 3   Comp 2   Comp 1                                        │
│ (last)   (middle) (first)                                       │
│   │         │         │                                         │
│   └─────────┴─────────┘                                         │
│             │                                                    │
│             ▼                                                    │
│   ┌───────────────────┐                                         │
│   │ Track Results     │                                         │
│   └─────────┬─────────┘                                         │
│             │                                                    │
│        ┌────┴────┐                                              │
│        │         │                                              │
│        ▼         ▼                                              │
│   All pass    Failure                                           │
│        │         │                                              │
│        ▼         ▼                                              │
│   Compensated  CompensationFailed                               │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

## Data Flow

### Workflow Execution Flow

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                         Workflow Execution Flow                              │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│   1. Start Workflow                                                          │
│   ┌──────────────────────────────────────────────────────────────────────┐  │
│   │ WorkflowManager.start()                                               │  │
│   │     → Create WorkflowInstance                                         │  │
│   │     → Save to workflows table                                         │  │
│   │     → Dispatch WorkflowCreated event                                  │  │
│   │     → Call WorkflowAdvancer.advance()                                 │  │
│   └──────────────────────────────────────────────────────────────────────┘  │
│                                    │                                         │
│                                    ▼                                         │
│   2. Advance Workflow                                                        │
│   ┌──────────────────────────────────────────────────────────────────────┐  │
│   │ WorkflowAdvancer.advance()                                            │  │
│   │     → Acquire lock on workflow                                        │  │
│   │     → Find next executable step                                       │  │
│   │     → Evaluate step conditions                                        │  │
│   │     → Create StepRun record                                           │  │
│   │     → Dispatch via StepDispatcher                                     │  │
│   │     → Release lock                                                    │  │
│   └──────────────────────────────────────────────────────────────────────┘  │
│                                    │                                         │
│                                    ▼                                         │
│   3. Job Execution                                                           │
│   ┌──────────────────────────────────────────────────────────────────────┐  │
│   │ Queue Worker picks up job                                             │  │
│   │     → JobLifecycleMiddleware starts                                   │  │
│   │     → Job.execute() runs                                              │  │
│   │     → Store outputs                                                   │  │
│   │     → JobLifecycleMiddleware finalizes                                │  │
│   └──────────────────────────────────────────────────────────────────────┘  │
│                                    │                                         │
│                                    ▼                                         │
│   4. Step Finalization                                                       │
│   ┌──────────────────────────────────────────────────────────────────────┐  │
│   │ StepFinalizer.finalize()                                              │  │
│   │     → Update StepRun state                                            │  │
│   │     → Handle success/failure                                          │  │
│   │     → Check if workflow complete                                      │  │
│   │     → If not complete: WorkflowAdvancer.advance()                     │  │
│   └──────────────────────────────────────────────────────────────────────┘  │
│                                    │                                         │
│                                    ▼                                         │
│   5. Repeat until complete                                                   │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Layer Boundaries

### Contracts (Interfaces)

Location: `src/Contracts/`

- Pure interfaces with no dependencies
- Define contracts between layers
- Value objects and enums

```php
// Example: WorkflowRepository interface
interface WorkflowRepository
{
    public function find(WorkflowId $id): ?WorkflowInstance;
    public function save(WorkflowInstance $workflow): void;
    public function findByState(WorkflowState $state): Collection;
}
```

### Domain Layer

Location: `src/Domain/`

- Business logic and rules
- Domain events
- Entity state management
- No infrastructure dependencies

```php
// Example: WorkflowInstance entity
final class WorkflowInstance
{
    public function start(): void
    {
        $this->assertCanTransitionTo(WorkflowState::Running);
        $this->state = WorkflowState::Running;
        $this->startedAt = CarbonImmutable::now();
        $this->recordEvent(new WorkflowStarted($this->id));
    }
}
```

### Application Layer

Location: `src/Application/`

- Use case orchestration
- Service coordination
- Transaction management

```php
// Example: WorkflowManagementService
final class WorkflowManagementService implements WorkflowManager
{
    public function start(DefinitionKey $definition): WorkflowInstance
    {
        return DB::transaction(function () use ($definition) {
            $workflow = WorkflowInstance::create($definition);
            $this->repository->save($workflow);
            $this->advancer->advance($workflow->id);
            return $workflow;
        });
    }
}
```

### Infrastructure Layer

Location: `src/Infrastructure/`

- Database repositories
- Queue integration
- HTTP controllers
- External service adapters

```php
// Example: EloquentWorkflowRepository
final class EloquentWorkflowRepository implements WorkflowRepository
{
    public function find(WorkflowId $id): ?WorkflowInstance
    {
        $model = WorkflowModel::find($id->value);
        return $model ? $this->hydrator->hydrate($model) : null;
    }
}
```

## Event Flow

```
┌─────────────────────────────────────────────────────────────────┐
│                        Event Flow                                │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│   Domain Entity                                                  │
│        │                                                         │
│        │ recordEvent()                                           │
│        ▼                                                         │
│   ┌─────────────────┐                                           │
│   │ Event Collected │                                           │
│   │ in Entity       │                                           │
│   └────────┬────────┘                                           │
│            │                                                     │
│            │ Repository.save()                                   │
│            ▼                                                     │
│   ┌─────────────────┐                                           │
│   │ Entity Persisted│                                           │
│   └────────┬────────┘                                           │
│            │                                                     │
│            │ releaseEvents()                                     │
│            ▼                                                     │
│   ┌─────────────────┐                                           │
│   │ Events          │                                           │
│   │ Dispatched      │                                           │
│   └────────┬────────┘                                           │
│            │                                                     │
│   ┌────────┼────────┬────────┐                                  │
│   │        │        │        │                                  │
│   ▼        ▼        ▼        ▼                                  │
│ Listener Listener Listener Logger                               │
│ (Alert)  (Metrics)(Audit)  (Debug)                              │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

## Key Design Decisions

### 1. Immutable Value Objects

All identifiers and domain concepts use immutable value objects:

```php
final readonly class WorkflowId
{
    private function __construct(public string $value) {}

    public static function generate(): self
    {
        return new self(Uuid::uuid7()->toString());
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
```

**Rationale:** Prevents accidental mutation, ensures identity semantics, enables comparison.

### 2. Event Sourcing Lite

Entities record domain events but state is stored directly:

```php
final class WorkflowInstance
{
    private array $pendingEvents = [];

    protected function recordEvent(object $event): void
    {
        $this->pendingEvents[] = $event;
    }

    public function releaseEvents(): array
    {
        $events = $this->pendingEvents;
        $this->pendingEvents = [];
        return $events;
    }
}
```

**Rationale:** Gets benefits of domain events without full event sourcing complexity.

### 3. Lock-Based Concurrency

Workflow advancement is protected by locks:

```php
$lock = $this->lockFactory->create("workflow:{$workflowId}");

if (!$lock->acquire()) {
    throw new WorkflowLockedException($workflowId);
}

try {
    // Advance workflow
} finally {
    $lock->release();
}
```

**Rationale:** Prevents race conditions when multiple jobs complete simultaneously.

### 4. Hydrator Pattern

Separates persistence models from domain entities:

```php
final class WorkflowHydrator
{
    public function hydrate(WorkflowModel $model): WorkflowInstance
    {
        return WorkflowInstance::reconstitute(
            id: WorkflowId::fromString($model->id),
            state: WorkflowState::from($model->state),
            // ...
        );
    }

    public function dehydrate(WorkflowInstance $entity): array
    {
        return [
            'id' => $entity->id->value,
            'state' => $entity->state->value,
            // ...
        ];
    }
}
```

**Rationale:** Keeps domain clean of persistence concerns, enables testing with in-memory fakes.

## Next Steps

- [State Machine](state-machine.md) - Detailed state transitions
- [Job Execution](job-execution.md) - Queue integration
- [Concurrency Control](concurrency.md) - Locking and idempotency
- [Database Schema](database-schema.md) - Table structure
