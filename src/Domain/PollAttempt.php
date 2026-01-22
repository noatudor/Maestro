<?php

declare(strict_types=1);

namespace Maestro\Workflow\Domain;

use Carbon\CarbonImmutable;
use Maestro\Workflow\ValueObjects\JobId;
use Maestro\Workflow\ValueObjects\PollAttemptId;
use Maestro\Workflow\ValueObjects\StepRunId;

/**
 * Records a single poll attempt for a polling step.
 *
 * Each poll attempt captures the result returned by the polling job:
 * whether the condition was met, whether polling should continue,
 * and any custom interval for the next poll.
 */
final readonly class PollAttempt
{
    private function __construct(
        public PollAttemptId $id,
        public StepRunId $stepRunId,
        public int $attemptNumber,
        public ?JobId $jobId,
        public bool $resultComplete,
        public bool $resultContinue,
        public ?int $nextIntervalSeconds,
        public CarbonImmutable $executedAt,
        public CarbonImmutable $createdAt,
        public CarbonImmutable $updatedAt,
    ) {}

    public static function create(
        StepRunId $stepRunId,
        int $attemptNumber,
        ?JobId $jobId,
        bool $resultComplete,
        bool $resultContinue,
        ?int $nextIntervalSeconds,
    ): self {
        $now = CarbonImmutable::now();

        return new self(
            id: PollAttemptId::generate(),
            stepRunId: $stepRunId,
            attemptNumber: $attemptNumber,
            jobId: $jobId,
            resultComplete: $resultComplete,
            resultContinue: $resultContinue,
            nextIntervalSeconds: $nextIntervalSeconds,
            executedAt: $now,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    public static function reconstitute(
        PollAttemptId $pollAttemptId,
        StepRunId $stepRunId,
        int $attemptNumber,
        ?JobId $jobId,
        bool $resultComplete,
        bool $resultContinue,
        ?int $nextIntervalSeconds,
        CarbonImmutable $executedAt,
        CarbonImmutable $createdAt,
        CarbonImmutable $updatedAt,
    ): self {
        return new self(
            id: $pollAttemptId,
            stepRunId: $stepRunId,
            attemptNumber: $attemptNumber,
            jobId: $jobId,
            resultComplete: $resultComplete,
            resultContinue: $resultContinue,
            nextIntervalSeconds: $nextIntervalSeconds,
            executedAt: $executedAt,
            createdAt: $createdAt,
            updatedAt: $updatedAt,
        );
    }

    public function isComplete(): bool
    {
        return $this->resultComplete;
    }

    public function shouldContinue(): bool
    {
        return $this->resultContinue;
    }

    public function wasAborted(): bool
    {
        return ! $this->resultComplete && ! $this->resultContinue;
    }
}
