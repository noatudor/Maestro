# Console Commands

Maestro provides a comprehensive set of console commands for managing workflows, handling failures, and operational tasks.

## Workflow Management

### Start Workflow

```bash
php artisan maestro:start {definition} [--context=]
```

Start a new workflow instance.

| Argument/Option | Description |
|-----------------|-------------|
| `definition` | Workflow definition key |
| `--context` | JSON context data |

**Examples:**

```bash
# Start workflow with no context
php artisan maestro:start order-processing

# Start with context
php artisan maestro:start order-processing --context='{"order_id":"12345"}'
```

### List Workflows

```bash
php artisan maestro:list [--state=] [--definition=] [--limit=] [--offset=]
```

List workflow instances with optional filtering.

| Option | Description |
|--------|-------------|
| `--state` | Filter by state (running, failed, succeeded, etc.) |
| `--definition` | Filter by definition key |
| `--limit` | Number of results (default: 20) |
| `--offset` | Pagination offset |

**Examples:**

```bash
# List all workflows
php artisan maestro:list

# List failed workflows
php artisan maestro:list --state=failed

# List recent order workflows
php artisan maestro:list --definition=order-processing --limit=50
```

### Graph Workflow

```bash
php artisan maestro:graph {definition}
```

Display workflow definition as ASCII graph.

**Example:**

```bash
php artisan maestro:graph order-processing

# Output:
# order-processing (v1)
# ┌─────────────────┐
# │ validate_order  │
# └────────┬────────┘
#          │
#          ▼
# ┌─────────────────┐
# │ process_payment │ [retry: 3x]
# └────────┬────────┘
#          │
#   ┌──────┴──────┐
#   │             │
#   ▼             ▼
# ┌──────┐    ┌──────────┐
# │notify│    │ fulfill  │
# └──────┘    └──────────┘
```

### Validate Definitions

```bash
php artisan maestro:validate [--definition=]
```

Validate workflow definitions for errors.

**Examples:**

```bash
# Validate all definitions
php artisan maestro:validate

# Validate specific definition
php artisan maestro:validate --definition=order-processing
```

## Workflow Control

### Pause Workflow

```bash
php artisan maestro:pause {workflowId} [--reason=]
```

Pause a running workflow.

**Example:**

```bash
php artisan maestro:pause abc-123 --reason="Investigating issue"
```

### Resume Workflow

```bash
php artisan maestro:resume {workflowId}
```

Resume a paused workflow.

**Example:**

```bash
php artisan maestro:resume abc-123
```

### Cancel Workflow

```bash
php artisan maestro:cancel {workflowId} [--compensate] [--reason=]
```

Cancel a workflow.

| Option | Description |
|--------|-------------|
| `--compensate` | Run compensation before cancelling |
| `--reason` | Cancellation reason |

**Examples:**

```bash
# Cancel immediately
php artisan maestro:cancel abc-123

# Cancel with compensation
php artisan maestro:cancel abc-123 --compensate

# Cancel with reason
php artisan maestro:cancel abc-123 --reason="Customer request"
```

## Failure Recovery

### Resolve Workflow

```bash
php artisan maestro:resolve {workflowId} --decision={decision} [--reason=] [--step=]
```

Make a resolution decision for a failed workflow.

| Option | Description |
|--------|-------------|
| `--decision` | Decision: retry, compensate, cancel, mark-resolved |
| `--reason` | Reason for the decision |
| `--step` | Step key for retry-from-step |

**Examples:**

```bash
# Retry the failed step
php artisan maestro:resolve abc-123 --decision=retry

# Trigger compensation
php artisan maestro:resolve abc-123 --decision=compensate

# Cancel the workflow
php artisan maestro:resolve abc-123 --decision=cancel

# Mark as manually resolved
php artisan maestro:resolve abc-123 --decision=mark-resolved --reason="Fixed in database"
```

### Retry from Step

```bash
php artisan maestro:retry-from-step {workflowId} {stepKey} [--compensate]
```

Retry workflow from a specific step.

| Option | Description |
|--------|-------------|
| `--compensate` | Compensate intermediate steps before retrying |

**Examples:**

```bash
# Retry from payment step
php artisan maestro:retry-from-step abc-123 payment

# Retry with compensation
php artisan maestro:retry-from-step abc-123 payment --compensate
```

## Compensation

### Compensate Workflow

```bash
php artisan maestro:compensate {workflowId} [--scope=] [--from-step=]
```

Trigger compensation for a workflow.

| Option | Description |
|--------|-------------|
| `--scope` | Scope: all, failed-step-only, from-step |
| `--from-step` | Step key for from-step scope |

**Examples:**

```bash
# Compensate all steps
php artisan maestro:compensate abc-123

# Compensate only the failed step
php artisan maestro:compensate abc-123 --scope=failed-step-only

# Compensate from specific step
php artisan maestro:compensate abc-123 --scope=from-step --from-step=payment
```

### Retry Compensation

```bash
php artisan maestro:retry-compensation {workflowId}
```

Retry failed compensation jobs.

**Example:**

```bash
php artisan maestro:retry-compensation abc-123
```

### Skip Compensation

```bash
php artisan maestro:skip-compensation {workflowId} --step={stepKey} [--reason=]
```

Skip compensation for a specific step.

**Example:**

```bash
php artisan maestro:skip-compensation abc-123 --step=payment --reason="Manually refunded"
```

## Scheduled Tasks

### Process Auto-Retries

```bash
php artisan maestro:process-auto-retries
```

Process workflows scheduled for auto-retry. Run via scheduler.

**Scheduler configuration:**

```php
$schedule->command('maestro:process-auto-retries')
    ->everyMinute()
    ->withoutOverlapping();
```

### Process Scheduled Resumes

```bash
php artisan maestro:process-scheduled-resumes
```

Resume workflows with scheduled resume times.

**Scheduler configuration:**

```php
$schedule->command('maestro:process-scheduled-resumes')
    ->everyMinute()
    ->withoutOverlapping();
```

### Check Trigger Timeouts

```bash
php artisan maestro:check-trigger-timeouts
```

Handle workflows waiting for triggers that have timed out.

**Scheduler configuration:**

```php
$schedule->command('maestro:check-trigger-timeouts')
    ->everyFiveMinutes()
    ->withoutOverlapping();
```

## Polling

### Dispatch Polls

```bash
php artisan maestro:dispatch-polls
```

Dispatch pending polling jobs. Run via scheduler.

**Scheduler configuration:**

```php
$schedule->command('maestro:dispatch-polls')
    ->everyMinute()
    ->withoutOverlapping();
```

### Recover Polls

```bash
php artisan maestro:recover-polls [--threshold=]
```

Recover stuck polling steps.

| Option | Description |
|--------|-------------|
| `--threshold` | Seconds before considering stuck (default: 3600) |

**Example:**

```bash
php artisan maestro:recover-polls --threshold=1800
```

## Complete Scheduler Setup

Add all scheduled commands to `app/Console/Kernel.php`:

```php
<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        // Process auto-retries every minute
        $schedule->command('maestro:process-auto-retries')
            ->everyMinute()
            ->withoutOverlapping()
            ->runInBackground();

        // Process scheduled resumes every minute
        $schedule->command('maestro:process-scheduled-resumes')
            ->everyMinute()
            ->withoutOverlapping()
            ->runInBackground();

        // Check trigger timeouts every 5 minutes
        $schedule->command('maestro:check-trigger-timeouts')
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->runInBackground();

        // Dispatch polling jobs every minute
        $schedule->command('maestro:dispatch-polls')
            ->everyMinute()
            ->withoutOverlapping()
            ->runInBackground();

        // Recover stuck polls every 15 minutes
        $schedule->command('maestro:recover-polls')
            ->everyFifteenMinutes()
            ->withoutOverlapping()
            ->runInBackground();

        // Optional: Cleanup old workflows daily
        $schedule->command('maestro:cleanup')
            ->dailyAt('03:00')
            ->withoutOverlapping()
            ->runInBackground();
    }
}
```

## Operational Dashboard

Combine commands for operational monitoring:

```bash
#!/bin/bash
# maestro-status.sh - Quick operational status

echo "=== Maestro Workflow Status ==="
echo ""

echo "Running workflows:"
php artisan maestro:list --state=running --limit=10

echo ""
echo "Failed workflows (awaiting resolution):"
php artisan maestro:list --state=failed --limit=10

echo ""
echo "Paused workflows:"
php artisan maestro:list --state=paused --limit=10

echo ""
echo "Compensating workflows:"
php artisan maestro:list --state=compensating --limit=10
```

## Exit Codes

All commands return standard exit codes:

| Code | Meaning |
|------|---------|
| 0 | Success |
| 1 | General error |
| 2 | Invalid arguments |

Use exit codes in scripts:

```bash
if php artisan maestro:resolve abc-123 --decision=retry; then
    echo "Workflow retry initiated"
else
    echo "Failed to retry workflow"
    exit 1
fi
```

## Next Steps

- [HTTP API Reference](api-reference.md) - REST API endpoints
- [Events Reference](events.md) - Monitoring events
- [Recovery Operations](../guide/failure-handling/recovery.md) - Recovery procedures
