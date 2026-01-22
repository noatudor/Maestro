<?php

declare(strict_types=1);

namespace Maestro\Workflow\Http\Responses;

use Maestro\Workflow\Domain\Collections\JobRecordCollection;
use Maestro\Workflow\Domain\Collections\StepRunCollection;
use Maestro\Workflow\Domain\WorkflowInstance;

/**
 * Data transfer object for detailed workflow API responses.
 *
 * Includes workflow status, all step runs, and all jobs.
 */
final readonly class WorkflowDetailDTO
{
    /**
     * @param list<StepRunDTO> $steps
     * @param list<JobRecordDTO> $jobs
     */
    private function __construct(
        public WorkflowStatusDTO $workflow,
        public array $steps,
        public array $jobs,
    ) {}

    public static function create(
        WorkflowInstance $workflowInstance,
        StepRunCollection $stepRunCollection,
        JobRecordCollection $jobRecordCollection,
    ): self {
        $steps = [];
        foreach ($stepRunCollection as $stepRun) {
            $steps[] = StepRunDTO::fromStepRun($stepRun);
        }

        $jobs = [];
        foreach ($jobRecordCollection as $jobRecord) {
            $jobs[] = JobRecordDTO::fromJobRecord($jobRecord);
        }

        return new self(
            workflow: WorkflowStatusDTO::fromWorkflowInstance($workflowInstance),
            steps: $steps,
            jobs: $jobs,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'workflow' => $this->workflow->toArray(),
            'steps' => array_map(
                static fn (StepRunDTO $stepRunDTO): array => $stepRunDTO->toArray(),
                $this->steps,
            ),
            'jobs' => array_map(
                static fn (JobRecordDTO $jobRecordDTO): array => $jobRecordDTO->toArray(),
                $this->jobs,
            ),
        ];
    }
}
