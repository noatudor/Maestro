<?php

declare(strict_types=1);

namespace Maestro\Workflow\Infrastructure\Persistence\QueryBuilders;

use Illuminate\Database\Eloquent\Builder;
use Maestro\Workflow\Enums\StepState;
use Maestro\Workflow\Infrastructure\Persistence\Models\StepRunModel;

/**
 * @extends Builder<StepRunModel>
 */
final class StepRunQueryBuilder extends Builder
{
    public function pending(): self
    {
        return $this->where('status', StepState::Pending->value);
    }

    public function running(): self
    {
        return $this->where('status', StepState::Running->value);
    }

    public function succeeded(): self
    {
        return $this->where('status', StepState::Succeeded->value);
    }

    public function failed(): self
    {
        return $this->where('status', StepState::Failed->value);
    }

    public function terminal(): self
    {
        return $this->whereIn('status', [
            StepState::Succeeded->value,
            StepState::Failed->value,
        ]);
    }

    public function forWorkflow(string $workflowId): self
    {
        return $this->where('workflow_id', $workflowId);
    }

    public function forStep(string $stepKey): self
    {
        return $this->where('step_key', $stepKey);
    }

    public function forWorkflowAndStep(string $workflowId, string $stepKey): self
    {
        return $this->forWorkflow($workflowId)->forStep($stepKey);
    }

    public function forAttempt(int $attempt): self
    {
        return $this->where('attempt', $attempt);
    }

    public function latestAttempt(): self
    {
        return $this->orderByDesc('attempt');
    }

    public function firstAttempt(): self
    {
        return $this->forAttempt(1);
    }

    public function withFailedJobs(): self
    {
        return $this->where('failed_job_count', '>', 0);
    }

    public function withoutFailedJobs(): self
    {
        return $this->where('failed_job_count', 0);
    }

    public function allJobsCompleted(): self
    {
        return $this->where('total_job_count', '>', 0)
            ->whereRaw('failed_job_count + (SELECT COUNT(*) FROM maestro_job_ledger WHERE step_run_id = maestro_step_runs.id AND status = ?) = total_job_count', ['succeeded']);
    }
}
