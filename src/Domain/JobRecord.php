<?php

declare(strict_types=1);

namespace Maestro\Workflow\Domain;

use Carbon\CarbonImmutable;
use Maestro\Workflow\Enums\JobState;
use Maestro\Workflow\Exceptions\InvalidStateTransitionException;
use Maestro\Workflow\ValueObjects\JobId;
use Maestro\Workflow\ValueObjects\StepRunId;
use Maestro\Workflow\ValueObjects\WorkflowId;

final class JobRecord
{
    private function __construct(
        public readonly JobId $id,
        public readonly WorkflowId $workflowId,
        public readonly StepRunId $stepRunId,
        public readonly string $jobUuid,
        public readonly string $jobClass,
        public readonly string $queue,
        public readonly CarbonImmutable $dispatchedAt,
        public readonly CarbonImmutable $createdAt,
        private JobState $status,
        private int $attempt,
        private ?CarbonImmutable $startedAt,
        private ?CarbonImmutable $finishedAt,
        private ?int $runtimeMs,
        private ?string $failureClass,
        private ?string $failureMessage,
        private ?string $failureTrace,
        private ?string $workerId,
        private CarbonImmutable $updatedAt,
    ) {}

    /**
     * @param class-string $jobClass
     */
    public static function create(
        WorkflowId $workflowId,
        StepRunId $stepRunId,
        string $jobUuid,
        string $jobClass,
        string $queue,
        ?JobId $id = null,
    ): self {
        $now = CarbonImmutable::now();

        return new self(
            id: $id ?? JobId::generate(),
            workflowId: $workflowId,
            stepRunId: $stepRunId,
            jobUuid: $jobUuid,
            jobClass: $jobClass,
            queue: $queue,
            dispatchedAt: $now,
            createdAt: $now,
            status: JobState::Dispatched,
            attempt: 1,
            startedAt: null,
            finishedAt: null,
            runtimeMs: null,
            failureClass: null,
            failureMessage: null,
            failureTrace: null,
            workerId: null,
            updatedAt: $now,
        );
    }

    public static function reconstitute(
        JobId $id,
        WorkflowId $workflowId,
        StepRunId $stepRunId,
        string $jobUuid,
        string $jobClass,
        string $queue,
        JobState $status,
        int $attempt,
        CarbonImmutable $dispatchedAt,
        ?CarbonImmutable $startedAt,
        ?CarbonImmutable $finishedAt,
        ?int $runtimeMs,
        ?string $failureClass,
        ?string $failureMessage,
        ?string $failureTrace,
        ?string $workerId,
        CarbonImmutable $createdAt,
        CarbonImmutable $updatedAt,
    ): self {
        return new self(
            id: $id,
            workflowId: $workflowId,
            stepRunId: $stepRunId,
            jobUuid: $jobUuid,
            jobClass: $jobClass,
            queue: $queue,
            dispatchedAt: $dispatchedAt,
            createdAt: $createdAt,
            status: $status,
            attempt: $attempt,
            startedAt: $startedAt,
            finishedAt: $finishedAt,
            runtimeMs: $runtimeMs,
            failureClass: $failureClass,
            failureMessage: $failureMessage,
            failureTrace: $failureTrace,
            workerId: $workerId,
            updatedAt: $updatedAt,
        );
    }

    public function status(): JobState
    {
        return $this->status;
    }

    public function attempt(): int
    {
        return $this->attempt;
    }

    public function startedAt(): ?CarbonImmutable
    {
        return $this->startedAt;
    }

    public function finishedAt(): ?CarbonImmutable
    {
        return $this->finishedAt;
    }

    public function runtimeMs(): ?int
    {
        return $this->runtimeMs;
    }

    public function failureClass(): ?string
    {
        return $this->failureClass;
    }

    public function failureMessage(): ?string
    {
        return $this->failureMessage;
    }

    public function failureTrace(): ?string
    {
        return $this->failureTrace;
    }

    public function workerId(): ?string
    {
        return $this->workerId;
    }

    public function updatedAt(): CarbonImmutable
    {
        return $this->updatedAt;
    }

    public function isDispatched(): bool
    {
        return $this->status === JobState::Dispatched;
    }

    public function isRunning(): bool
    {
        return $this->status === JobState::Running;
    }

    public function isSucceeded(): bool
    {
        return $this->status === JobState::Succeeded;
    }

    public function isFailed(): bool
    {
        return $this->status === JobState::Failed;
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    /**
     * @throws InvalidStateTransitionException
     */
    public function start(?string $workerId = null): void
    {
        $this->transitionTo(JobState::Running);
        $this->startedAt = CarbonImmutable::now();
        $this->workerId = $workerId;
        $this->touch();
    }

    /**
     * @throws InvalidStateTransitionException
     */
    public function succeed(): void
    {
        $this->transitionTo(JobState::Succeeded);
        $this->finishedAt = CarbonImmutable::now();
        $this->calculateRuntime();
        $this->touch();
    }

    /**
     * @throws InvalidStateTransitionException
     */
    public function fail(
        ?string $failureClass = null,
        ?string $failureMessage = null,
        ?string $failureTrace = null,
    ): void {
        $this->transitionTo(JobState::Failed);
        $this->finishedAt = CarbonImmutable::now();
        $this->failureClass = $failureClass;
        $this->failureMessage = $failureMessage;
        $this->failureTrace = $failureTrace;
        $this->calculateRuntime();
        $this->touch();
    }

    public function incrementAttempt(): void
    {
        $this->attempt++;
        $this->touch();
    }

    public function duration(): ?int
    {
        return $this->runtimeMs;
    }

    public function queueWaitTime(): ?int
    {
        if ($this->startedAt === null) {
            return null;
        }

        return (int) $this->dispatchedAt->diffInMilliseconds($this->startedAt);
    }

    /**
     * @throws InvalidStateTransitionException
     */
    private function transitionTo(JobState $target): void
    {
        if (! $this->status->canTransitionTo($target)) {
            throw InvalidStateTransitionException::forJob($this->status, $target);
        }

        $this->status = $target;
    }

    private function calculateRuntime(): void
    {
        if ($this->startedAt !== null && $this->finishedAt !== null) {
            $this->runtimeMs = (int) $this->startedAt->diffInMilliseconds($this->finishedAt);
        }
    }

    private function touch(): void
    {
        $this->updatedAt = CarbonImmutable::now();
    }
}
