# Scaling Maestro

This guide covers strategies for scaling Maestro to handle millions of workflows across multiple servers.

## Scaling Architecture

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                      Production Scaling Architecture                         │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│   ┌──────────────────────────────────────────────────────────────────────┐  │
│   │                         API Layer (Stateless)                         │  │
│   │  ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌──────────┐             │  │
│   │  │ API Pod  │  │ API Pod  │  │ API Pod  │  │ API Pod  │             │  │
│   │  └────┬─────┘  └────┬─────┘  └────┬─────┘  └────┬─────┘             │  │
│   │       │              │              │              │                  │  │
│   │       └──────────────┴──────────────┴──────────────┘                  │  │
│   └──────────────────────────────┬───────────────────────────────────────┘  │
│                                  │                                          │
│                                  ▼                                          │
│   ┌──────────────────────────────────────────────────────────────────────┐  │
│   │                      Message Queue (Redis Cluster)                    │  │
│   │  ┌─────────────────────────────────────────────────────────────────┐ │  │
│   │  │  workflows-high │ workflows │ workflows-low │ workflows-polling │ │  │
│   │  └─────────────────────────────────────────────────────────────────┘ │  │
│   └──────────────────────────────┬───────────────────────────────────────┘  │
│                                  │                                          │
│                                  ▼                                          │
│   ┌──────────────────────────────────────────────────────────────────────┐  │
│   │                     Worker Layer (Scale Independently)                │  │
│   │  ┌────────────┐  ┌────────────┐  ┌────────────┐  ┌────────────┐     │  │
│   │  │ Worker Pod │  │ Worker Pod │  │ Worker Pod │  │ Worker Pod │     │  │
│   │  │ (general)  │  │ (general)  │  │ (polling)  │  │ (fan-out)  │     │  │
│   │  └─────┬──────┘  └─────┬──────┘  └─────┬──────┘  └─────┬──────┘     │  │
│   │        │               │               │               │             │  │
│   │        └───────────────┴───────────────┴───────────────┘             │  │
│   └──────────────────────────────┬───────────────────────────────────────┘  │
│                                  │                                          │
│                                  ▼                                          │
│   ┌──────────────────────────────────────────────────────────────────────┐  │
│   │                    Database Layer (Replicated)                        │  │
│   │  ┌──────────────┐     ┌──────────────┐    ┌──────────────┐          │  │
│   │  │    Primary   │────▶│   Replica 1  │    │   Replica 2  │          │  │
│   │  │   (writes)   │     │   (reads)    │    │   (reads)    │          │  │
│   │  └──────────────┘     └──────────────┘    └──────────────┘          │  │
│   └──────────────────────────────────────────────────────────────────────┘  │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Queue Scaling

### Multiple Queues by Priority

```php
// config/maestro.php
'queues' => [
    'high' => 'workflows-high',
    'default' => 'workflows',
    'low' => 'workflows-low',
    'polling' => 'workflows-polling',
],
```

Assign steps to queues:

```php
->step('critical_payment')
    ->job(ProcessPaymentJob::class)
    ->onQueue('workflows-high')
    ->build()

->step('analytics')
    ->job(AnalyticsJob::class)
    ->onQueue('workflows-low')
    ->build()

->polling('wait_confirmation')
    ->job(CheckConfirmationJob::class)
    ->onQueue('workflows-polling')
    ->build()
```

### Dedicated Workers

Run workers for specific queues:

```bash
# High-priority workers (more resources)
php artisan queue:work --queue=workflows-high --memory=512

# General workers
php artisan queue:work --queue=workflows --memory=256

# Polling workers (can be fewer)
php artisan queue:work --queue=workflows-polling --sleep=5

# Low-priority workers
php artisan queue:work --queue=workflows-low --memory=128
```

### Supervisor Configuration

```ini
; /etc/supervisor/conf.d/maestro.conf

[program:maestro-high]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/artisan queue:work --queue=workflows-high --sleep=1 --memory=512
autostart=true
autorestart=true
numprocs=4
user=www-data

[program:maestro-default]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/artisan queue:work --queue=workflows --sleep=3 --memory=256
autostart=true
autorestart=true
numprocs=8
user=www-data

[program:maestro-polling]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/artisan queue:work --queue=workflows-polling --sleep=10 --memory=256
autostart=true
autorestart=true
numprocs=2
user=www-data
```

### Laravel Horizon

For advanced queue management:

```php
// config/horizon.php
'environments' => [
    'production' => [
        'supervisor-high' => [
            'connection' => 'redis',
            'queue' => ['workflows-high'],
            'balance' => 'auto',
            'minProcesses' => 4,
            'maxProcesses' => 20,
            'balanceMaxShift' => 5,
            'balanceCooldown' => 3,
        ],
        'supervisor-default' => [
            'connection' => 'redis',
            'queue' => ['workflows'],
            'balance' => 'auto',
            'minProcesses' => 8,
            'maxProcesses' => 50,
        ],
        'supervisor-polling' => [
            'connection' => 'redis',
            'queue' => ['workflows-polling'],
            'balance' => 'simple',
            'minProcesses' => 2,
            'maxProcesses' => 10,
        ],
    ],
],
```

## Database Scaling

### Read/Write Splitting

```php
// config/database.php
'mysql' => [
    'driver' => 'mysql',
    'read' => [
        'host' => [
            env('DB_READ_HOST_1'),
            env('DB_READ_HOST_2'),
            env('DB_READ_HOST_3'),
        ],
    ],
    'write' => [
        'host' => env('DB_WRITE_HOST'),
    ],
    'sticky' => true, // Prevent replication lag issues
],
```

### Partitioning Strategy

For very large installations (100M+ workflows), consider partitioning:

```sql
-- Partition by creation date (MySQL)
ALTER TABLE maestro_workflows
PARTITION BY RANGE (UNIX_TIMESTAMP(created_at)) (
    PARTITION p2024_q1 VALUES LESS THAN (UNIX_TIMESTAMP('2024-04-01')),
    PARTITION p2024_q2 VALUES LESS THAN (UNIX_TIMESTAMP('2024-07-01')),
    PARTITION p2024_q3 VALUES LESS THAN (UNIX_TIMESTAMP('2024-10-01')),
    PARTITION p2024_q4 VALUES LESS THAN (UNIX_TIMESTAMP('2025-01-01')),
    PARTITION p_future VALUES LESS THAN MAXVALUE
);
```

### Connection Limits

Configure connection pooling:

```php
// config/database.php
'mysql' => [
    'options' => [
        PDO::ATTR_PERSISTENT => true,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
    ],
],
```

## Redis Scaling

### Redis Cluster

```php
// config/database.php
'redis' => [
    'client' => 'phpredis',
    'cluster' => true,
    'clusters' => [
        'default' => [
            [
                'host' => env('REDIS_HOST_1'),
                'port' => env('REDIS_PORT_1'),
            ],
            [
                'host' => env('REDIS_HOST_2'),
                'port' => env('REDIS_PORT_2'),
            ],
            [
                'host' => env('REDIS_HOST_3'),
                'port' => env('REDIS_PORT_3'),
            ],
        ],
    ],
    'options' => [
        'cluster' => 'redis',
        'prefix' => 'maestro:',
    ],
],
```

### Separate Redis Instances

Use separate Redis instances for different purposes:

```php
// config/database.php
'redis' => [
    'queue' => [
        'url' => env('REDIS_QUEUE_URL'),
    ],
    'locks' => [
        'url' => env('REDIS_LOCKS_URL'),
    ],
    'cache' => [
        'url' => env('REDIS_CACHE_URL'),
    ],
],

// config/maestro.php
'locking' => [
    'driver' => 'redis',
    'connection' => 'locks', // Dedicated connection
],

// config/queue.php
'redis' => [
    'connection' => 'queue', // Dedicated connection
],
```

## Kubernetes Deployment

### Deployment Manifests

```yaml
# worker-deployment.yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: maestro-workers
spec:
  replicas: 10
  selector:
    matchLabels:
      app: maestro-worker
  template:
    metadata:
      labels:
        app: maestro-worker
    spec:
      containers:
      - name: worker
        image: your-app:latest
        command: ["php", "artisan", "queue:work", "--queue=workflows", "--memory=256"]
        resources:
          requests:
            memory: "256Mi"
            cpu: "250m"
          limits:
            memory: "512Mi"
            cpu: "500m"
        env:
        - name: DB_HOST
          valueFrom:
            secretKeyRef:
              name: db-credentials
              key: host
```

### Horizontal Pod Autoscaler

```yaml
# hpa.yaml
apiVersion: autoscaling/v2
kind: HorizontalPodAutoscaler
metadata:
  name: maestro-workers-hpa
spec:
  scaleTargetRef:
    apiVersion: apps/v1
    kind: Deployment
    name: maestro-workers
  minReplicas: 5
  maxReplicas: 100
  metrics:
  - type: External
    external:
      metric:
        name: redis_queue_length
        selector:
          matchLabels:
            queue: workflows
      target:
        type: AverageValue
        averageValue: "100" # Scale up when > 100 jobs per worker
```

### Pod Disruption Budget

```yaml
# pdb.yaml
apiVersion: policy/v1
kind: PodDisruptionBudget
metadata:
  name: maestro-workers-pdb
spec:
  minAvailable: 3
  selector:
    matchLabels:
      app: maestro-worker
```

## Multi-Tenant Scaling

### Tenant Isolation

```php
// Tenant-specific queues
->step('process')
    ->job(ProcessJob::class)
    ->onQueue("workflows-tenant-{$tenantId}")
    ->build()

// Separate databases per tenant
config(['database.default' => "tenant_{$tenantId}"]);
```

### Resource Quotas

```php
// Limit workflows per tenant
final class TenantWorkflowLimiter
{
    public function canStartWorkflow(string $tenantId): bool
    {
        $active = WorkflowModel::where('tenant_id', $tenantId)
            ->whereNotIn('state', ['succeeded', 'failed', 'cancelled'])
            ->count();

        $limit = $this->getTenantLimit($tenantId);

        return $active < $limit;
    }
}
```

## Load Testing

### Benchmarking Setup

```php
// Create load test workflow
final class LoadTestWorkflow extends WorkflowDefinition
{
    public function define(WorkflowDefinitionBuilder $builder): void
    {
        $builder
            ->name('Load Test')
            ->version(1)

            ->step('step_1')
                ->job(QuickJob::class)
                ->build()

            ->fanOut('parallel')
                ->job(QuickJob::class)
                ->items(fn($ctx, $out) => range(1, 10))
                ->build()

            ->step('step_2')
                ->job(QuickJob::class)
                ->build();
    }
}
```

### Load Test Script

```php
// Run concurrent workflow creation
use Illuminate\Support\Benchmark;

$result = Benchmark::measure(function () {
    $promises = [];

    for ($i = 0; $i < 1000; $i++) {
        $promises[] = async(fn() =>
            Maestro::startWorkflow(
                DefinitionKey::fromString('load-test'),
            )
        );
    }

    await($promises);
});

echo "Created 1000 workflows in {$result}ms";
```

### Metrics to Monitor

During load testing, monitor:

| Metric | Target | Alert Threshold |
|--------|--------|-----------------|
| Queue depth | < 1000 | > 5000 |
| Job latency (p95) | < 100ms | > 500ms |
| Lock acquisition (p95) | < 50ms | > 200ms |
| DB query time (p95) | < 20ms | > 100ms |
| Worker memory | < 256MB | > 400MB |
| Error rate | < 0.1% | > 1% |

## Capacity Planning

### Workflow Throughput Calculator

```
Workflows/second = (Workers × Jobs/second/worker) / Steps/workflow

Example:
- 20 workers
- 10 jobs/second/worker average
- 5 steps per workflow

Throughput = (20 × 10) / 5 = 40 workflows/second = 144,000/hour
```

### Storage Calculator

```
Storage/month = Workflows/month × Bytes/workflow

Average workflow storage:
- Workflow record: ~500 bytes
- Step runs (5 steps): ~2,500 bytes
- Job records: ~2,000 bytes
- Outputs: ~1,000 bytes
Total: ~6 KB/workflow

1M workflows/month = 6 GB/month
```

## Scaling Checklist

- [ ] Redis cluster deployed for queue
- [ ] Separate Redis for locks
- [ ] Database read replicas configured
- [ ] Connection pooling enabled
- [ ] Multiple queue priorities configured
- [ ] Workers scaled per queue type
- [ ] Horizon/Supervisor configured
- [ ] HPA configured (if Kubernetes)
- [ ] Load testing performed
- [ ] Monitoring dashboards in place
- [ ] Capacity planning documented

## Next Steps

- [Performance](performance.md) - Optimization strategies
- [Monitoring](monitoring.md) - Observability setup
- [Concurrency](../internals/concurrency.md) - Locking internals
