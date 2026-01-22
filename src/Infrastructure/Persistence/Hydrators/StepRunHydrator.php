<?php

declare(strict_types=1);

namespace Maestro\Workflow\Infrastructure\Persistence\Hydrators;

use Carbon\CarbonImmutable;
use Maestro\Workflow\Domain\StepRun;
use Maestro\Workflow\Enums\RetrySource;
use Maestro\Workflow\Enums\SkipReason;
use Maestro\Workflow\Enums\StepState;
use Maestro\Workflow\Exceptions\InvalidBranchKeyException;
use Maestro\Workflow\Exceptions\InvalidStepKeyException;
use Maestro\Workflow\Infrastructure\Persistence\Models\StepRunModel;
use Maestro\Workflow\ValueObjects\BranchKey;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\StepRunId;
use Maestro\Workflow\ValueObjects\WorkflowId;
use Ramsey\Uuid\Uuid;

final readonly class StepRunHydrator
{
    /**
     * @param array{
     *     id?: StepRunId,
     *     workflow_id: WorkflowId,
     *     step_key: StepKey,
     *     attempt?: int,
     *     status?: StepState,
     *     total_job_count?: int,
     * } $data
     */
    public function createModel(array $data): StepRunModel
    {
        $model = new StepRunModel();
        $model->id = isset($data['id']) ? $data['id']->value : Uuid::uuid7()->toString();
        $model->workflow_id = $data['workflow_id']->value;
        $model->step_key = $data['step_key']->value;
        $model->attempt = $data['attempt'] ?? 1;
        $model->status = isset($data['status']) ? $data['status']->value : StepState::Pending->value;
        $model->completed_job_count = 0;
        $model->failed_job_count = 0;
        $model->total_job_count = $data['total_job_count'] ?? 0;

        return $model;
    }

    public function extractStepRunId(StepRunModel $stepRunModel): StepRunId
    {
        return StepRunId::fromString($stepRunModel->id);
    }

    public function extractWorkflowId(StepRunModel $stepRunModel): WorkflowId
    {
        return WorkflowId::fromString($stepRunModel->workflow_id);
    }

    /**
     * @throws InvalidStepKeyException
     */
    public function extractStepKey(StepRunModel $stepRunModel): StepKey
    {
        return StepKey::fromString($stepRunModel->step_key);
    }

    public function extractStatus(StepRunModel $stepRunModel): StepState
    {
        return StepState::from($stepRunModel->status);
    }

    public function extractStartedAt(StepRunModel $stepRunModel): ?CarbonImmutable
    {
        return $stepRunModel->started_at;
    }

    public function extractFinishedAt(StepRunModel $stepRunModel): ?CarbonImmutable
    {
        return $stepRunModel->finished_at;
    }

    /**
     * @return array{
     *     id: StepRunId,
     *     workflow_id: WorkflowId,
     *     step_key: StepKey,
     *     attempt: int,
     *     status: StepState,
     *     started_at: CarbonImmutable|null,
     *     finished_at: CarbonImmutable|null,
     *     failure_code: string|null,
     *     failure_message: string|null,
     *     completed_job_count: int,
     *     failed_job_count: int,
     *     total_job_count: int,
     *     superseded_by_id: StepRunId|null,
     *     superseded_at: CarbonImmutable|null,
     *     retry_source: RetrySource|null,
     *     skip_reason: SkipReason|null,
     *     skip_message: string|null,
     *     branch_key: BranchKey|null,
     *     poll_attempt_count: int,
     *     next_poll_at: CarbonImmutable|null,
     *     poll_started_at: CarbonImmutable|null,
     *     created_at: CarbonImmutable,
     *     updated_at: CarbonImmutable,
     * }
     *
     * @throws InvalidStepKeyException
     * @throws InvalidBranchKeyException
     */
    public function extractAll(StepRunModel $stepRunModel): array
    {
        return [
            'id' => $this->extractStepRunId($stepRunModel),
            'workflow_id' => $this->extractWorkflowId($stepRunModel),
            'step_key' => $this->extractStepKey($stepRunModel),
            'attempt' => $stepRunModel->attempt,
            'status' => $this->extractStatus($stepRunModel),
            'started_at' => $stepRunModel->started_at,
            'finished_at' => $stepRunModel->finished_at,
            'failure_code' => $stepRunModel->failure_code,
            'failure_message' => $stepRunModel->failure_message,
            'completed_job_count' => $stepRunModel->completed_job_count,
            'failed_job_count' => $stepRunModel->failed_job_count,
            'total_job_count' => $stepRunModel->total_job_count,
            'superseded_by_id' => $this->extractSupersededById($stepRunModel),
            'superseded_at' => $stepRunModel->superseded_at,
            'retry_source' => $this->extractRetrySource($stepRunModel),
            'skip_reason' => $this->extractSkipReason($stepRunModel),
            'skip_message' => $stepRunModel->skip_message,
            'branch_key' => $this->extractBranchKey($stepRunModel),
            'poll_attempt_count' => $stepRunModel->poll_attempt_count,
            'next_poll_at' => $stepRunModel->next_poll_at,
            'poll_started_at' => $stepRunModel->poll_started_at,
            'created_at' => $stepRunModel->created_at,
            'updated_at' => $stepRunModel->updated_at,
        ];
    }

    public function extractSupersededById(StepRunModel $stepRunModel): ?StepRunId
    {
        return $stepRunModel->superseded_by_id !== null
            ? StepRunId::fromString($stepRunModel->superseded_by_id)
            : null;
    }

    public function extractRetrySource(StepRunModel $stepRunModel): ?RetrySource
    {
        return $stepRunModel->retry_source !== null
            ? RetrySource::from($stepRunModel->retry_source)
            : null;
    }

    public function extractSkipReason(StepRunModel $stepRunModel): ?SkipReason
    {
        return $stepRunModel->skip_reason !== null
            ? SkipReason::from($stepRunModel->skip_reason)
            : null;
    }

    /**
     * @throws InvalidBranchKeyException
     */
    public function extractBranchKey(StepRunModel $stepRunModel): ?BranchKey
    {
        return $stepRunModel->branch_key !== null
            ? BranchKey::fromString($stepRunModel->branch_key)
            : null;
    }

    public function updateStatus(StepRunModel $stepRunModel, StepState $stepState): void
    {
        $stepRunModel->status = $stepState->value;
    }

    public function markStarted(StepRunModel $stepRunModel): void
    {
        $stepRunModel->status = StepState::Running->value;
        $stepRunModel->started_at = CarbonImmutable::now();
    }

    public function markSucceeded(StepRunModel $stepRunModel): void
    {
        $stepRunModel->status = StepState::Succeeded->value;
        $stepRunModel->finished_at = CarbonImmutable::now();
    }

    public function markFailed(StepRunModel $stepRunModel, ?string $code = null, ?string $message = null): void
    {
        $stepRunModel->status = StepState::Failed->value;
        $stepRunModel->finished_at = CarbonImmutable::now();
        $stepRunModel->failure_code = $code;
        $stepRunModel->failure_message = $message;
    }

    public function incrementFailedJobCount(StepRunModel $stepRunModel): void
    {
        $stepRunModel->failed_job_count++;
    }

    public function setTotalJobCount(StepRunModel $stepRunModel, int $count): void
    {
        $stepRunModel->total_job_count = $count;
    }

    /**
     * @throws InvalidStepKeyException
     * @throws InvalidBranchKeyException
     */
    public function toDomain(StepRunModel $stepRunModel): StepRun
    {
        return StepRun::reconstitute(
            stepRunId: $this->extractStepRunId($stepRunModel),
            workflowId: $this->extractWorkflowId($stepRunModel),
            stepKey: $this->extractStepKey($stepRunModel),
            attempt: $stepRunModel->attempt,
            stepState: $this->extractStatus($stepRunModel),
            startedAt: $stepRunModel->started_at,
            finishedAt: $stepRunModel->finished_at,
            failureCode: $stepRunModel->failure_code,
            failureMessage: $stepRunModel->failure_message,
            completedJobCount: $stepRunModel->completed_job_count,
            failedJobCount: $stepRunModel->failed_job_count,
            totalJobCount: $stepRunModel->total_job_count,
            supersededById: $this->extractSupersededById($stepRunModel),
            supersededAt: $stepRunModel->superseded_at,
            retrySource: $this->extractRetrySource($stepRunModel),
            skipReason: $this->extractSkipReason($stepRunModel),
            skipMessage: $stepRunModel->skip_message,
            branchKey: $this->extractBranchKey($stepRunModel),
            pollAttemptCount: $stepRunModel->poll_attempt_count,
            nextPollAt: $stepRunModel->next_poll_at,
            pollStartedAt: $stepRunModel->poll_started_at,
            createdAt: $stepRunModel->created_at,
            updatedAt: $stepRunModel->updated_at,
        );
    }

    public function fromDomain(StepRun $stepRun): StepRunModel
    {
        $model = new StepRunModel();
        $model->id = $stepRun->id->value;
        $model->workflow_id = $stepRun->workflowId->value;
        $model->step_key = $stepRun->stepKey->value;
        $model->attempt = $stepRun->attempt;
        $model->status = $stepRun->status()->value;
        $model->started_at = $stepRun->startedAt();
        $model->finished_at = $stepRun->finishedAt();
        $model->failure_code = $stepRun->failureCode();
        $model->failure_message = $stepRun->failureMessage();
        $model->completed_job_count = $stepRun->completedJobCount();
        $model->failed_job_count = $stepRun->failedJobCount();
        $model->total_job_count = $stepRun->totalJobCount();
        $model->superseded_by_id = $stepRun->supersededById()?->value;
        $model->superseded_at = $stepRun->supersededAt();
        $model->retry_source = $stepRun->retrySource()?->value;
        $model->skip_reason = $stepRun->skipReason()?->value;
        $model->skip_message = $stepRun->skipMessage();
        $model->branch_key = $stepRun->branchKey()?->value;
        $model->poll_attempt_count = $stepRun->pollAttemptCount();
        $model->next_poll_at = $stepRun->nextPollAt();
        $model->poll_started_at = $stepRun->pollStartedAt();
        $model->created_at = $stepRun->createdAt;
        $model->updated_at = $stepRun->updatedAt();

        return $model;
    }

    public function updateFromDomain(StepRunModel $stepRunModel, StepRun $stepRun): void
    {
        $stepRunModel->status = $stepRun->status()->value;
        $stepRunModel->started_at = $stepRun->startedAt();
        $stepRunModel->finished_at = $stepRun->finishedAt();
        $stepRunModel->failure_code = $stepRun->failureCode();
        $stepRunModel->failure_message = $stepRun->failureMessage();
        $stepRunModel->completed_job_count = $stepRun->completedJobCount();
        $stepRunModel->failed_job_count = $stepRun->failedJobCount();
        $stepRunModel->total_job_count = $stepRun->totalJobCount();
        $stepRunModel->superseded_by_id = $stepRun->supersededById()?->value;
        $stepRunModel->superseded_at = $stepRun->supersededAt();
        $stepRunModel->retry_source = $stepRun->retrySource()?->value;
        $stepRunModel->skip_reason = $stepRun->skipReason()?->value;
        $stepRunModel->skip_message = $stepRun->skipMessage();
        $stepRunModel->branch_key = $stepRun->branchKey()?->value;
        $stepRunModel->poll_attempt_count = $stepRun->pollAttemptCount();
        $stepRunModel->next_poll_at = $stepRun->nextPollAt();
        $stepRunModel->poll_started_at = $stepRun->pollStartedAt();
        $stepRunModel->updated_at = $stepRun->updatedAt();
    }

    public function markSuperseded(StepRunModel $stepRunModel, StepRunId $stepRunId): void
    {
        $stepRunModel->status = StepState::Superseded->value;
        $stepRunModel->superseded_by_id = $stepRunId->value;
        $stepRunModel->superseded_at = CarbonImmutable::now();
    }

    public function markSkipped(StepRunModel $stepRunModel, SkipReason $skipReason, ?string $message = null): void
    {
        $stepRunModel->status = StepState::Skipped->value;
        $stepRunModel->finished_at = CarbonImmutable::now();
        $stepRunModel->skip_reason = $skipReason->value;
        $stepRunModel->skip_message = $message;
    }

    public function markPolling(StepRunModel $stepRunModel): void
    {
        $now = CarbonImmutable::now();
        $stepRunModel->status = StepState::Polling->value;
        $stepRunModel->started_at = $now;
        $stepRunModel->poll_started_at = $now;
    }

    public function scheduleNextPoll(StepRunModel $stepRunModel, CarbonImmutable $nextPollAt): void
    {
        $stepRunModel->next_poll_at = $nextPollAt;
    }

    public function incrementPollAttemptCount(StepRunModel $stepRunModel): void
    {
        $stepRunModel->poll_attempt_count++;
    }

    public function markTimedOut(StepRunModel $stepRunModel, ?string $code = null, ?string $message = null): void
    {
        $stepRunModel->status = StepState::TimedOut->value;
        $stepRunModel->finished_at = CarbonImmutable::now();
        $stepRunModel->next_poll_at = null;
        $stepRunModel->failure_code = $code;
        $stepRunModel->failure_message = $message;
    }
}
