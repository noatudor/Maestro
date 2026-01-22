<?php

declare(strict_types=1);

namespace Maestro\Workflow\Domain;

use Carbon\CarbonImmutable;
use Maestro\Workflow\Enums\CompensationRunStatus;
use Maestro\Workflow\Exceptions\InvalidStateTransitionException;
use Maestro\Workflow\ValueObjects\CompensationRunId;
use Maestro\Workflow\ValueObjects\JobId;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\WorkflowId;

/**
 * Tracks the execution of a compensation job for a single step.
 *
 * Each compensation run represents one attempt to execute the compensation
 * job for a step. The status progresses from Pending → Running → Succeeded/Failed.
 */
final class CompensationRun
{
    private function __construct(
        public readonly CompensationRunId $id,
        public readonly WorkflowId $workflowId,
        public readonly StepKey $stepKey,
        public readonly string $compensationJobClass,
        public readonly int $executionOrder,
        private CompensationRunStatus $compensationRunStatus,
        private int $attempt,
        private readonly int $maxAttempts,
        private ?JobId $currentJobId,
        private ?CarbonImmutable $startedAt,
        private ?CarbonImmutable $finishedAt,
        private ?string $failureMessage,
        private ?string $failureTrace,
        public readonly CarbonImmutable $createdAt,
        private CarbonImmutable $updatedAt,
    ) {}

    public static function create(
        WorkflowId $workflowId,
        StepKey $stepKey,
        string $compensationJobClass,
        int $executionOrder,
        int $maxAttempts = 3,
    ): self {
        $now = CarbonImmutable::now();

        return new self(
            id: CompensationRunId::generate(),
            workflowId: $workflowId,
            stepKey: $stepKey,
            compensationJobClass: $compensationJobClass,
            executionOrder: $executionOrder,
            attempt: 0,
            maxAttempts: max(1, $maxAttempts),
            currentJobId: null,
            startedAt: null,
            finishedAt: null,
            failureMessage: null,
            failureTrace: null,
            createdAt: $now,
            updatedAt: $now,
            status: CompensationRunStatus::Pending,
        );
    }

    public static function reconstitute(
        CompensationRunId $compensationRunId,
        WorkflowId $workflowId,
        StepKey $stepKey,
        string $compensationJobClass,
        int $executionOrder,
        CompensationRunStatus $compensationRunStatus,
        int $attempt,
        int $maxAttempts,
        ?JobId $currentJobId,
        ?CarbonImmutable $startedAt,
        ?CarbonImmutable $finishedAt,
        ?string $failureMessage,
        ?string $failureTrace,
        CarbonImmutable $createdAt,
        CarbonImmutable $updatedAt,
    ): self {
        return new self(
            id: $compensationRunId,
            workflowId: $workflowId,
            stepKey: $stepKey,
            compensationJobClass: $compensationJobClass,
            executionOrder: $executionOrder,
            attempt: $attempt,
            maxAttempts: $maxAttempts,
            currentJobId: $currentJobId,
            startedAt: $startedAt,
            finishedAt: $finishedAt,
            failureMessage: $failureMessage,
            failureTrace: $failureTrace,
            createdAt: $createdAt,
            updatedAt: $updatedAt,
            status: $compensationRunStatus,
        );
    }

    public function status(): CompensationRunStatus
    {
        return $this->compensationRunStatus;
    }

    public function attempt(): int
    {
        return $this->attempt;
    }

    public function maxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function currentJobId(): ?JobId
    {
        return $this->currentJobId;
    }

    public function startedAt(): ?CarbonImmutable
    {
        return $this->startedAt;
    }

    public function finishedAt(): ?CarbonImmutable
    {
        return $this->finishedAt;
    }

    public function failureMessage(): ?string
    {
        return $this->failureMessage;
    }

    public function failureTrace(): ?string
    {
        return $this->failureTrace;
    }

    public function updatedAt(): CarbonImmutable
    {
        return $this->updatedAt;
    }

    public function isPending(): bool
    {
        return $this->compensationRunStatus === CompensationRunStatus::Pending;
    }

    public function isRunning(): bool
    {
        return $this->compensationRunStatus === CompensationRunStatus::Running;
    }

    public function isSucceeded(): bool
    {
        return $this->compensationRunStatus === CompensationRunStatus::Succeeded;
    }

    public function isFailed(): bool
    {
        return $this->compensationRunStatus === CompensationRunStatus::Failed;
    }

    public function isSkipped(): bool
    {
        return $this->compensationRunStatus === CompensationRunStatus::Skipped;
    }

    public function isTerminal(): bool
    {
        return $this->compensationRunStatus->isTerminal();
    }

    public function canRetry(): bool
    {
        return $this->compensationRunStatus === CompensationRunStatus::Failed
            && $this->attempt < $this->maxAttempts;
    }

    public function hasReachedMaxAttempts(): bool
    {
        return $this->attempt >= $this->maxAttempts;
    }

    /**
     * @throws InvalidStateTransitionException
     */
    public function start(JobId $jobId): void
    {
        $this->transitionTo(CompensationRunStatus::Running);
        $this->attempt++;
        $this->currentJobId = $jobId;
        $this->startedAt = CarbonImmutable::now();
        $this->failureMessage = null;
        $this->failureTrace = null;
        $this->touch();
    }

    /**
     * @throws InvalidStateTransitionException
     */
    public function succeed(): void
    {
        $this->transitionTo(CompensationRunStatus::Succeeded);
        $this->finishedAt = CarbonImmutable::now();
        $this->touch();
    }

    /**
     * @throws InvalidStateTransitionException
     */
    public function fail(?string $message = null, ?string $trace = null): void
    {
        $this->transitionTo(CompensationRunStatus::Failed);
        $this->finishedAt = CarbonImmutable::now();
        $this->failureMessage = $message;
        $this->failureTrace = $trace !== null ? mb_substr($trace, 0, 10000) : null;
        $this->touch();
    }

    /**
     * @throws InvalidStateTransitionException
     */
    public function skip(): void
    {
        $this->transitionTo(CompensationRunStatus::Skipped);
        $this->finishedAt = CarbonImmutable::now();
        $this->touch();
    }

    /**
     * Reset to pending for retry (from failed state).
     *
     * @throws InvalidStateTransitionException
     */
    public function resetForRetry(): void
    {
        if ($this->compensationRunStatus !== CompensationRunStatus::Failed) {
            throw InvalidStateTransitionException::forCompensationRun(
                $this->compensationRunStatus,
                CompensationRunStatus::Pending,
            );
        }

        $this->compensationRunStatus = CompensationRunStatus::Pending;
        $this->currentJobId = null;
        $this->startedAt = null;
        $this->finishedAt = null;
        $this->touch();
    }

    /**
     * @throws InvalidStateTransitionException
     */
    private function transitionTo(CompensationRunStatus $compensationRunStatus): void
    {
        if (! $this->compensationRunStatus->canTransitionTo($compensationRunStatus)) {
            throw InvalidStateTransitionException::forCompensationRun($this->compensationRunStatus, $compensationRunStatus);
        }

        $this->compensationRunStatus = $compensationRunStatus;
    }

    private function touch(): void
    {
        $this->updatedAt = CarbonImmutable::now();
    }
}
