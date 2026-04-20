# Database Configuration

This guide covers database configuration and optimization for Maestro workflows.

## Table Names

Customize Maestro's table names to avoid conflicts:

```php
// config/maestro.php
'tables' => [
    'workflows' => 'maestro_workflows',
    'step_runs' => 'maestro_step_runs',
    'job_ledger' => 'maestro_job_ledger',
    'step_outputs' => 'maestro_step_outputs',
],
```

## Database Schema

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           Database Schema                                    │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│   ┌────────────────────────────────────────────────────────────────────┐    │
│   │                      maestro_workflows                              │    │
│   │────────────────────────────────────────────────────────────────────│    │
│   │  id (UUID, PK)                                                     │    │
│   │  definition_key (VARCHAR 255)                                      │    │
│   │  version (INT)                                                     │    │
│   │  state (VARCHAR 50)                                                │    │
│   │  context (LONGTEXT/JSON)                                           │    │
│   │  metadata (LONGTEXT/JSON, nullable)                                │    │
│   │  current_step_key (VARCHAR 255, nullable)                          │    │
│   │  auto_retry_* columns                                              │    │
│   │  trigger_* columns                                                 │    │
│   │  compensation_* columns                                            │    │
│   │  terminated_early, termination_reason                              │    │
│   │  started_at, completed_at, created_at, updated_at                  │    │
│   └────────────────────────────────────────────────────────────────────┘    │
│                               │                                              │
│                               │ 1:N                                         │
│                               ▼                                              │
│   ┌────────────────────────────────────────────────────────────────────┐    │
│   │                      maestro_step_runs                              │    │
│   │────────────────────────────────────────────────────────────────────│    │
│   │  id (UUID, PK)                                                     │    │
│   │  workflow_id (UUID, FK)                                            │    │
│   │  step_key (VARCHAR 255)                                            │    │
│   │  state (VARCHAR 50)                                                │    │
│   │  attempt_number (INT)                                              │    │
│   │  total_jobs, successful_jobs, failed_jobs (INT)                    │    │
│   │  started_at, completed_at                                          │    │
│   │  poll_* columns                                                    │    │
│   │  branch_* columns                                                  │    │
│   │  superseded_* columns                                              │    │
│   │  created_at, updated_at                                            │    │
│   └────────────────────────────────────────────────────────────────────┘    │
│                               │                                              │
│                               │ 1:N                                         │
│                               ▼                                              │
│   ┌────────────────────────────────────────────────────────────────────┐    │
│   │                      maestro_job_ledger                             │    │
│   │────────────────────────────────────────────────────────────────────│    │
│   │  id (UUID, PK)                                                     │    │
│   │  step_run_id (UUID, FK)                                            │    │
│   │  job_class (VARCHAR 255)                                           │    │
│   │  state (VARCHAR 50)                                                │    │
│   │  attempt (INT)                                                     │    │
│   │  args (LONGTEXT/JSON, nullable)                                    │    │
│   │  error_message, error_trace (TEXT, nullable)                       │    │
│   │  started_at, completed_at                                          │    │
│   │  created_at, updated_at                                            │    │
│   └────────────────────────────────────────────────────────────────────┘    │
│                                                                              │
│   ┌────────────────────────────────────────────────────────────────────┐    │
│   │                      maestro_step_outputs                           │    │
│   │────────────────────────────────────────────────────────────────────│    │
│   │  id (UUID, PK)                                                     │    │
│   │  workflow_id (UUID, FK)                                            │    │
│   │  step_key (VARCHAR 255)                                            │    │
│   │  output_class (VARCHAR 255)                                        │    │
│   │  payload (LONGTEXT/JSON)                                           │    │
│   │  created_at, updated_at                                            │    │
│   └────────────────────────────────────────────────────────────────────┘    │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Migrations

### Publishing Migrations

```bash
php artisan vendor:publish --tag=maestro-migrations
```

### Running Migrations

```bash
php artisan migrate
```

### Migration Files

Maestro includes migrations for all required tables:

```
database/migrations/
├── 2024_01_01_000001_create_maestro_workflows_table.php
├── 2024_01_01_000002_create_maestro_step_runs_table.php
├── 2024_01_01_000003_create_maestro_job_ledger_table.php
├── 2024_01_01_000004_create_maestro_step_outputs_table.php
├── 2024_01_01_000005_create_maestro_resolution_decisions_table.php
├── 2024_01_01_000006_add_auto_retry_columns_to_maestro_workflows_table.php
├── 2024_01_01_000007_add_superseding_columns_to_maestro_step_runs_table.php
├── 2024_01_01_000008_create_maestro_compensation_runs_table.php
├── 2024_01_01_000009_add_compensation_columns_to_maestro_workflows_table.php
├── 2024_01_01_000010_add_branching_columns_to_maestro_step_runs_table.php
├── 2024_01_01_000011_create_maestro_branch_decisions_table.php
├── 2024_01_01_000012_add_polling_columns_to_maestro_step_runs_table.php
├── 2024_01_01_000013_create_maestro_poll_attempts_table.php
├── 2024_01_01_000014_add_trigger_columns_to_maestro_workflows_table.php
└── 2024_01_01_000015_create_maestro_trigger_payloads_table.php
```

## Index Optimization

### Critical Indexes

The migrations include these performance-critical indexes:

```sql
-- Workflow state lookups
CREATE INDEX idx_workflows_state ON maestro_workflows(state);
CREATE INDEX idx_workflows_definition ON maestro_workflows(definition_key);
CREATE INDEX idx_workflows_def_state ON maestro_workflows(definition_key, state);

-- Step run lookups
CREATE INDEX idx_step_runs_workflow ON maestro_step_runs(workflow_id);
CREATE INDEX idx_step_runs_workflow_state ON maestro_step_runs(workflow_id, state);
CREATE INDEX idx_step_runs_workflow_step ON maestro_step_runs(workflow_id, step_key);

-- Job lookups
CREATE INDEX idx_jobs_step_run ON maestro_job_ledger(step_run_id);
CREATE INDEX idx_jobs_step_run_state ON maestro_job_ledger(step_run_id, state);

-- Output lookups
CREATE INDEX idx_outputs_workflow ON maestro_step_outputs(workflow_id);
CREATE INDEX idx_outputs_workflow_step ON maestro_step_outputs(workflow_id, step_key);
```

### Verifying Indexes

```sql
-- MySQL
SHOW INDEX FROM maestro_workflows;
SHOW INDEX FROM maestro_step_runs;

-- PostgreSQL
\d+ maestro_workflows
\d+ maestro_step_runs
```

## Database Drivers

### MySQL

```php
// config/database.php
'mysql' => [
    'driver' => 'mysql',
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => env('DB_PORT', '3306'),
    'database' => env('DB_DATABASE', 'forge'),
    'username' => env('DB_USERNAME', 'forge'),
    'password' => env('DB_PASSWORD', ''),
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
    'strict' => true,
    'engine' => 'InnoDB',
    'options' => [
        PDO::ATTR_TIMEOUT => 60,
        PDO::ATTR_PERSISTENT => true,
    ],
],
```

### PostgreSQL

```php
// config/database.php
'pgsql' => [
    'driver' => 'pgsql',
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => env('DB_PORT', '5432'),
    'database' => env('DB_DATABASE', 'forge'),
    'username' => env('DB_USERNAME', 'forge'),
    'password' => env('DB_PASSWORD', ''),
    'charset' => 'utf8',
    'prefix' => '',
    'schema' => 'public',
],
```

### SQLite (Testing Only)

```php
// config/database.php
'sqlite' => [
    'driver' => 'sqlite',
    'database' => database_path('database.sqlite'),
    'prefix' => '',
    'foreign_key_constraints' => true,
],
```

## Read/Write Splitting

For high-scale deployments:

```php
// config/database.php
'mysql' => [
    'driver' => 'mysql',
    'read' => [
        'host' => [
            env('DB_READ_HOST_1'),
            env('DB_READ_HOST_2'),
        ],
    ],
    'write' => [
        'host' => env('DB_WRITE_HOST'),
    ],
    'sticky' => true, // Prevent replication lag issues
    'database' => env('DB_DATABASE'),
    'username' => env('DB_USERNAME'),
    'password' => env('DB_PASSWORD'),
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
],
```

## Connection Pooling

For high-throughput scenarios:

```php
// config/database.php
'mysql' => [
    // ...
    'options' => [
        PDO::ATTR_PERSISTENT => true,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
    ],
],
```

## JSON Column Handling

### MySQL

Maestro uses `LONGTEXT` for JSON columns for compatibility:

```sql
-- Queries against JSON data
SELECT * FROM maestro_workflows
WHERE JSON_EXTRACT(metadata, '$.source') = 'api';
```

### PostgreSQL

Native JSONB support provides better performance:

```sql
-- PostgreSQL JSONB queries
SELECT * FROM maestro_workflows
WHERE metadata->>'source' = 'api';

-- Index on JSON field
CREATE INDEX idx_workflows_metadata_source
ON maestro_workflows ((metadata->>'source'));
```

## Query Optimization

### Avoid N+1 Queries

```php
// Bad: N+1 query
$workflows = WorkflowModel::where('state', 'running')->get();
foreach ($workflows as $workflow) {
    $steps = $workflow->stepRuns; // N additional queries
}

// Good: Eager load
$workflows = WorkflowModel::with('stepRuns')
    ->where('state', 'running')
    ->get();
```

### Use Chunking for Bulk Operations

```php
// Process large result sets efficiently
WorkflowModel::where('state', 'completed')
    ->where('completed_at', '<', now()->subDays(30))
    ->chunkById(1000, function ($workflows) {
        // Archive or cleanup
    });
```

### Index Usage Verification

```sql
-- Check if query uses indexes (MySQL)
EXPLAIN SELECT * FROM maestro_workflows WHERE state = 'running';

-- Expected output should show "Using index" or key usage
```

## Locking Configuration

### Database Locks

```php
// config/maestro.php
'locking' => [
    'driver' => 'database',
    'timeout_seconds' => 30,
],
```

### Redis Locks (Recommended for Production)

```php
// config/maestro.php
'locking' => [
    'driver' => 'redis',
    'timeout_seconds' => 30,
],
```

## Data Retention

### Cleanup Configuration

```php
// config/maestro.php
'cleanup' => [
    'enabled' => true,
    'keep_completed_days' => 30,
    'keep_failed_days' => 90,
],
```

### Manual Cleanup

```bash
# Archive old workflows
php artisan maestro:cleanup --older-than=30

# Delete archived workflows
php artisan maestro:cleanup --delete --older-than=90
```

### Scheduled Cleanup

```php
// app/Console/Kernel.php
Schedule::command('maestro:cleanup --older-than=30')
    ->daily()
    ->at('02:00');
```

## Partitioning (Large Scale)

For tables with millions of rows:

```sql
-- MySQL partition by month
ALTER TABLE maestro_workflows
PARTITION BY RANGE (UNIX_TIMESTAMP(created_at)) (
    PARTITION p_2024_01 VALUES LESS THAN (UNIX_TIMESTAMP('2024-02-01')),
    PARTITION p_2024_02 VALUES LESS THAN (UNIX_TIMESTAMP('2024-03-01')),
    -- Add more partitions...
    PARTITION p_future VALUES LESS THAN MAXVALUE
);
```

## Backup Considerations

### Critical Tables

Always backup these tables together:
- `maestro_workflows`
- `maestro_step_runs`
- `maestro_job_ledger`
- `maestro_step_outputs`

### Point-in-Time Recovery

Ensure your backup strategy supports point-in-time recovery to maintain data consistency across Maestro tables.

## Next Steps

- [Queue Configuration](queues.md) - Queue settings
- [Trigger Authentication](trigger-auth.md) - External trigger setup
- [Database Schema](../internals/database-schema.md) - Detailed schema docs
