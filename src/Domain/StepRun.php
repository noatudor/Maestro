<?php

declare(strict_types=1);

namespace Maestro\Workflow\Domain;

use Carbon\CarbonImmutable;
use Maestro\Workflow\Enums\StepState;
use Maestro\Workflow\Exceptions\InvalidStateTransitionException;
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
        private CarbonImmutable $updatedAt,
    ) {}

    public static function create(
        WorkflowId $workflowId,
        StepKey $stepKey,
        int $attempt = 1,
        int $totalJobCount = 0,
        ?StepRunId $stepRunId = null,
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
