# Database Schema

Maestro uses several database tables to persist workflow state, step executions, job records, and outputs.

## Table Overview

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           Database Schema                                    │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│   ┌─────────────────────┐                                                   │
│   │ maestro_workflows   │                                                   │
│   │─────────────────────│                                                   │
│   │ id (PK)             │                                                   │
│   │ definition_key      │                                                   │
│   │ definition_version  │                                                   │
│   │ state               │───────────────────┐                               │
│   │ ...                 │                   │                               │
│   └─────────────────────┘                   │                               │
│            │                                │                               │
│            │ 1:N                            │                               │
│            ▼                                │                               │
│   ┌─────────────────────┐                   │                               │
│   │ maestro_step_runs   │                   │                               │
│   │─────────────────────│                   │                               │
│   │ id (PK)             │                   │                               │
│   │ workflow_id (FK)    │───────────────────┤                               │
│   │ step_key            │                   │                               │
│   │ state               │                   │                               │
│   │ ...                 │                   │                               │
│   └─────────────────────┘                   │                               │
│            │                                │                               │
│            │ 1:N                            │                               │
│            ▼                                │                               │
│   ┌─────────────────────┐                   │                               │
│   │ maestro_jobs        │                   │                               │
│   │─────────────────────│                   │                               │
│   │ id (PK)             │                   │                               │
│   │ step_run_id (FK)    │                   │                               │
│   │ workflow_id (FK)    │───────────────────┘                               │
│   │ state               │                                                   │
│   │ ...                 │                                                   │
│   └─────────────────────┘                                                   │
│                                                                              │
│   ┌─────────────────────┐   ┌─────────────────────┐                         │
│   │ maestro_outputs     │   │ maestro_compensation│                         │
│   │─────────────────────│   │_runs                │                         │
│   │ id (PK)             │   │─────────────────────│                         │
│   │ workflow_id (FK)    │   │ id (PK)             │                         │
│   │ step_key            │   │ workflow_id (FK)    │                         │
│   │ output_class        │   │ step_key            │                         │
│   │ payload             │   │ state               │                         │
│   └─────────────────────┘   └─────────────────────┘                         │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Core Tables

### maestro_workflows

Main workflow instances table:

```sql
CREATE TABLE maestro_workflows (
    id CHAR(36) PRIMARY KEY,
    definition_key VARCHAR(255) NOT NULL,
    definition_version INT UNSIGNED NOT NULL DEFAULT 1,
    state VARCHAR(50) NOT NULL DEFAULT 'pending',
    context JSON NULL,
    metadata JSON NULL,

    -- Timestamps
    created_at TIMESTAMP NOT NULL,
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    failed_at TIMESTAMP NULL,
    paused_at TIMESTAMP NULL,
    updated_at TIMESTAMP NOT NULL,

    -- Auto-retry tracking
    auto_retry_count INT UNSIGNED NOT NULL DEFAULT 0,
    auto_retry_max INT UNSIGNED NULL,
    auto_retry_scheduled_at TIMESTAMP NULL,

    -- Trigger tracking
    trigger_key VARCHAR(255) NULL,
    trigger_timeout_at TIMESTAMP NULL,
    scheduled_resume_at TIMESTAMP NULL,

    -- Compensation tracking
    compensation_scope VARCHAR(50) NULL,
    compensation_started_at TIMESTAMP NULL,

    -- Indexes
    INDEX idx_workflows_state (state),
    INDEX idx_workflows_definition_key (definition_key),
    INDEX idx_workflows_created_at (created_at),
    INDEX idx_workflows_auto_retry (auto_retry_scheduled_at),
    INDEX idx_workflows_trigger (trigger_key, trigger_timeout_at),
    INDEX idx_workflows_scheduled_resume (scheduled_resume_at)
);
```

### maestro_step_runs

Step execution records:

```sql
CREATE TABLE maestro_step_runs (
    id CHAR(36) PRIMARY KEY,
    workflow_id CHAR(36) NOT NULL,
    step_key VARCHAR(255) NOT NULL,
    state VARCHAR(50) NOT NULL DEFAULT 'pending',
    attempt_number INT UNSIGNED NOT NULL DEFAULT 1,

    -- Timestamps
    created_at TIMESTAMP NOT NULL,
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    failed_at TIMESTAMP NULL,
    updated_at TIMESTAMP NOT NULL,

    -- Error tracking
    error_message TEXT NULL,
    error_class VARCHAR(255) NULL,

    -- Supersession (for retry-from-step)
    superseded_by CHAR(36) NULL,
    superseded_at TIMESTAMP NULL,
    supersedes CHAR(36) NULL,

    -- Skip tracking
    skip_reason VARCHAR(50) NULL,

    -- Branching
    branch_key VARCHAR(255) NULL,
    branch_decision_id CHAR(36) NULL,

    -- Polling
    poll_attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
    poll_next_at TIMESTAMP NULL,
    poll_timeout_at TIMESTAMP NULL,

    -- Foreign keys
    CONSTRAINT fk_step_runs_workflow
        FOREIGN KEY (workflow_id) REFERENCES maestro_workflows(id)
        ON DELETE CASCADE,

    -- Indexes
    INDEX idx_step_runs_workflow_id (workflow_id),
    INDEX idx_step_runs_state (state),
    INDEX idx_step_runs_workflow_step (workflow_id, step_key),
    INDEX idx_step_runs_poll_next (poll_next_at)
);
```

### maestro_jobs

Job execution ledger:

```sql
CREATE TABLE maestro_jobs (
    id CHAR(36) PRIMARY KEY,
    workflow_id CHAR(36) NOT NULL,
    step_run_id CHAR(36) NOT NULL,
    job_class VARCHAR(255) NOT NULL,
    state VARCHAR(50) NOT NULL DEFAULT 'pending',

    -- Timestamps
    created_at TIMESTAMP NOT NULL,
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    failed_at TIMESTAMP NULL,

    -- Fan-out tracking
    fan_out_index INT UNSIGNED NULL,
    fan_out_total INT UNSIGNED NULL,

    -- Error tracking
    error_message TEXT NULL,
    error_class VARCHAR(255) NULL,
    error_trace TEXT NULL,

    -- Idempotency
    idempotency_key VARCHAR(255) NOT NULL,

    -- Queue info
    queue_name VARCHAR(255) NULL,
    queue_connection VARCHAR(255) NULL,

    -- Foreign keys
    CONSTRAINT fk_jobs_workflow
        FOREIGN KEY (workflow_id) REFERENCES maestro_workflows(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_jobs_step_run
        FOREIGN KEY (step_run_id) REFERENCES maestro_step_runs(id)
        ON DELETE CASCADE,

    -- Indexes
    INDEX idx_jobs_workflow_id (workflow_id),
    INDEX idx_jobs_step_run_id (step_run_id),
    INDEX idx_jobs_state (state),
    INDEX idx_jobs_idempotency (idempotency_key)
);
```

### maestro_outputs

Step output storage:

```sql
CREATE TABLE maestro_outputs (
    id CHAR(36) PRIMARY KEY,
    workflow_id CHAR(36) NOT NULL,
    step_key VARCHAR(255) NOT NULL,
    output_class VARCHAR(255) NOT NULL,
    payload LONGTEXT NOT NULL,  -- JSON serialized

    created_at TIMESTAMP NOT NULL,

    -- Foreign keys
    CONSTRAINT fk_outputs_workflow
        FOREIGN KEY (workflow_id) REFERENCES maestro_workflows(id)
        ON DELETE CASCADE,

    -- Indexes
    INDEX idx_outputs_workflow_id (workflow_id),
    UNIQUE INDEX idx_outputs_workflow_step_class (workflow_id, step_key, output_class)
);
```

## Supporting Tables

### maestro_compensation_runs

Compensation execution tracking:

```sql
CREATE TABLE maestro_compensation_runs (
    id CHAR(36) PRIMARY KEY,
    workflow_id CHAR(36) NOT NULL,
    step_key VARCHAR(255) NOT NULL,
    state VARCHAR(50) NOT NULL DEFAULT 'pending',

    created_at TIMESTAMP NOT NULL,
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    failed_at TIMESTAMP NULL,

    error_message TEXT NULL,
    skip_reason VARCHAR(255) NULL,

    CONSTRAINT fk_compensation_runs_workflow
        FOREIGN KEY (workflow_id) REFERENCES maestro_workflows(id)
        ON DELETE CASCADE,

    INDEX idx_compensation_runs_workflow_id (workflow_id),
    INDEX idx_compensation_runs_state (state)
);
```

### maestro_resolution_decisions

Manual resolution audit log:

```sql
CREATE TABLE maestro_resolution_decisions (
    id CHAR(36) PRIMARY KEY,
    workflow_id CHAR(36) NOT NULL,
    decision VARCHAR(50) NOT NULL,
    reason TEXT NULL,
    made_by VARCHAR(255) NULL,

    created_at TIMESTAMP NOT NULL,

    CONSTRAINT fk_resolution_decisions_workflow
        FOREIGN KEY (workflow_id) REFERENCES maestro_workflows(id)
        ON DELETE CASCADE,

    INDEX idx_resolution_decisions_workflow_id (workflow_id)
);
```

### maestro_branch_decisions

Branch evaluation tracking:

```sql
CREATE TABLE maestro_branch_decisions (
    id CHAR(36) PRIMARY KEY,
    workflow_id CHAR(36) NOT NULL,
    step_key VARCHAR(255) NOT NULL,
    branch_type VARCHAR(50) NOT NULL,
    selected_branches JSON NOT NULL,

    created_at TIMESTAMP NOT NULL,

    CONSTRAINT fk_branch_decisions_workflow
        FOREIGN KEY (workflow_id) REFERENCES maestro_workflows(id)
        ON DELETE CASCADE,

    INDEX idx_branch_decisions_workflow_id (workflow_id)
);
```

### maestro_poll_attempts

Polling attempt history:

```sql
CREATE TABLE maestro_poll_attempts (
    id CHAR(36) PRIMARY KEY,
    workflow_id CHAR(36) NOT NULL,
    step_run_id CHAR(36) NOT NULL,
    attempt_number INT UNSIGNED NOT NULL,
    result VARCHAR(50) NOT NULL,
    message TEXT NULL,

    created_at TIMESTAMP NOT NULL,

    CONSTRAINT fk_poll_attempts_workflow
        FOREIGN KEY (workflow_id) REFERENCES maestro_workflows(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_poll_attempts_step_run
        FOREIGN KEY (step_run_id) REFERENCES maestro_step_runs(id)
        ON DELETE CASCADE,

    INDEX idx_poll_attempts_step_run_id (step_run_id)
);
```

### maestro_trigger_payloads

External trigger payload storage:

```sql
CREATE TABLE maestro_trigger_payloads (
    id CHAR(36) PRIMARY KEY,
    workflow_id CHAR(36) NOT NULL,
    trigger_key VARCHAR(255) NOT NULL,
    payload_type VARCHAR(255) NOT NULL,
    payload JSON NOT NULL,
    accepted BOOLEAN NOT NULL DEFAULT FALSE,
    rejection_reason TEXT NULL,

    created_at TIMESTAMP NOT NULL,

    CONSTRAINT fk_trigger_payloads_workflow
        FOREIGN KEY (workflow_id) REFERENCES maestro_workflows(id)
        ON DELETE CASCADE,

    INDEX idx_trigger_payloads_workflow_id (workflow_id)
);
```

## Index Strategy

### Primary Queries

| Query | Index Used |
|-------|------------|
| Find workflow by ID | PRIMARY KEY |
| Find workflows by state | `idx_workflows_state` |
| Find workflows by definition | `idx_workflows_definition_key` |
| Find steps for workflow | `idx_step_runs_workflow_id` |
| Find jobs for step run | `idx_jobs_step_run_id` |
| Check idempotency | `idx_jobs_idempotency` |
| Find pending auto-retries | `idx_workflows_auto_retry` |
| Find pending polls | `idx_step_runs_poll_next` |

### Composite Indexes

```sql
-- For finding outputs by workflow and step
INDEX idx_outputs_workflow_step_class (workflow_id, step_key, output_class)

-- For finding step runs by workflow and step
INDEX idx_step_runs_workflow_step (workflow_id, step_key)

-- For finding workflows awaiting specific trigger
INDEX idx_workflows_trigger (trigger_key, trigger_timeout_at)
```

## Data Types

### UUIDs

All primary keys use UUIDv7 (time-ordered):

```php
$id = Uuid::uuid7()->toString();
// e.g., "018e0e5a-4b3c-7d1e-8f00-1234567890ab"
```

Benefits:
- Globally unique without coordination
- Time-ordered for efficient indexing
- Compatible with distributed systems

### JSON Columns

Used for flexible data storage:

```sql
context JSON NULL,           -- Workflow context (arbitrary data)
metadata JSON NULL,          -- Additional metadata
payload LONGTEXT NOT NULL,   -- Serialized output objects
selected_branches JSON NOT NULL, -- Array of branch keys
```

### Timestamps

All timestamps use `TIMESTAMP`:

```sql
created_at TIMESTAMP NOT NULL,
started_at TIMESTAMP NULL,
completed_at TIMESTAMP NULL,
```

## Configuration

Customize table names in `config/maestro.php`:

```php
'tables' => [
    'workflows' => 'maestro_workflows',
    'step_runs' => 'maestro_step_runs',
    'jobs' => 'maestro_jobs',
    'outputs' => 'maestro_outputs',
    'compensation_runs' => 'maestro_compensation_runs',
    'resolution_decisions' => 'maestro_resolution_decisions',
    'branch_decisions' => 'maestro_branch_decisions',
    'poll_attempts' => 'maestro_poll_attempts',
    'trigger_payloads' => 'maestro_trigger_payloads',
],
```

## Migrations

Publish and run migrations:

```bash
php artisan vendor:publish --tag=maestro-migrations
php artisan migrate
```

## Performance Considerations

### For High Volume

1. **Partition by date** - Partition `maestro_workflows` by `created_at` for archival
2. **Archive completed** - Move old completed workflows to archive tables
3. **Index strategy** - Add indexes based on actual query patterns
4. **Connection pooling** - Use connection pooling for high concurrency

### Query Optimization

```php
// Use eager loading for workflow details
$workflow = WorkflowModel::with([
    'stepRuns',
    'stepRuns.jobs',
    'outputs',
])->find($id);

// Use chunking for bulk operations
WorkflowModel::where('state', 'succeeded')
    ->where('completed_at', '<', now()->subDays(30))
    ->chunk(1000, function ($workflows) {
        // Archive or delete
    });
```

## Next Steps

- [Concurrency Control](concurrency.md) - Locking mechanisms
- [Architecture Overview](overview.md) - High-level design
- [Configuration](../configuration/reference.md) - All options
