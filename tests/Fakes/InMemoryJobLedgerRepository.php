<?php

declare(strict_types=1);

namespace Maestro\Workflow\Tests\Fakes;

use Carbon\CarbonImmutable;
use Maestro\Workflow\Contracts\JobLedgerRepository;
use Maestro\Workflow\Domain\Collections\JobRecordCollection;
use Maestro\Workflow\Domain\JobRecord;
use Maestro\Workflow\Enums\JobState;
use Maestro\Workflow\Exceptions\JobNotFoundException;
use Maestro\Workflow\ValueObjects\JobId;
use Maestro\Workflow\ValueObjects\StepRunId;
use Maestro\Workflow\ValueObjects\WorkflowId;

final class InMemoryJobLedgerRepository implements JobLedgerRepository
{
    /**
     * @var array<string, JobRecord>
     */
    private array $jobs = [];

    public function find(JobId $jobId): ?JobRecord
    {
        return $this->jobs[$jobId->value] ?? null;
    }

    /**
     * @throws JobNotFoundException
     */
    public function findOrFail(JobId $jobId): JobRecord
    {
        $job = $this->find($jobId);

        if (! $job instanceof JobRecord) {
            throw JobNotFoundException::withId($jobId);
        }

        return $job;
    }

    public function save(JobRecord $jobRecord): void
    {
        $this->jobs[$jobRecord->id->value] = $jobRecord;
    }

    public function findByStepRunId(StepRunId $stepRunId): JobRecordCollection
    {
        $jobs = array_filter(
            $this->jobs,
            static fn (JobRecord $jobRecord): bool => $jobRecord->stepRunId->value === $stepRunId->value,
        );

        return new JobRecordCollection(array_values($jobs));
    }

    public function findByWorkflowId(WorkflowId $workflowId): JobRecordCollection
    {
        $jobs = array_filter(
            $this->jobs,
            static fn (JobRecord $jobRecord): bool => $jobRecord->workflowId->value === $workflowId->value,
        );

        return new JobRecordCollection(array_values($jobs));
    }

    public function findByStepRunIdAndState(StepRunId $stepRunId, JobState $jobState): JobRecordCollection
    {
        $jobs = array_filter(
            $this->jobs,
            static fn (JobRecord $jobRecord): bool => $jobRecord->stepRunId->value === $stepRunId->value
                && $jobRecord->status() === $jobState,
        );

        return new JobRecordCollection(array_values($jobs));
    }

    public function countByStepRunId(StepRunId $stepRunId): int
    {
        return $this->findByStepRunId($stepRunId)->count();
    }

    public function countByStepRunIdAndState(StepRunId $stepRunId, JobState $jobState): int
    {
        return $this->findByStepRunIdAndState($stepRunId, $jobState)->count();
    }

    public function findByJobUuid(string $jobUuid): ?JobRecord
    {
        foreach ($this->jobs as $job) {
            if ($job->jobUuid === $jobUuid) {
                return $job;
            }
        }

        return null;
    }

    public function clear(): void
    {
        $this->jobs = [];
    }

    /**
     * @return list<JobRecord>
     */
    public function all(): array
    {
        return array_values($this->jobs);
    }

    public function findZombieJobs(CarbonImmutable $threshold): JobRecordCollection
    {
        $jobs = array_filter(
            $this->jobs,
            static fn (JobRecord $jobRecord): bool => $jobRecord->status() === JobState::Running
                && $jobRecord->startedAt() instanceof CarbonImmutable
                && $jobRecord->startedAt()->lessThan($threshold),
        );

        return new JobRecordCollection(array_values($jobs));
    }

    public function findStaleDispatchedJobs(CarbonImmutable $threshold): JobRecordCollection
    {
        $jobs = array_filter(
            $this->jobs,
            static fn (JobRecord $jobRecord): bool => $jobRecord->status() === JobState::Dispatched
                && $jobRecord->dispatchedAt->lessThan($threshold),
        );

        return new JobRecordCollection(array_values($jobs));
    }

    public function deleteByWorkflowId(WorkflowId $workflowId): void
    {
        $this->jobs = array_filter(
            $this->jobs,
            static fn (JobRecord $jobRecord): bool => $jobRecord->workflowId->value !== $workflowId->value,
        );
    }
}
