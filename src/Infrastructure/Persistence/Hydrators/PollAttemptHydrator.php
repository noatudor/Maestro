<?php

declare(strict_types=1);

namespace Maestro\Workflow\Infrastructure\Persistence\Hydrators;

use Maestro\Workflow\Domain\PollAttempt;
use Maestro\Workflow\Infrastructure\Persistence\Models\PollAttemptModel;
use Maestro\Workflow\ValueObjects\JobId;
use Maestro\Workflow\ValueObjects\PollAttemptId;
use Maestro\Workflow\ValueObjects\StepRunId;

final readonly class PollAttemptHydrator
{
    public function toDomain(PollAttemptModel $pollAttemptModel): PollAttempt
    {
        $jobId = $pollAttemptModel->job_id !== null
            ? JobId::fromString($pollAttemptModel->job_id)
            : null;

        return PollAttempt::reconstitute(
            stepRunId: StepRunId::fromString($pollAttemptModel->step_run_id),
            attemptNumber: $pollAttemptModel->attempt_number,
            jobId: $jobId,
            resultComplete: $pollAttemptModel->result_complete,
            resultContinue: $pollAttemptModel->result_continue,
            nextIntervalSeconds: $pollAttemptModel->next_interval_seconds,
            executedAt: $pollAttemptModel->executed_at,
            createdAt: $pollAttemptModel->created_at,
            updatedAt: $pollAttemptModel->updated_at,
            id: PollAttemptId::fromString($pollAttemptModel->id),
        );
    }

    public function fromDomain(PollAttempt $pollAttempt): PollAttemptModel
    {
        $model = new PollAttemptModel();
        $this->updateFromDomain($model, $pollAttempt);

        return $model;
    }

    public function updateFromDomain(PollAttemptModel $pollAttemptModel, PollAttempt $pollAttempt): void
    {
        $pollAttemptModel->id = $pollAttempt->id->value;
        $pollAttemptModel->step_run_id = $pollAttempt->stepRunId->value;
        $pollAttemptModel->attempt_number = $pollAttempt->attemptNumber;
        $pollAttemptModel->job_id = $pollAttempt->jobId?->value;
        $pollAttemptModel->result_complete = $pollAttempt->resultComplete;
        $pollAttemptModel->result_continue = $pollAttempt->resultContinue;
        $pollAttemptModel->next_interval_seconds = $pollAttempt->nextIntervalSeconds;
        $pollAttemptModel->executed_at = $pollAttempt->executedAt;
    }
}
