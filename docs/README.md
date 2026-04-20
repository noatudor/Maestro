# Maestro Workflow Orchestration

> High-performance Laravel workflow orchestration for complex business processes

Maestro is a production-ready workflow orchestration package for Laravel, designed to handle millions of workflows with typed data passing, explicit state tracking, and full operational transparency.

## Why Maestro?

Building reliable business processes in distributed systems is hard. You need to handle failures gracefully, coordinate parallel operations, wait for external events, and maintain visibility into what's happening. Maestro solves these challenges with a battle-tested architecture.

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                         What Maestro Provides                                │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  Traditional Approach              With Maestro                              │
│  ──────────────────────           ─────────────                              │
│  ✗ Jobs fail silently             ✓ Every state change tracked              │
│  ✗ No visibility into progress    ✓ Full audit trail with events            │
│  ✗ Manual retry logic             ✓ Configurable retry with backoff         │
│  ✗ No rollback capability         ✓ Saga pattern compensation               │
│  ✗ Hard to wait for external      ✓ Built-in external triggers              │
│    events                         ✓ Webhook integration                      │
│  ✗ Complex parallel coordination  ✓ Fan-out/fan-in patterns                 │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Key Features

### Three Step Types for Every Use Case

| Step Type | Use Case | Example |
|-----------|----------|---------|
| **Single Job** | Sequential operations | Process payment, send email |
| **Fan-Out** | Parallel processing | Process 1000 items concurrently |
| **Polling** | Wait for external state | Check payment confirmation |

### Comprehensive Failure Handling

```
Step Fails → Apply Policy → Retry/Skip/Pause/Fail → Resolution Strategy
                ↓
        ┌───────────────┐
        │ 5 Policies:   │
        │ • FailWorkflow│
        │ • PauseWork.. │
        │ • RetryStep   │
        │ • SkipStep    │
        │ • Continue... │
        └───────────────┘
```

- **Step-level policies** - Control immediate response to failures
- **Workflow-level strategies** - Auto-retry, auto-compensate, or await decision
- **Compensation (Saga pattern)** - Rollback completed steps when needed

### External Triggers & Webhooks

Pause workflows to wait for human approval, webhook callbacks, or scheduled events:

```php
->step('await_approval')
    ->job(RequestApprovalJob::class)
    ->pauseTrigger(new PauseTriggerDefinition(
        triggerKey: 'manager-approval',
        timeoutSeconds: 259200,  // 3 days
        timeoutPolicy: TriggerTimeoutPolicy::SendReminder,
    ))
    ->build()
```

### Branching & Conditional Execution

Route workflows based on runtime conditions:

```php
->step('route_order')
    ->branch(new BranchDefinition(
        conditionClass: OrderTypeCondition::class,
        branchType: BranchType::Exclusive,
        branches: [
            'digital' => ['deliver_digital'],
            'physical' => ['ship_physical'],
        ],
    ))
```

### 41 Domain Events for Full Observability

Every state change emits a typed event for monitoring, alerting, and audit:

- `WorkflowStarted`, `WorkflowSucceeded`, `WorkflowFailed`
- `StepStarted`, `StepRetried`, `StepSkipped`
- `CompensationStarted`, `CompensationCompleted`
- `TriggerReceived`, `TriggerTimedOut`
- And 31 more...

## Quick Example

```php
<?php

declare(strict_types=1);

use Maestro\Workflow\Definition\Builders\WorkflowDefinitionBuilder;
use Maestro\Workflow\Definition\WorkflowDefinition;
use Maestro\Workflow\Enums\FailurePolicy;

final class OrderWorkflow extends WorkflowDefinition
{
    public function define(WorkflowDefinitionBuilder $builder): void
    {
        $builder
            ->name('Order Processing')
            ->version(1)

            ->step('validate')
                ->job(ValidateOrderJob::class)
                ->produces(ValidationOutput::class)
                ->failurePolicy(FailurePolicy::FailWorkflow)
                ->build()

            ->step('payment')
                ->job(ProcessPaymentJob::class)
                ->requires('validate', ValidationOutput::class)
                ->failurePolicy(FailurePolicy::RetryStep)
                ->retryable(maxAttempts: 3, delaySeconds: 30)
                ->compensation(RefundPaymentJob::class)
                ->build()

            ->fanOut('fulfill_items')
                ->job(FulfillItemJob::class)
                ->items(fn($ctx, $out) => $ctx->order->items)
                ->successCriteria(SuccessCriteria::All)
                ->parallelism(10)
                ->build()

            ->step('notify')
                ->job(SendConfirmationJob::class)
                ->failurePolicy(FailurePolicy::SkipStep)
                ->build();
    }
}
```

```php
// Start the workflow
$workflow = Maestro::startWorkflow(
    DefinitionKey::fromString('order-processing'),
);

// Check status
$status = Maestro::getStatus($workflow->id);
echo "State: {$status->state->value}";
echo "Current step: {$status->currentStepKey?->value}";
```

## Requirements

- **PHP 8.4+** (latest stable)
- **Laravel 11.x or 12.x**
- **Database**: MySQL 8.0+, PostgreSQL 14+, or SQLite
- **Queue**: Any Laravel-supported driver (Redis recommended)

## Documentation

### Getting Started
- [Installation](getting-started/installation.md) - Setup and configuration
- [Quick Start](getting-started/quick-start.md) - Your first workflow in 5 minutes

### Core Concepts
- [Workflows](concepts/workflows.md) - Understanding workflow lifecycle
- [Steps](concepts/steps.md) - Step types and configuration
- [Jobs](concepts/jobs.md) - Job implementation patterns
- [Typed Outputs](concepts/typed-outputs.md) - Type-safe data passing
- [State Machine](concepts/state-machine.md) - States and transitions

### Step Types
- [Single Job Steps](guide/step-types/single-job.md) - Sequential operations
- [Fan-Out Steps](guide/step-types/fan-out.md) - Parallel processing
- [Polling Steps](guide/step-types/polling.md) - Long-running operations

### Failure Handling
- [Overview](guide/failure-handling/overview.md) - Failure handling architecture
- [Step Policies](guide/failure-handling/step-policies.md) - Immediate failure response
- [Workflow Resolution](guide/failure-handling/workflow-resolution.md) - Recovery strategies
- [Compensation](guide/failure-handling/compensation.md) - Saga pattern rollback
- [Recovery Operations](guide/failure-handling/recovery.md) - Manual intervention

### Advanced Features
- [Branching](guide/advanced/branching.md) - Conditional execution paths
- [External Triggers](guide/advanced/external-triggers.md) - Webhooks and approvals
- [Early Termination](guide/advanced/early-termination.md) - Conditional completion
- [Scheduled Resumption](guide/advanced/scheduled-resumption.md) - Time-based resume

### Operations
- [Console Commands](operations/console-commands.md) - 21 CLI commands
- [HTTP API](operations/api-reference.md) - REST endpoints
- [Events](operations/events.md) - 41 domain events

### Advanced Topics
- [Performance](advanced/performance.md) - Optimization strategies
- [Scaling](advanced/scaling.md) - High-volume deployments
- [Testing](advanced/testing.md) - Testing workflows
- [Monitoring](advanced/monitoring.md) - Observability setup

### Architecture
- [Internals Overview](internals/overview.md) - How Maestro works
- [State Machine](internals/state-machine.md) - State transitions
- [Job Execution](internals/job-execution.md) - Queue integration
- [Concurrency](internals/concurrency.md) - Locking and safety
- [Database Schema](internals/database-schema.md) - Table structure

### Examples
- [E-commerce Order](examples/order-processing.md) - Complete order workflow
- [Document Approval](examples/document-approval.md) - Multi-level approval
- [Data Pipeline](examples/data-pipeline.md) - ETL processing
- [Payment Processing](examples/payment-processing.md) - Payment with retry

### Reference
- [Configuration](configuration/reference.md) - All options
- [Troubleshooting](troubleshooting/common-issues.md) - Common issues
- [FAQ](troubleshooting/faq.md) - Frequently asked questions

## Performance

Designed for high throughput:

| Operation | Target |
|-----------|--------|
| Workflow creation | < 5ms |
| Step advancement | < 10ms |
| Job dispatch | < 2ms |
| State queries | < 1ms |

Tested with millions of concurrent workflows.

## License

Maestro is open-source software licensed under the [MIT license](https://opensource.org/licenses/MIT).
