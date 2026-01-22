<?php

declare(strict_types=1);

namespace Maestro\Workflow\Application\Job;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Maestro\Workflow\ValueObjects\CompensationRunId;
use Maestro\Workflow\ValueObjects\JobId;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\WorkflowId;

/**
 * Base class for compensation jobs.
 *
 * Compensation jobs are used to undo the side effects of forward jobs.
 * They are executed in reverse step order when compensation is triggered.
 *
 * Unlike OrchestratedJob, CompensationJob does not have a step run ID.
 * Instead, it carries a compensation run ID for tracking.
 */
abstract class CompensationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly WorkflowId $workflowId,
        public readonly StepKey $stepKey,
        public readonly CompensationRunId $compensationRunId,
        public readonly JobId $jobId,
    ) {}

    /**
     * Execute the compensation logic.
     *
     * Subclasses implement this method to perform the actual rollback/compensation.
     */
    abstract public function handle(): void;

    /**
     * Get the display name of the job for logging/debugging.
     */
    final public function displayName(): string
    {
        return sprintf(
            '%s [workflow:%s step:%s]',
            static::class,
            $this->workflowId->value,
            $this->stepKey->value,
        );
    }

    /**
     * Get the tags for queue monitoring.
     *
     * @return list<string>
     */
    final public function tags(): array
    {
        return [
            'maestro',
            'compensation',
            sprintf('workflow:%s', $this->workflowId->value),
            sprintf('step:%s', $this->stepKey->value),
            sprintf('compensation-run:%s', $this->compensationRunId->value),
        ];
    }
}
