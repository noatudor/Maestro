<?php

declare(strict_types=1);

namespace Maestro\Workflow\Domain;

use Carbon\CarbonImmutable;
use Maestro\Workflow\Enums\RetrySource;
use Maestro\Workflow\Enums\SkipReason;
use Maestro\Workflow\Enums\StepState;
use Maestro\Workflow\Exceptions\InvalidStateTransitionException;
use Maestro\Workflow\ValueObjects\BranchKey;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\StepRunId;
use Maestro\Workflow\ValueObjects\WorkflowId;

final class StepRun
{
    private function __construct(
        public readonly StepRunId $id,
        public readonly WorkflowId $workflowId,
        public readonly StepKey $stepKey,
        public readonly int $attempt,
        public readonly CarbonImmutable $createdAt,
        private StepState $stepState,
        private ?CarbonImmutable $startedAt,
        private ?CarbonImmutable $finishedAt,
        private ?string $failureCode,
        private ?string $failureMessage,
        private int $completedJobCount,
        private int $failedJobCount,
        private int $totalJobCount,
        private ?StepRunId $supersededById,
        private ?CarbonImmutable $supersededAt,
        private readonly ?RetrySource $retrySource,
        private ?SkipReason $skipReason,
        private ?string $skipMessage,
        private readonly ?BranchKey $branchKey,
        private int $pollAttemptCount,
        private ?CarbonImmutable $nextPollAt,
        private ?CarbonImmutable $pollStartedAt,
        private CarbonImmutable $updatedAt,
    ) {}

    public static function create(
        WorkflowId $workflowId,
        StepKey $stepKey,
        int $attempt = 1,
        int $totalJobCount = 0,
        ?StepRunId $stepRunId = null,
        ?RetrySource $retrySource = null,
        ?BranchKey $branchKey = null,
    ): self {
        $now = CarbonImmutable::now();

        return new self(
            id: $stepRunId ?? StepRunId::generate(),
            workflowId: $workflowId,
            stepKey: $stepKey,
            attempt: $attempt,
            createdAt: $now,
            stepState: StepState::Pending,
            startedAt: null,
            finishedAt: null,
            failureCode: null,
            failureMessage: null,
            completedJobCount: 0,
            failedJobCount: 0,
            totalJobCount: $totalJobCount,
            supersededById: null,
            supersededAt: null,
            retrySource: $retrySource,
            skipReason: null,
            skipMessage: null,
            branchKey: $branchKey,
            pollAttemptCount: 0,
            nextPollAt: null,
            pollStartedAt: null,
            updatedAt: $now,
        );
    }

    public static function reconstitute(
        StepRunId $stepRunId,
        WorkflowId $workflowId,
        StepKey $stepKey,
        int $attempt,
        StepState $stepState,
        ?CarbonImmutable $startedAt,
        ?CarbonImmutable $finishedAt,
        ?string $failureCode,
        ?string $failureMessage,
        int $completedJobCount,
        int $failedJobCount,
        int $totalJobCount,
        ?StepRunId $supersededById,
        ?CarbonImmutable $supersededAt,
        ?RetrySource $retrySource,
        ?SkipReason $skipReason,
        ?string $skipMessage,
        ?BranchKey $branchKey,
        int $pollAttemptCount,
        ?CarbonImmutable $nextPollAt,
        ?CarbonImmutable $pollStartedAt,
        CarbonImmutable $createdAt,
        CarbonImmutable $updatedAt,
    ): self {
        return new self(
            id: $stepRunId,
            workflowId: $workflowId,
            stepKey: $stepKey,
            attempt: $attempt,
            createdAt: $createdAt,
            stepState: $stepState,
            startedAt: $startedAt,
            finishedAt: $finishedAt,
            failureCode: $failureCode,
            failureMessage: $failureMessage,
            completedJobCount: $completedJobCount,
            failedJobCount: $failedJobCount,
            totalJobCount: $totalJobCount,
            supersededById: $supersededById,
            supersededAt: $supersededAt,
            retrySource: $retrySource,
            skipReason: $skipReason,
            skipMessage: $skipMessage,
            branchKey: $branchKey,
            pollAttemptCount: $pollAttemptCount,
            nextPollAt: $nextPollAt,
            pollStartedAt: $pollStartedAt,
            updatedAt: $updatedAt,
        );
    }

    public function status(): StepState
    {
        return $this->stepState;
    }

    public function startedAt(): ?CarbonImmutable
    {
        return $this->startedAt;
    }

    public function finishedAt(): ?CarbonImmutable
    {
        return $this->finishedAt;
    }

    public function failureCode(): ?string
    {
        return $this->failureCode;
    }

    public function failureMessage(): ?string
    {
        return $this->failureMessage;
    }

    public function failedJobCount(): int
    {
        return $this->failedJobCount;
    }

    public function totalJobCount(): int
    {
        return $this->totalJobCount;
    }

    public function updatedAt(): CarbonImmutable
    {
        return $this->updatedAt;
    }

    public function succeededJobCount(): int
    {
        return $this->completedJobCount - $this->failedJobCount;
    }

    public function isPending(): bool
    {
        return $this->stepState === StepState::Pending;
    }

    public function isRunning(): bool
    {
        return $this->stepState === StepState::Running;
    }

    public function isSucceeded(): bool
    {
        return $this->stepState === StepState::Succeeded;
    }

    public function isFailed(): bool
    {
        return $this->stepState === StepState::Failed;
    }

    public function isTerminal(): bool
    {
        return $this->stepState->isTerminal();
    }

    public function isSuperseded(): bool
    {
        return $this->stepState === StepState::Superseded;
    }

    public function supersededById(): ?StepRunId
    {
        return $this->supersededById;
    }

    public function supersededAt(): ?CarbonImmutable
    {
        return $this->supersededAt;
    }

    public function retrySource(): ?RetrySource
    {
        return $this->retrySource;
    }

    public function skipReason(): ?SkipReason
    {
        return $this->skipReason;
    }

    public function skipMessage(): ?string
    {
        return $this->skipMessage;
    }

    public function branchKey(): ?BranchKey
    {
        return $this->branchKey;
    }

    public function isSkipped(): bool
    {
        return $this->stepState === StepState::Skipped;
    }

    public function isPolling(): bool
    {
        return $this->stepState === StepState::Polling;
    }

    public function isTimedOut(): bool
    {
        return $this->stepState === StepState::TimedOut;
    }

    public function pollAttemptCount(): int
    {
        return $this->pollAttemptCount;
    }

    public function nextPollAt(): ?CarbonImmutable
    {
        return $this->nextPollAt;
    }

    public function pollStartedAt(): ?CarbonImmutable
    {
        return $this->pollStartedAt;
    }

    /**
     * Transition to polling state for polling steps.
     *
     * @throws InvalidStateTransitionException
     */
    public function startPolling(): void
    {
        $this->transitionTo(StepState::Polling);
        $this->startedAt = CarbonImmutable::now();
        $this->pollStartedAt = CarbonImmutable::now();
        $this->touch();
    }

    /**
     * Schedule the next poll execution.
     */
    public function scheduleNextPoll(CarbonImmutable $nextPollAt): void
    {
        $this->nextPollAt = $nextPollAt;
        $this->touch();
    }

    /**
     * Record a poll attempt.
     */
    public function recordPollAttempt(): void
    {
        $this->pollAttemptCount++;
        $this->touch();
    }

    /**
     * Mark polling step as timed out.
     *
     * @throws InvalidStateTransitionException
     */
    public function timeout(?string $code = null, ?string $message = null): void
    {
        $this->transitionTo(StepState::TimedOut);
        $this->finishedAt = CarbonImmutable::now();
        $this->nextPollAt = null;
        $this->failureCode = $code;
        $this->failureMessage = $message;
        $this->touch();
    }

    /**
     * Calculate duration since polling started.
     */
    public function pollingDuration(): ?int
    {
        if (! $this->pollStartedAt instanceof CarbonImmutable) {
            return null;
        }

        $endTime = $this->finishedAt ?? CarbonImmutable::now();

        return (int) $this->pollStartedAt->diffInSeconds($endTime);
    }

    /**
     * Check if next poll is due.
     */
    public function isPollDue(): bool
    {
        if (! $this->isPolling()) {
            return false;
        }

        if (! $this->nextPollAt instanceof CarbonImmutable) {
            return false;
        }

        return $this->nextPollAt->lessThanOrEqualTo(CarbonImmutable::now());
    }

    /**
     * Mark this step run as skipped.
     *
     * @throws InvalidStateTransitionException
     */
    public function skip(SkipReason $skipReason, ?string $message = null): void
    {
        $this->transitionTo(StepState::Skipped);
        $this->finishedAt = CarbonImmutable::now();
        $this->skipReason = $skipReason;
        $this->skipMessage = $message;
        $this->touch();
    }

    /**
     * Mark this step run as superseded by another step run.
     *
     * @throws InvalidStateTransitionException
     */
    public function supersede(StepRunId $stepRunId): void
    {
        $this->transitionTo(StepState::Superseded);
        $this->supersededById = $stepRunId;
        $this->supersededAt = CarbonImmutable::now();
        $this->touch();
    }

    public function hasAllJobsCompleted(): bool
    {
        return $this->totalJobCount > 0 && $this->completedJobCount >= $this->totalJobCount;
    }

    public function completedJobCount(): int
    {
        return $this->completedJobCount;
    }

    /**
     * @throws InvalidStateTransitionException
     */
    public function start(): void
    {
        $this->transitionTo(StepState::Running);
        $this->startedAt = CarbonImmutable::now();
        $this->touch();
    }

    /**
     * @throws InvalidStateTransitionException
     */
    public function succeed(): void
    {
        $this->transitionTo(StepState::Succeeded);
        $this->finishedAt = CarbonImmutable::now();
        $this->touch();
    }

    /**
     * @throws InvalidStateTransitionException
     */
    public function fail(?string $code = null, ?string $message = null): void
    {
        $this->transitionTo(StepState::Failed);
        $this->finishedAt = CarbonImmutable::now();
        $this->failureCode = $code;
        $this->failureMessage = $message;
        $this->touch();
    }

    public function setTotalJobCount(int $count): void
    {
        $this->totalJobCount = $count;
        $this->touch();
    }

    public function incrementFailedJobCount(): void
    {
        $this->completedJobCount++;
        $this->failedJobCount++;
        $this->touch();
    }

    public function recordJobSuccess(): void
    {
        $this->completedJobCount++;
        $this->touch();
    }

    public function recordJobFailure(): void
    {
        $this->completedJobCount++;
        $this->failedJobCount++;
        $this->touch();
    }

    public function duration(): ?int
    {
        if (! $this->startedAt instanceof CarbonImmutable) {
            return null;
        }

        $endTime = $this->finishedAt ?? CarbonImmutable::now();

        return (int) $this->startedAt->diffInMilliseconds($endTime);
    }

    /**
     * @throws InvalidStateTransitionException
     */
    private function transitionTo(StepState $stepState): void
    {
        if (! $this->stepState->canTransitionTo($stepState)) {
            throw InvalidStateTransitionException::forStep($this->stepState, $stepState);
        }

        $this->stepState = $stepState;
    }

    private function touch(): void
    {
        $this->updatedAt = CarbonImmutable::now();
    }
}
