# State Machine

Maestro uses explicit state machines for workflows, steps, and compensation runs. This document details all states and valid transitions.

## Workflow States

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                       Workflow State Machine                                 │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│                          ┌─────────┐                                        │
│                          │ Pending │                                        │
│                          └────┬────┘                                        │
│                               │ start()                                     │
│                               ▼                                             │
│                          ┌─────────┐                                        │
│               ┌──────────│ Running │──────────┐                             │
│               │          └────┬────┘          │                             │
│               │               │               │                             │
│     pause()   │    succeed()  │  fail()      │ cancel()                    │
│               │               │               │                             │
│               ▼               │               ▼                             │
│          ┌─────────┐         │          ┌───────────┐                      │
│          │ Paused  │         │          │ Cancelled │ (terminal)           │
│          └────┬────┘         │          └───────────┘                      │
│               │              │                                              │
│     resume()  │              │                                              │
│               │              │                                              │
│               ▼              │                                              │
│          ┌─────────┐         │                                              │
│          │ Running │◄────────┘                                              │
│          └────┬────┘                                                        │
│               │                                                             │
│       ┌───────┼───────┐                                                     │
│       │       │       │                                                     │
│       ▼       │       ▼                                                     │
│  ┌─────────┐  │  ┌────────┐                                                │
│  │Succeeded│  │  │ Failed │ ◄── Can retry or compensate                    │
│  └─────────┘  │  └───┬────┘                                                │
│   (terminal)  │      │                                                      │
│               │      │ compensate()                                         │
│               │      ▼                                                      │
│               │ ┌─────────────┐                                             │
│               │ │ Compensating│                                             │
│               │ └──────┬──────┘                                             │
│               │        │                                                    │
│               │   ┌────┴────┐                                               │
│               │   │         │                                               │
│               │   ▼         ▼                                               │
│               │ ┌──────────┐ ┌────────────────────┐                        │
│               │ │Compensated│ │ CompensationFailed │                        │
│               │ └──────────┘ └────────────────────┘                        │
│               │  (terminal)    (can retry compensation)                     │
│               │                                                             │
│               ▼                                                             │
│          (see above)                                                        │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

### State Definitions

| State | Description | Terminal |
|-------|-------------|----------|
| `Pending` | Created but not started | No |
| `Running` | Actively executing steps | No |
| `Paused` | Manually paused or awaiting trigger | No |
| `Succeeded` | All steps completed successfully | Yes |
| `Failed` | Step failed, awaiting resolution | No |
| `Cancelled` | Manually cancelled | Yes |
| `Compensating` | Running compensation jobs | No |
| `Compensated` | Compensation completed | Yes |
| `CompensationFailed` | Compensation failed | No |

### Valid Transitions

```php
public function canTransitionTo(WorkflowState $newState): bool
{
    return match ($this->state) {
        WorkflowState::Pending => in_array($newState, [
            WorkflowState::Running,
            WorkflowState::Cancelled,
        ]),

        WorkflowState::Running => in_array($newState, [
            WorkflowState::Paused,
            WorkflowState::Succeeded,
            WorkflowState::Failed,
            WorkflowState::Cancelled,
            WorkflowState::Compensating,
        ]),

        WorkflowState::Paused => in_array($newState, [
            WorkflowState::Running,
            WorkflowState::Cancelled,
            WorkflowState::Compensating,
        ]),

        WorkflowState::Failed => in_array($newState, [
            WorkflowState::Running,      // Retry
            WorkflowState::Cancelled,
            WorkflowState::Compensating,
        ]),

        WorkflowState::Compensating => in_array($newState, [
            WorkflowState::Compensated,
            WorkflowState::CompensationFailed,
        ]),

        WorkflowState::CompensationFailed => in_array($newState, [
            WorkflowState::Compensating, // Retry compensation
            WorkflowState::Compensated,  // Skip remaining
        ]),

        // Terminal states
        WorkflowState::Succeeded,
        WorkflowState::Cancelled,
        WorkflowState::Compensated => false,
    };
}
```

## Step States

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                          Step State Machine                                  │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│                          ┌─────────┐                                        │
│                          │ Pending │                                        │
│                          └────┬────┘                                        │
│                               │                                             │
│               ┌───────────────┼───────────────┐                             │
│               │               │               │                             │
│          start()         startPolling()     skip()                          │
│               │               │               │                             │
│               ▼               ▼               ▼                             │
│          ┌─────────┐    ┌─────────┐     ┌─────────┐                        │
│          │ Running │    │ Polling │     │ Skipped │ (terminal)             │
│          └────┬────┘    └────┬────┘     └─────────┘                        │
│               │              │                                              │
│          ┌────┴────┐    ┌────┴────┐                                        │
│          │         │    │         │                                        │
│          ▼         ▼    ▼         ▼                                        │
│    ┌──────────┐ ┌──────┐ ┌──────────┐ ┌─────────┐                         │
│    │Succeeded │ │Failed│ │Succeeded │ │ TimedOut│                         │
│    └──────────┘ └──────┘ └──────────┘ └─────────┘                         │
│     (terminal)  (term*)   (terminal)  (terminal)                           │
│                                                                              │
│   * Failed can transition to Superseded if retried                          │
│                                                                              │
│                     Supersession                                             │
│                          │                                                   │
│   Any non-terminal ──────┼───────► ┌────────────┐                          │
│   state                  │         │ Superseded │ (terminal)               │
│                          │         └────────────┘                          │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

### State Definitions

| State | Description | Terminal |
|-------|-------------|----------|
| `Pending` | Not yet started | No |
| `Running` | Job(s) executing | No |
| `Polling` | Polling job active | No |
| `Succeeded` | Completed successfully | Yes |
| `Failed` | Failed (after retries) | Yes* |
| `TimedOut` | Polling timed out | Yes |
| `Skipped` | Skipped (condition/branch) | Yes |
| `Superseded` | Replaced by retry | Yes |

*Failed steps can be superseded by retry-from-step.

### Skip Reasons

```php
enum SkipReason: string
{
    case ConditionFalse = 'condition_false';     // Step condition returned false
    case NotOnActiveBranch = 'not_on_branch';   // Step not on selected branch
    case TerminatedEarly = 'terminated_early';  // Workflow terminated early
}
```

## Compensation Run States

```
┌─────────────────────────────────────────────────────────────────┐
│                  Compensation Run State Machine                  │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│                     ┌─────────┐                                 │
│                     │ Pending │                                 │
│                     └────┬────┘                                 │
│                          │ start()                              │
│                          ▼                                      │
│                     ┌─────────┐                                 │
│                     │ Running │                                 │
│                     └────┬────┘                                 │
│                          │                                      │
│               ┌──────────┴──────────┐                           │
│               │                     │                           │
│          succeed()              fail()                          │
│               │                     │                           │
│               ▼                     ▼                           │
│          ┌──────────┐         ┌────────┐                       │
│          │Succeeded │         │ Failed │                       │
│          └──────────┘         └───┬────┘                       │
│           (terminal)              │                             │
│                                   │ skip()                      │
│                                   ▼                             │
│                              ┌─────────┐                        │
│                              │ Skipped │                        │
│                              └─────────┘                        │
│                               (terminal)                        │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

## State Transition Events

Each transition dispatches a domain event:

```php
// Workflow transitions
WorkflowState::Pending → Running      → WorkflowStarted
WorkflowState::Running → Paused       → WorkflowPaused
WorkflowState::Paused → Running       → WorkflowResumed
WorkflowState::Running → Succeeded    → WorkflowSucceeded
WorkflowState::Running → Failed       → WorkflowFailed
WorkflowState::Running → Cancelled    → WorkflowCancelled
WorkflowState::Failed → Compensating  → CompensationStarted
WorkflowState::Compensating → Compensated → CompensationCompleted

// Step transitions
StepState::Pending → Running          → StepStarted
StepState::Running → Succeeded        → StepSucceeded
StepState::Running → Failed           → StepFailed
StepState::Pending → Skipped          → StepSkipped
StepState::* → Superseded             → StepRunSuperseded
```

## Concurrent State Changes

Maestro handles concurrent state changes through locking:

```
┌─────────────────────────────────────────────────────────────────┐
│              Concurrent State Change Handling                    │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│   Job A completes              Job B completes                   │
│        │                            │                            │
│        ▼                            ▼                            │
│   ┌─────────────────┐         ┌─────────────────┐               │
│   │ Request Lock    │         │ Request Lock    │               │
│   └────────┬────────┘         └────────┬────────┘               │
│            │                           │                         │
│            ▼                           │                         │
│   ┌─────────────────┐                  │                         │
│   │ Lock Acquired   │                  │ (waiting)               │
│   └────────┬────────┘                  │                         │
│            │                           │                         │
│            ▼                           │                         │
│   ┌─────────────────┐                  │                         │
│   │ Update State    │                  │                         │
│   │ Advance Workflow│                  │                         │
│   └────────┬────────┘                  │                         │
│            │                           │                         │
│            ▼                           │                         │
│   ┌─────────────────┐                  │                         │
│   │ Release Lock    │─────────────────►│                         │
│   └─────────────────┘                  │                         │
│                                        ▼                         │
│                               ┌─────────────────┐                │
│                               │ Lock Acquired   │                │
│                               └────────┬────────┘                │
│                                        │                         │
│                                        ▼                         │
│                               ┌─────────────────┐                │
│                               │ Update State    │                │
│                               │ Advance Workflow│                │
│                               └────────┬────────┘                │
│                                        │                         │
│                                        ▼                         │
│                               ┌─────────────────┐                │
│                               │ Release Lock    │                │
│                               └─────────────────┘                │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

## Implementation Details

### State Transition Method

```php
final class WorkflowInstance
{
    public function transitionTo(WorkflowState $newState): void
    {
        if (!$this->canTransitionTo($newState)) {
            throw InvalidStateTransitionException::create(
                entityType: 'workflow',
                entityId: $this->id->value,
                currentState: $this->state->value,
                attemptedState: $newState->value,
            );
        }

        $previousState = $this->state;
        $this->state = $newState;
        $this->updatedAt = CarbonImmutable::now();

        $this->recordTransitionEvent($previousState, $newState);
    }

    private function recordTransitionEvent(
        WorkflowState $from,
        WorkflowState $to,
    ): void {
        $event = match ($to) {
            WorkflowState::Running => new WorkflowStarted($this->id),
            WorkflowState::Paused => new WorkflowPaused($this->id),
            WorkflowState::Succeeded => new WorkflowSucceeded($this->id),
            WorkflowState::Failed => new WorkflowFailed($this->id),
            WorkflowState::Cancelled => new WorkflowCancelled($this->id),
            // ...
        };

        $this->recordEvent($event);
    }
}
```

### Terminal State Checks

```php
public function isTerminal(): bool
{
    return in_array($this->state, [
        WorkflowState::Succeeded,
        WorkflowState::Cancelled,
        WorkflowState::Compensated,
    ], true);
}

public function canBeAdvanced(): bool
{
    return $this->state === WorkflowState::Running;
}

public function canBeResumed(): bool
{
    return $this->state === WorkflowState::Paused;
}

public function canBeRetried(): bool
{
    return $this->state === WorkflowState::Failed;
}
```

## Next Steps

- [Job Execution](job-execution.md) - Queue integration details
- [Concurrency Control](concurrency.md) - Locking mechanisms
- [Architecture Overview](overview.md) - High-level design
