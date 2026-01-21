<?php

declare(strict_types=1);

namespace Maestro\Workflow\Contracts;

use Carbon\CarbonImmutable;
use Maestro\Workflow\Domain\Collections\JobRecordCollection;
use Maestro\Workflow\Domain\JobRecord;
use Maestro\Workflow\Enums\JobState;
use Maestro\Workflow\Exceptions\JobNotFoundException;
use Maestro\Workflow\ValueObjects\JobId;
use Maestro\Workflow\ValueObjects\StepRunId;
use Maestro\Workflow\ValueObjects\WorkflowId;

interface JobLedgerRepository
{
    public function find(JobId $jobId): ?JobRecord;

    public function save(JobRecord $jobRecord): void;

    public function findByStepRunId(StepRunId $stepRunId): JobRecordCollection;

    public function findByWorkflowId(WorkflowId $workflowId): JobRecordCollection;

    public function findByStepRunIdAndState(StepRunId $stepRunId, JobState $jobState): JobRecordCollection;

    public function countByStepRunId(StepRunId $stepRunId): int;

    public function countByStepRunIdAndState(StepRunId $stepRunId, JobState $jobState): int;

    public function findByJobUuid(string $jobUuid): ?JobRecord;

    /**
     * @throws JobNotFoundException
     */
    public function findOrFail(JobId $jobId): JobRecord;

    /**
     * Find jobs in RUNNING state that started before the threshold.
     */
    public function findZombieJobs(CarbonImmutable $threshold): JobRecordCollection;

    /**
     * Find jobs in DISPATCHED state that were dispatched before the threshold.
     */
    public function findStaleDispatchedJobs(CarbonImmutable $threshold): JobRecordCollection;
}
