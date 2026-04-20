# Monitoring Maestro

This guide covers strategies for monitoring Maestro workflows in production, including metrics, alerting, and observability best practices.

## Monitoring Architecture

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                        Observability Stack                                   │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│   ┌─────────────────────────────────────────────────────────────────────┐   │
│   │                        Maestro Application                           │   │
│   │  ┌───────────┐  ┌───────────┐  ┌───────────┐  ┌───────────┐        │   │
│   │  │  Events   │  │   Logs    │  │  Metrics  │  │  Traces   │        │   │
│   │  │ (41 types)│  │ (context) │  │ (counters)│  │  (spans)  │        │   │
│   │  └─────┬─────┘  └─────┬─────┘  └─────┬─────┘  └─────┬─────┘        │   │
│   └────────┼──────────────┼──────────────┼──────────────┼────────────────┘   │
│            │              │              │              │                    │
│            ▼              ▼              ▼              ▼                    │
│   ┌─────────────┐  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐       │
│   │   Event     │  │    Log      │  │   Metrics   │  │   Tracing   │       │
│   │   Store     │  │Aggregation  │  │   Backend   │  │   Backend   │       │
│   │ (DB/Stream) │  │ (ELK/Loki) │  │ (Prometheus)│  │  (Jaeger)   │       │
│   └──────┬──────┘  └──────┬──────┘  └──────┬──────┘  └──────┬──────┘       │
│          │                │                │                │               │
│          └────────────────┴────────────────┴────────────────┘               │
│                                    │                                         │
│                                    ▼                                         │
│                          ┌─────────────────┐                                │
│                          │   Dashboards    │                                │
│                          │   (Grafana)     │                                │
│                          └────────┬────────┘                                │
│                                   │                                          │
│                          ┌────────┴────────┐                                │
│                          │                 │                                 │
│                          ▼                 ▼                                 │
│                    ┌──────────┐      ┌──────────┐                           │
│                    │ Alerting │      │ On-Call  │                           │
│                    │(PagerDuty│      │  Team    │                           │
│                    └──────────┘      └──────────┘                           │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Event-Based Monitoring

### Listening to Workflow Events

Maestro emits 41 domain events for comprehensive observability:

```php
<?php

declare(strict_types=1);

namespace App\Listeners;

use Illuminate\Events\Dispatcher;
use Maestro\Workflow\Domain\Events\{
    WorkflowStarted,
    WorkflowSucceeded,
    WorkflowFailed,
    StepStarted,
    StepSucceeded,
    StepFailed,
    JobDispatched,
    JobSucceeded,
    JobFailed,
};

final class WorkflowMetricsListener
{
    public function subscribe(Dispatcher $events): void
    {
        $events->listen(WorkflowStarted::class, [$this, 'onWorkflowStarted']);
        $events->listen(WorkflowSucceeded::class, [$this, 'onWorkflowSucceeded']);
        $events->listen(WorkflowFailed::class, [$this, 'onWorkflowFailed']);
        $events->listen(StepFailed::class, [$this, 'onStepFailed']);
        $events->listen(JobFailed::class, [$this, 'onJobFailed']);
    }

    public function onWorkflowStarted(WorkflowStarted $event): void
    {
        Metrics::increment('maestro.workflows.started', [
            'definition' => $event->definitionKey->value,
        ]);
    }

    public function onWorkflowSucceeded(WorkflowSucceeded $event): void
    {
        Metrics::increment('maestro.workflows.succeeded', [
            'definition' => $event->definitionKey->value,
        ]);

        Metrics::timing('maestro.workflows.duration', $event->durationMs, [
            'definition' => $event->definitionKey->value,
        ]);
    }

    public function onWorkflowFailed(WorkflowFailed $event): void
    {
        Metrics::increment('maestro.workflows.failed', [
            'definition' => $event->definitionKey->value,
        ]);

        // Alert on failure
        Alert::notify('workflow_failed', [
            'workflow_id' => $event->workflowId->value,
            'reason' => $event->reason,
        ]);
    }

    public function onStepFailed(StepFailed $event): void
    {
        Metrics::increment('maestro.steps.failed', [
            'definition' => $event->definitionKey->value,
            'step' => $event->stepKey->value,
        ]);
    }

    public function onJobFailed(JobFailed $event): void
    {
        Metrics::increment('maestro.jobs.failed', [
            'job_class' => $event->jobClass,
        ]);

        Log::error('Job failed', [
            'workflow_id' => $event->workflowId->value,
            'step_key' => $event->stepKey->value,
            'job_id' => $event->jobId->value,
            'exception' => $event->exception->getMessage(),
            'trace' => $event->exception->getTraceAsString(),
        ]);
    }
}
```

### Event Categories

| Category | Events | Use Case |
|----------|--------|----------|
| Workflow Lifecycle | Started, Succeeded, Failed, Paused, Resumed, Cancelled | High-level tracking |
| Step Lifecycle | Started, Succeeded, Failed, Skipped, Retried | Step-level debugging |
| Job Lifecycle | Dispatched, Started, Succeeded, Failed | Job-level debugging |
| Compensation | Started, Completed, Failed, StepSucceeded, StepFailed | Rollback monitoring |
| Triggers | Received, TimedOut, ValidationFailed | External integration |
| Resolution | DecisionMade, AutoRetryScheduled, AutoRetryExhausted | Failure handling |

## Metrics Collection

### Prometheus Metrics

```php
<?php

declare(strict_types=1);

namespace App\Metrics;

use Prometheus\CollectorRegistry;
use Prometheus\Counter;
use Prometheus\Histogram;
use Prometheus\Gauge;

final class MaestroMetrics
{
    private Counter $workflowsTotal;
    private Counter $stepsTotal;
    private Counter $jobsTotal;
    private Histogram $workflowDuration;
    private Histogram $stepDuration;
    private Histogram $jobDuration;
    private Gauge $activeWorkflows;
    private Gauge $queueDepth;

    public function __construct(CollectorRegistry $registry)
    {
        $this->workflowsTotal = $registry->getOrRegisterCounter(
            'maestro',
            'workflows_total',
            'Total workflows by definition and state',
            ['definition', 'state']
        );

        $this->stepsTotal = $registry->getOrRegisterCounter(
            'maestro',
            'steps_total',
            'Total steps by definition, step, and state',
            ['definition', 'step', 'state']
        );

        $this->jobsTotal = $registry->getOrRegisterCounter(
            'maestro',
            'jobs_total',
            'Total jobs by class and state',
            ['class', 'state']
        );

        $this->workflowDuration = $registry->getOrRegisterHistogram(
            'maestro',
            'workflow_duration_seconds',
            'Workflow duration in seconds',
            ['definition'],
            [0.1, 0.5, 1, 5, 10, 30, 60, 300, 600]
        );

        $this->stepDuration = $registry->getOrRegisterHistogram(
            'maestro',
            'step_duration_seconds',
            'Step duration in seconds',
            ['definition', 'step'],
            [0.01, 0.05, 0.1, 0.5, 1, 5, 10, 30]
        );

        $this->jobDuration = $registry->getOrRegisterHistogram(
            'maestro',
            'job_duration_seconds',
            'Job duration in seconds',
            ['class'],
            [0.001, 0.005, 0.01, 0.05, 0.1, 0.5, 1, 5]
        );

        $this->activeWorkflows = $registry->getOrRegisterGauge(
            'maestro',
            'active_workflows',
            'Currently active workflows by definition',
            ['definition']
        );

        $this->queueDepth = $registry->getOrRegisterGauge(
            'maestro',
            'queue_depth',
            'Current queue depth by queue name',
            ['queue']
        );
    }

    public function workflowStarted(string $definition): void
    {
        $this->workflowsTotal->incBy(1, [$definition, 'started']);
        $this->activeWorkflows->inc([$definition]);
    }

    public function workflowCompleted(string $definition, string $state, float $durationSeconds): void
    {
        $this->workflowsTotal->incBy(1, [$definition, $state]);
        $this->activeWorkflows->dec([$definition]);
        $this->workflowDuration->observe($durationSeconds, [$definition]);
    }

    public function stepCompleted(string $definition, string $step, string $state, float $durationSeconds): void
    {
        $this->stepsTotal->incBy(1, [$definition, $step, $state]);
        $this->stepDuration->observe($durationSeconds, [$definition, $step]);
    }

    public function jobCompleted(string $class, string $state, float $durationSeconds): void
    {
        $this->jobsTotal->incBy(1, [$class, $state]);
        $this->jobDuration->observe($durationSeconds, [$class]);
    }

    public function setQueueDepth(string $queue, int $depth): void
    {
        $this->queueDepth->set($depth, [$queue]);
    }
}
```

### StatsD/DataDog Integration

```php
<?php

declare(strict_types=1);

use DataDog\DogStatsd;

final class DataDogMetrics
{
    public function __construct(
        private readonly DogStatsd $statsd,
    ) {}

    public function recordWorkflowMetrics(WorkflowSucceeded $event): void
    {
        $this->statsd->increment('maestro.workflow.completed', 1, [
            'definition' => $event->definitionKey->value,
            'state' => 'succeeded',
        ]);

        $this->statsd->timing('maestro.workflow.duration', $event->durationMs, [
            'definition' => $event->definitionKey->value,
        ]);
    }

    public function recordQueueMetrics(): void
    {
        $queues = ['workflows-high', 'workflows', 'workflows-low', 'workflows-polling'];

        foreach ($queues as $queue) {
            $depth = Redis::llen("queues:{$queue}");

            $this->statsd->gauge('maestro.queue.depth', $depth, [
                'queue' => $queue,
            ]);
        }
    }
}
```

## Logging Strategy

### Structured Logging

```php
<?php

declare(strict_types=1);

namespace App\Logging;

use Illuminate\Support\Facades\Log;

final class WorkflowLogger
{
    public function logWorkflowEvent(object $event): void
    {
        $context = $this->buildContext($event);

        match ($event::class) {
            WorkflowFailed::class => Log::error('Workflow failed', $context),
            StepFailed::class => Log::warning('Step failed', $context),
            JobFailed::class => Log::warning('Job failed', $context),
            default => Log::info('Workflow event', $context),
        };
    }

    private function buildContext(object $event): array
    {
        $context = [
            'event_type' => class_basename($event),
            'timestamp' => now()->toIso8601String(),
        ];

        if (property_exists($event, 'workflowId')) {
            $context['workflow_id'] = $event->workflowId->value;
        }

        if (property_exists($event, 'definitionKey')) {
            $context['definition'] = $event->definitionKey->value;
        }

        if (property_exists($event, 'stepKey')) {
            $context['step'] = $event->stepKey->value;
        }

        if (property_exists($event, 'exception')) {
            $context['error'] = $event->exception->getMessage();
            $context['trace'] = $event->exception->getTraceAsString();
        }

        return $context;
    }
}
```

### Log Channel Configuration

```php
// config/logging.php
'channels' => [
    'maestro' => [
        'driver' => 'daily',
        'path' => storage_path('logs/maestro.log'),
        'level' => env('MAESTRO_LOG_LEVEL', 'info'),
        'days' => 14,
        'formatter' => Monolog\Formatter\JsonFormatter::class,
    ],

    'maestro-errors' => [
        'driver' => 'daily',
        'path' => storage_path('logs/maestro-errors.log'),
        'level' => 'error',
        'days' => 30,
        'formatter' => Monolog\Formatter\JsonFormatter::class,
    ],
],
```

### Log Aggregation (ELK Stack)

```yaml
# filebeat.yml
filebeat.inputs:
  - type: log
    enabled: true
    paths:
      - /var/www/storage/logs/maestro*.log
    json.keys_under_root: true
    json.add_error_key: true
    fields:
      application: maestro
      environment: production

output.elasticsearch:
  hosts: ["elasticsearch:9200"]
  index: "maestro-logs-%{+yyyy.MM.dd}"
```

## Grafana Dashboards

### Workflow Overview Dashboard

```json
{
  "title": "Maestro Workflow Overview",
  "panels": [
    {
      "title": "Workflows Started (24h)",
      "type": "stat",
      "targets": [
        {
          "expr": "sum(increase(maestro_workflows_total{state=\"started\"}[24h]))"
        }
      ]
    },
    {
      "title": "Success Rate",
      "type": "gauge",
      "targets": [
        {
          "expr": "sum(rate(maestro_workflows_total{state=\"succeeded\"}[1h])) / sum(rate(maestro_workflows_total{state=~\"succeeded|failed\"}[1h])) * 100"
        }
      ]
    },
    {
      "title": "Workflow Duration (p95)",
      "type": "timeseries",
      "targets": [
        {
          "expr": "histogram_quantile(0.95, sum(rate(maestro_workflow_duration_seconds_bucket[5m])) by (le, definition))",
          "legendFormat": "{{definition}}"
        }
      ]
    },
    {
      "title": "Active Workflows",
      "type": "timeseries",
      "targets": [
        {
          "expr": "sum(maestro_active_workflows) by (definition)",
          "legendFormat": "{{definition}}"
        }
      ]
    },
    {
      "title": "Queue Depth",
      "type": "timeseries",
      "targets": [
        {
          "expr": "maestro_queue_depth",
          "legendFormat": "{{queue}}"
        }
      ]
    },
    {
      "title": "Failed Workflows",
      "type": "timeseries",
      "targets": [
        {
          "expr": "sum(rate(maestro_workflows_total{state=\"failed\"}[5m])) by (definition)",
          "legendFormat": "{{definition}}"
        }
      ]
    }
  ]
}
```

### Step Performance Dashboard

```json
{
  "title": "Maestro Step Performance",
  "panels": [
    {
      "title": "Step Duration Heatmap",
      "type": "heatmap",
      "targets": [
        {
          "expr": "sum(rate(maestro_step_duration_seconds_bucket[5m])) by (le, step)"
        }
      ]
    },
    {
      "title": "Step Failure Rate",
      "type": "timeseries",
      "targets": [
        {
          "expr": "sum(rate(maestro_steps_total{state=\"failed\"}[5m])) by (step) / sum(rate(maestro_steps_total[5m])) by (step) * 100",
          "legendFormat": "{{step}}"
        }
      ]
    },
    {
      "title": "Retries by Step",
      "type": "timeseries",
      "targets": [
        {
          "expr": "sum(rate(maestro_steps_total{state=\"retried\"}[5m])) by (step)",
          "legendFormat": "{{step}}"
        }
      ]
    }
  ]
}
```

## Alerting Rules

### Prometheus Alerting Rules

```yaml
# prometheus-alerts.yml
groups:
  - name: maestro
    rules:
      - alert: HighWorkflowFailureRate
        expr: |
          sum(rate(maestro_workflows_total{state="failed"}[5m]))
          / sum(rate(maestro_workflows_total{state=~"succeeded|failed"}[5m]))
          > 0.05
        for: 5m
        labels:
          severity: critical
        annotations:
          summary: "High workflow failure rate"
          description: "Workflow failure rate is {{ $value | printf \"%.1f\" }}% (threshold: 5%)"

      - alert: QueueBacklogHigh
        expr: maestro_queue_depth > 10000
        for: 10m
        labels:
          severity: warning
        annotations:
          summary: "Queue backlog is high"
          description: "Queue {{ $labels.queue }} has {{ $value }} pending jobs"

      - alert: WorkflowDurationHigh
        expr: |
          histogram_quantile(0.95, sum(rate(maestro_workflow_duration_seconds_bucket[15m])) by (le, definition))
          > 300
        for: 15m
        labels:
          severity: warning
        annotations:
          summary: "Workflow duration is high"
          description: "{{ $labels.definition }} p95 duration is {{ $value | printf \"%.0f\" }}s"

      - alert: StepRetryRateHigh
        expr: |
          sum(rate(maestro_steps_total{state="retried"}[5m])) by (step)
          / sum(rate(maestro_steps_total[5m])) by (step)
          > 0.1
        for: 10m
        labels:
          severity: warning
        annotations:
          summary: "High step retry rate"
          description: "Step {{ $labels.step }} retry rate is {{ $value | printf \"%.1f\" }}%"

      - alert: NoWorkflowsProcessed
        expr: |
          sum(rate(maestro_workflows_total[15m])) == 0
        for: 30m
        labels:
          severity: critical
        annotations:
          summary: "No workflows being processed"
          description: "No workflow activity in the last 30 minutes"
```

### PagerDuty Integration

```php
<?php

declare(strict_types=1);

namespace App\Alerts;

use PagerDuty\Event;

final class WorkflowAlerter
{
    public function __construct(
        private readonly string $routingKey,
    ) {}

    public function alertWorkflowFailed(WorkflowFailed $event): void
    {
        if ($this->shouldAlert($event)) {
            $pagerDutyEvent = Event::trigger(
                routingKey: $this->routingKey,
                summary: "Workflow failed: {$event->definitionKey->value}",
                source: 'maestro',
                severity: 'error',
                customDetails: [
                    'workflow_id' => $event->workflowId->value,
                    'definition' => $event->definitionKey->value,
                    'reason' => $event->reason,
                    'failed_at' => $event->failedAt->toIso8601String(),
                ],
            );

            $pagerDutyEvent->send();
        }
    }

    private function shouldAlert(WorkflowFailed $event): bool
    {
        // Don't alert for expected failures or low-priority workflows
        $lowPriorityWorkflows = ['analytics', 'background-cleanup'];

        return !in_array($event->definitionKey->value, $lowPriorityWorkflows);
    }
}
```

## Health Checks

### Liveness and Readiness Probes

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Maestro\Workflow\Infrastructure\Persistence\Models\WorkflowModel;

final class HealthController extends Controller
{
    public function liveness(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    public function readiness(): JsonResponse
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'redis' => $this->checkRedis(),
            'queue' => $this->checkQueue(),
        ];

        $healthy = !in_array(false, $checks, true);

        return response()->json([
            'status' => $healthy ? 'ok' : 'unhealthy',
            'checks' => $checks,
        ], $healthy ? 200 : 503);
    }

    public function maestroHealth(): JsonResponse
    {
        $metrics = [
            'active_workflows' => WorkflowModel::whereIn('state', ['running', 'paused'])->count(),
            'failed_last_hour' => WorkflowModel::where('state', 'failed')
                ->where('updated_at', '>', now()->subHour())
                ->count(),
            'queue_depth' => $this->getQueueDepth(),
            'oldest_running' => $this->getOldestRunningWorkflow(),
        ];

        $healthy = $metrics['failed_last_hour'] < 100
            && $metrics['queue_depth'] < 10000;

        return response()->json([
            'status' => $healthy ? 'healthy' : 'degraded',
            'metrics' => $metrics,
        ], $healthy ? 200 : 200); // 200 even if degraded (for visibility)
    }

    private function checkDatabase(): bool
    {
        try {
            DB::connection()->getPdo();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function checkRedis(): bool
    {
        try {
            Redis::ping();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function checkQueue(): bool
    {
        try {
            Queue::size('workflows');
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function getQueueDepth(): int
    {
        return Queue::size('workflows')
            + Queue::size('workflows-high')
            + Queue::size('workflows-low');
    }

    private function getOldestRunningWorkflow(): ?string
    {
        $oldest = WorkflowModel::where('state', 'running')
            ->orderBy('started_at')
            ->first();

        return $oldest?->started_at?->diffForHumans();
    }
}
```

## Distributed Tracing

### OpenTelemetry Integration

```php
<?php

declare(strict_types=1);

namespace App\Tracing;

use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TracerInterface;

final class WorkflowTracer
{
    public function __construct(
        private readonly TracerInterface $tracer,
    ) {}

    public function traceWorkflowExecution(WorkflowInstance $workflow, callable $operation): mixed
    {
        $span = $this->tracer->spanBuilder('workflow.execute')
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->setAttribute('workflow.id', $workflow->id->value)
            ->setAttribute('workflow.definition', $workflow->definitionKey->value)
            ->startSpan();

        $scope = $span->activate();

        try {
            $result = $operation();
            $span->setStatus(StatusCode::STATUS_OK);
            return $result;
        } catch (\Throwable $e) {
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            $span->recordException($e);
            throw $e;
        } finally {
            $span->end();
            $scope->detach();
        }
    }

    public function traceJobExecution(string $jobClass, callable $operation): mixed
    {
        $span = $this->tracer->spanBuilder('job.execute')
            ->setSpanKind(SpanKind::KIND_CONSUMER)
            ->setAttribute('job.class', $jobClass)
            ->startSpan();

        $scope = $span->activate();

        try {
            $result = $operation();
            $span->setStatus(StatusCode::STATUS_OK);
            return $result;
        } catch (\Throwable $e) {
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            $span->recordException($e);
            throw $e;
        } finally {
            $span->end();
            $scope->detach();
        }
    }
}
```

## Monitoring Checklist

- [ ] Event listeners configured for all critical events
- [ ] Metrics exported to Prometheus/StatsD
- [ ] Log aggregation configured (ELK/Loki)
- [ ] Grafana dashboards created
- [ ] Alert rules defined and tested
- [ ] PagerDuty/Slack integration configured
- [ ] Health check endpoints exposed
- [ ] Kubernetes probes configured
- [ ] Distributed tracing enabled
- [ ] Runbooks documented for common alerts

## Next Steps

- [Performance](performance.md) - Performance optimization
- [Scaling](scaling.md) - Multi-server deployments
- [Events Reference](../operations/events.md) - Complete event list
