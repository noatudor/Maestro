# Events Reference

Maestro dispatches domain events throughout workflow execution. Use these events for monitoring, logging, alerting, and integration with external systems.

## Event Categories

- [Workflow Events](#workflow-events) - Workflow lifecycle changes
- [Step Events](#step-events) - Step execution events
- [Job Events](#job-events) - Individual job events
- [Polling Events](#polling-events) - Polling step events
- [Compensation Events](#compensation-events) - Rollback events
- [Trigger Events](#trigger-events) - External trigger events
- [Resolution Events](#resolution-events) - Manual intervention events
- [Branching Events](#branching-events) - Conditional execution events

## Workflow Events

### WorkflowCreated

Dispatched when a new workflow instance is created.

```php
use Maestro\Workflow\Domain\Events\WorkflowCreated;

Event::listen(WorkflowCreated::class, function (WorkflowCreated $event) {
    Log::info('Workflow created', [
        'workflow_id' => $event->workflowId->value,
        'definition_key' => $event->definitionKey->value,
        'definition_version' => $event->definitionVersion,
    ]);
});
```

### WorkflowStarted

Dispatched when workflow execution begins.

```php
use Maestro\Workflow\Domain\Events\WorkflowStarted;

$event->workflowId;    // WorkflowId
$event->startedAt;     // CarbonImmutable
```

### WorkflowSucceeded

Dispatched when workflow completes successfully.

```php
use Maestro\Workflow\Domain\Events\WorkflowSucceeded;

Event::listen(WorkflowSucceeded::class, function (WorkflowSucceeded $event) {
    Metrics::increment('workflows.completed');
    Metrics::timing('workflows.duration', $event->durationSeconds);
});
```

### WorkflowFailed

Dispatched when workflow fails.

```php
use Maestro\Workflow\Domain\Events\WorkflowFailed;

Event::listen(WorkflowFailed::class, function (WorkflowFailed $event) {
    Alert::send("Workflow {$event->workflowId->value} failed", [
        'failed_step' => $event->failedStepKey?->value,
        'error' => $event->errorMessage,
    ]);
});
```

### WorkflowPaused

Dispatched when workflow is paused.

```php
$event->workflowId;
$event->pausedAt;
$event->reason;      // ?string
```

### WorkflowResumed

Dispatched when workflow resumes from paused state.

```php
$event->workflowId;
$event->resumedAt;
$event->previousState;  // WorkflowState
```

### WorkflowCancelled

Dispatched when workflow is cancelled.

```php
$event->workflowId;
$event->cancelledAt;
$event->reason;         // ?string
$event->compensated;    // bool
```

### WorkflowTerminatedEarly

Dispatched when workflow terminates early via termination condition.

```php
$event->workflowId;
$event->terminatedAt;
$event->reason;        // string
$event->atStepKey;     // StepKey
```

### WorkflowAwaitingResolution

Dispatched when failed workflow awaits manual decision.

```php
Event::listen(WorkflowAwaitingResolution::class, function ($event) {
    // Notify on-call team
    Notification::send(
        Team::onCall(),
        new WorkflowNeedsAttentionNotification($event->workflowId)
    );
});
```

### WorkflowAwaitingTrigger

Dispatched when workflow pauses to wait for external trigger.

```php
$event->workflowId;
$event->triggerKey;     // string
$event->timeoutAt;      // CarbonImmutable
```

### WorkflowAutoResumed

Dispatched when workflow auto-resumes on schedule.

```php
$event->workflowId;
$event->scheduledFor;   // CarbonImmutable
$event->resumedAt;      // CarbonImmutable
```

## Step Events

### StepStarted

Dispatched when step execution begins.

```php
use Maestro\Workflow\Domain\Events\StepStarted;

$event->workflowId;
$event->stepKey;
$event->stepRunId;
$event->attemptNumber;
$event->startedAt;
```

### StepSucceeded

Dispatched when step completes successfully.

```php
$event->workflowId;
$event->stepKey;
$event->stepRunId;
$event->completedAt;
$event->durationMs;
```

### StepFailed

Dispatched when step ultimately fails (after retries).

```php
Event::listen(StepFailed::class, function (StepFailed $event) {
    Log::error('Step failed', [
        'workflow_id' => $event->workflowId->value,
        'step_key' => $event->stepKey->value,
        'error' => $event->errorMessage,
        'attempt' => $event->attemptNumber,
    ]);
});
```

### StepRetried

Dispatched when step is scheduled for retry.

```php
$event->workflowId;
$event->stepKey;
$event->attemptNumber;
$event->scheduledFor;   // CarbonImmutable
$event->delaySeconds;
```

### StepSkipped

Dispatched when step is skipped.

```php
$event->workflowId;
$event->stepKey;
$event->reason;         // SkipReason enum
$event->skippedAt;
```

SkipReason values:
- `ConditionFalse` - Step condition evaluated to false
- `NotOnActiveBranch` - Step not on selected branch
- `TerminatedEarly` - Workflow terminated before step

### StepRunSuperseded

Dispatched when step run is superseded by a retry.

```php
$event->workflowId;
$event->stepKey;
$event->stepRunId;
$event->supersededBy;   // StepRunId
$event->reason;
```

## Job Events

### JobDispatched

Dispatched when job is queued.

```php
$event->workflowId;
$event->stepKey;
$event->jobId;
$event->jobClass;
$event->queueName;
```

### JobStarted

Dispatched when job execution begins.

```php
$event->workflowId;
$event->stepKey;
$event->jobId;
$event->startedAt;
```

### JobSucceeded

Dispatched when job completes successfully.

```php
Event::listen(JobSucceeded::class, function (JobSucceeded $event) {
    Metrics::increment('jobs.completed');
    Metrics::timing('jobs.duration', $event->durationMs);
});
```

### JobFailed

Dispatched when job fails.

```php
$event->workflowId;
$event->stepKey;
$event->jobId;
$event->exception;      // Throwable
$event->failedAt;
```

## Polling Events

### PollAttempted

Dispatched after each poll attempt.

```php
$event->workflowId;
$event->stepKey;
$event->attemptNumber;
$event->result;         // 'continue', 'completed', 'aborted'
$event->message;        // ?string
```

### PollCompleted

Dispatched when polling finishes successfully.

```php
$event->workflowId;
$event->stepKey;
$event->totalAttempts;
$event->completedAt;
```

### PollTimedOut

Dispatched when polling times out.

```php
Event::listen(PollTimedOut::class, function (PollTimedOut $event) {
    Alert::send("Polling timed out for workflow {$event->workflowId->value}");
});
```

### PollAborted

Dispatched when polling is aborted.

```php
$event->workflowId;
$event->stepKey;
$event->reason;
$event->attemptNumber;
```

## Compensation Events

### CompensationStarted

Dispatched when compensation phase begins.

```php
$event->workflowId;
$event->scope;              // CompensationScope
$event->stepsToCompensate;  // array of step keys
```

### CompensationStepStarted

Dispatched when individual compensation step begins.

```php
$event->workflowId;
$event->stepKey;
$event->compensationRunId;
```

### CompensationStepSucceeded

Dispatched when compensation step succeeds.

```php
$event->workflowId;
$event->stepKey;
$event->compensationRunId;
$event->completedAt;
```

### CompensationStepFailed

Dispatched when compensation step fails.

```php
Event::listen(CompensationStepFailed::class, function ($event) {
    Alert::critical("Compensation failed", [
        'workflow_id' => $event->workflowId->value,
        'step_key' => $event->stepKey->value,
        'error' => $event->errorMessage,
    ]);
});
```

### CompensationCompleted

Dispatched when all compensation completes successfully.

```php
$event->workflowId;
$event->compensatedSteps;
$event->completedAt;
```

### CompensationFailed

Dispatched when compensation fails.

```php
$event->workflowId;
$event->failedSteps;
$event->completedSteps;
```

## Trigger Events

### TriggerReceived

Dispatched when external trigger is received.

```php
$event->workflowId;
$event->triggerKey;
$event->payload;        // TriggerPayload
$event->accepted;       // bool
$event->receivedAt;
```

### TriggerValidationFailed

Dispatched when trigger fails validation.

```php
$event->workflowId;
$event->triggerKey;
$event->payload;
$event->reason;
```

### TriggerTimedOut

Dispatched when waiting for trigger times out.

```php
$event->workflowId;
$event->triggerKey;
$event->timeoutPolicy;  // TriggerTimeoutPolicy
$event->timedOutAt;
```

## Resolution Events

### ResolutionDecisionMade

Dispatched when manual resolution decision is made.

```php
Event::listen(ResolutionDecisionMade::class, function ($event) {
    AuditLog::create([
        'entity_type' => 'workflow',
        'entity_id' => $event->workflowId->value,
        'action' => "resolution:{$event->decision->value}",
        'reason' => $event->reason,
        'user_id' => $event->madeBy,
        'timestamp' => $event->madeAt,
    ]);
});
```

### AutoRetryScheduled

Dispatched when auto-retry is scheduled.

```php
$event->workflowId;
$event->attemptNumber;
$event->scheduledFor;
$event->reason;
```

### AutoRetryExhausted

Dispatched when auto-retries are exhausted.

```php
Event::listen(AutoRetryExhausted::class, function ($event) {
    Alert::warning("Auto-retries exhausted for workflow {$event->workflowId->value}");
});
```

### RetryFromStepInitiated

Dispatched when retry-from-step is initiated.

```php
$event->workflowId;
$event->stepKey;
$event->retryMode;
$event->supersededSteps;
```

### RetryFromStepCompleted

Dispatched when retry-from-step completes setup.

```php
$event->workflowId;
$event->stepKey;
$event->newStepRunId;
```

## Branching Events

### BranchEvaluated

Dispatched when branch condition is evaluated.

```php
$event->workflowId;
$event->stepKey;
$event->branchType;         // BranchType
$event->selectedBranches;   // array of branch keys
```

## Subscribing to Events

### Event Listener

```php
<?php

namespace App\Listeners;

use Maestro\Workflow\Domain\Events\WorkflowFailed;

class HandleWorkflowFailure
{
    public function handle(WorkflowFailed $event): void
    {
        // Send alert
        Alert::send("Workflow failed: {$event->workflowId->value}");

        // Update metrics
        Metrics::increment('workflows.failed');

        // Log for debugging
        Log::error('Workflow failed', [
            'workflow_id' => $event->workflowId->value,
            'step_key' => $event->failedStepKey?->value,
            'error' => $event->errorMessage,
        ]);
    }
}
```

### Event Subscriber

```php
<?php

namespace App\Listeners;

use Illuminate\Events\Dispatcher;
use Maestro\Workflow\Domain\Events\{
    WorkflowStarted,
    WorkflowSucceeded,
    WorkflowFailed,
};

class WorkflowMetricsSubscriber
{
    public function subscribe(Dispatcher $events): array
    {
        return [
            WorkflowStarted::class => 'handleStarted',
            WorkflowSucceeded::class => 'handleSucceeded',
            WorkflowFailed::class => 'handleFailed',
        ];
    }

    public function handleStarted(WorkflowStarted $event): void
    {
        Metrics::increment('workflows.started');
    }

    public function handleSucceeded(WorkflowSucceeded $event): void
    {
        Metrics::increment('workflows.succeeded');
        Metrics::timing('workflows.duration', $event->durationSeconds * 1000);
    }

    public function handleFailed(WorkflowFailed $event): void
    {
        Metrics::increment('workflows.failed');
    }
}
```

### Register in EventServiceProvider

```php
protected $listen = [
    WorkflowFailed::class => [
        HandleWorkflowFailure::class,
    ],
];

protected $subscribe = [
    WorkflowMetricsSubscriber::class,
];
```

## Configuration

Enable/disable event categories in `config/maestro.php`:

```php
'events' => [
    'workflow_events' => true,  // WorkflowStarted, WorkflowFailed, etc.
    'step_events' => true,      // StepStarted, StepFailed, etc.
    'job_events' => true,       // JobDispatched, JobFailed, etc.
],
```

## Common Patterns

### Alerting Dashboard

```php
// app/Listeners/WorkflowAlertSubscriber.php
class WorkflowAlertSubscriber
{
    public function subscribe(Dispatcher $events): array
    {
        return [
            WorkflowFailed::class => 'alertOnFailure',
            AutoRetryExhausted::class => 'alertOnRetryExhausted',
            CompensationFailed::class => 'alertOnCompensationFailed',
            TriggerTimedOut::class => 'alertOnTriggerTimeout',
        ];
    }

    public function alertOnFailure(WorkflowFailed $event): void
    {
        if ($this->isHighPriority($event->workflowId)) {
            PagerDuty::alert("High priority workflow failed");
        } else {
            Slack::notify("#ops-alerts", "Workflow failed: {$event->workflowId->value}");
        }
    }
    // ...
}
```

### Audit Logging

```php
class AuditLogSubscriber
{
    private array $auditableEvents = [
        WorkflowCreated::class,
        WorkflowCancelled::class,
        ResolutionDecisionMade::class,
        CompensationStarted::class,
    ];

    public function subscribe(Dispatcher $events): array
    {
        return collect($this->auditableEvents)
            ->mapWithKeys(fn ($event) => [$event => 'log'])
            ->all();
    }

    public function log(object $event): void
    {
        AuditLog::create([
            'event_type' => get_class($event),
            'workflow_id' => $event->workflowId->value,
            'payload' => json_encode($event),
            'timestamp' => now(),
        ]);
    }
}
```

### Webhook Delivery

```php
Event::listen(WorkflowSucceeded::class, function ($event) {
    $workflow = Workflow::find($event->workflowId);

    if ($webhook = $workflow->metadata['webhook_url'] ?? null) {
        Http::post($webhook, [
            'event' => 'workflow.completed',
            'workflow_id' => $event->workflowId->value,
            'completed_at' => $event->completedAt->toIso8601String(),
        ]);
    }
});
```

## Next Steps

- [Console Commands](console-commands.md) - CLI tools
- [API Reference](api-reference.md) - REST endpoints
- [Failure Handling](../guide/failure-handling/overview.md) - Error recovery
