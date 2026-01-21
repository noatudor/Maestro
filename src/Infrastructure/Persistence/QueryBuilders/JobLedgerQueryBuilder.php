<?php

declare(strict_types=1);

namespace Maestro\Workflow\Infrastructure\Persistence\QueryBuilders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Maestro\Workflow\Enums\JobState;
use Maestro\Workflow\Infrastructure\Persistence\Models\JobLedgerModel;

/**
 * @extends Builder<JobLedgerModel>
 */
final class JobLedgerQueryBuilder extends Builder
{
    public function dispatched(): self
    {
        return $this->where('status', JobState::Dispatched->value);
    }

    public function running(): self
    {
        return $this->where('status', JobState::Running->value);
    }

    public function succeeded(): self
    {
        return $this->where('status', JobState::Succeeded->value);
    }

    public function failed(): self
    {
        return $this->where('status', JobState::Failed->value);
    }

    public function terminal(): self
    {
        return $this->whereIn('status', [
            JobState::Succeeded->value,
            JobState::Failed->value,
        ]);
    }

    public function inProgress(): self
    {
        return $this->whereIn('status', [
            JobState::Dispatched->value,
            JobState::Running->value,
        ]);
    }

    public function forWorkflow(string $workflowId): self
    {
        return $this->where('workflow_id', $workflowId);
    }

    public function forStepRun(string $stepRunId): self
    {
        return $this->where('step_run_id', $stepRunId);
    }

    public function forQueue(string $queue): self
    {
        return $this->where('queue', $queue);
    }

    public function forJobClass(string $jobClass): self
    {
        return $this->where('job_class', $jobClass);
    }

    public function forWorker(string $workerId): self
    {
        return $this->where('worker_id', $workerId);
    }

    public function zombies(CarbonImmutable $threshold): self
    {
        return $this->running()->where('started_at', '<', $threshold);
    }

    public function staleDispatched(CarbonImmutable $threshold): self
    {
        return $this->dispatched()->where('dispatched_at', '<', $threshold);
    }

    public function dispatchedBetween(CarbonImmutable $start, CarbonImmutable $end): self
    {
        return $this->whereBetween('dispatched_at', [$start, $end]);
    }

    public function withLongRuntime(int $thresholdMs): self
    {
        return $this->where('runtime_ms', '>', $thresholdMs);
    }

    public function byJobUuid(string $jobUuid): self
    {
        return $this->where('job_uuid', $jobUuid);
    }
}
