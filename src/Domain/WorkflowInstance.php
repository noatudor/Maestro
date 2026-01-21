<?php

declare(strict_types=1);

namespace Maestro\Workflow\Domain;

use Carbon\CarbonImmutable;
use Maestro\Workflow\Enums\WorkflowState;
use Maestro\Workflow\Exceptions\InvalidStateTransitionException;
use Maestro\Workflow\Exceptions\WorkflowAlreadyCancelledException;
use Maestro\Workflow\Exceptions\WorkflowLockedException;
use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\DefinitionVersion;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\WorkflowId;

final class WorkflowInstance
{
    private function __construct(
        public readonly WorkflowId $id,
        public readonly DefinitionKey $definitionKey,
        public readonly DefinitionVersion $definitionVersion,
        public readonly CarbonImmutable $createdAt,
        private WorkflowState $workflowState,
        private ?StepKey $currentStepKey,
        private ?CarbonImmutable $pausedAt,
        private ?string $pausedReason,
        private ?CarbonImmutable $failedAt,
        private ?string $failureCode,
        private ?string $failureMessage,
        private ?CarbonImmutable $succeededAt,
        private ?CarbonImmutable $cancelledAt,
        private ?string $lockedBy,
        private ?CarbonImmutable $lockedAt,
        private CarbonImmutable $updatedAt,
    ) {}

    public static function create(
        DefinitionKey $definitionKey,
        DefinitionVersion $definitionVersion,
        ?WorkflowId $workflowId = null,
    ): self {
        $now = CarbonImmutable::now();

        return new self(
            id: $workflowId ?? WorkflowId::generate(),
            definitionKey: $definitionKey,
            definitionVersion: $definitionVersion,
            createdAt: $now,
            workflowState: WorkflowState::Pending,
            currentStepKey: null,
            pausedAt: null,
            pausedReason: null,
            failedAt: null,
            failureCode: null,
            failureMessage: null,
            succeededAt: null,
            cancelledAt: null,
            lockedBy: null,
            lockedAt: null,
            updatedAt: $now,
        );
    }

    public static function reconstitute(
        WorkflowId $workflowId,
        DefinitionKey $definitionKey,
        DefinitionVersion $definitionVersion,
        WorkflowState $workflowState,
        ?StepKey $currentStepKey,
        ?CarbonImmutable $pausedAt,
        ?string $pausedReason,
        ?CarbonImmutable $failedAt,
        ?string $failureCode,
        ?string $failureMessage,
        ?CarbonImmutable $succeededAt,
        ?CarbonImmutable $cancelledAt,
        ?string $lockedBy,
        ?CarbonImmutable $lockedAt,
        CarbonImmutable $createdAt,
        CarbonImmutable $updatedAt,
    ): self {
        return new self(
            id: $workflowId,
            definitionKey: $definitionKey,
            definitionVersion: $definitionVersion,
            createdAt: $createdAt,
            workflowState: $workflowState,
            currentStepKey: $currentStepKey,
            pausedAt: $pausedAt,
            pausedReason: $pausedReason,
            failedAt: $failedAt,
            failureCode: $failureCode,
            failureMessage: $failureMessage,
            succeededAt: $succeededAt,
            cancelledAt: $cancelledAt,
            lockedBy: $lockedBy,
            lockedAt: $lockedAt,
            updatedAt: $updatedAt,
        );
    }

    public function state(): WorkflowState
    {
        return $this->workflowState;
    }

    public function currentStepKey(): ?StepKey
    {
        return $this->currentStepKey;
    }

    public function pausedAt(): ?CarbonImmutable
    {
        return $this->pausedAt;
    }

    public function pausedReason(): ?string
    {
        return $this->pausedReason;
    }

    public function failedAt(): ?CarbonImmutable
    {
        return $this->failedAt;
    }

    public function failureCode(): ?string
    {
        return $this->failureCode;
    }

    public function failureMessage(): ?string
    {
        return $this->failureMessage;
    }

    public function succeededAt(): ?CarbonImmutable
    {
        return $this->succeededAt;
    }

    public function cancelledAt(): ?CarbonImmutable
    {
        return $this->cancelledAt;
    }

    public function lockedBy(): ?string
    {
        return $this->lockedBy;
    }

    public function lockedAt(): ?CarbonImmutable
    {
        return $this->lockedAt;
    }

    public function updatedAt(): CarbonImmutable
    {
        return $this->updatedAt;
    }

    public function isPending(): bool
    {
        return $this->workflowState === WorkflowState::Pending;
    }

    public function isRunning(): bool
    {
        return $this->workflowState === WorkflowState::Running;
    }

    public function isPaused(): bool
    {
        return $this->workflowState === WorkflowState::Paused;
    }

    public function isSucceeded(): bool
    {
        return $this->workflowState === WorkflowState::Succeeded;
    }

    public function isFailed(): bool
    {
        return $this->workflowState === WorkflowState::Failed;
    }

    public function isCancelled(): bool
    {
        return $this->workflowState === WorkflowState::Cancelled;
    }

    public function isTerminal(): bool
    {
        return $this->workflowState->isTerminal();
    }

    public function isActive(): bool
    {
        return $this->workflowState->isActive();
    }

    public function isLocked(): bool
    {
        return $this->lockedBy !== null;
    }

    /**
     * @throws InvalidStateTransitionException
     */
    public function start(StepKey $firstStepKey): void
    {
        $this->transitionTo(WorkflowState::Running);
        $this->currentStepKey = $firstStepKey;
        $this->touch();
    }

    /**
     * @throws InvalidStateTransitionException
     */
    public function pause(?string $reason = null): void
    {
        $this->transitionTo(WorkflowState::Paused);
        $this->pausedAt = CarbonImmutable::now();
        $this->pausedReason = $reason;
        $this->touch();
    }

    /**
     * @throws InvalidStateTransitionException
     */
    public function resume(): void
    {
        $this->transitionTo(WorkflowState::Running);
        $this->pausedAt = null;
        $this->pausedReason = null;
        $this->touch();
    }

    /**
     * @throws InvalidStateTransitionException
     */
    public function succeed(): void
    {
        $this->transitionTo(WorkflowState::Succeeded);
        $this->succeededAt = CarbonImmutable::now();
        $this->currentStepKey = null;
        $this->touch();
    }

    /**
     * Succeed an empty workflow immediately from Pending state.
     *
     * Transitions through Running to Succeeded in one operation.
     * Used for workflows with no steps.
     *
     * @throws InvalidStateTransitionException
     */
    public function succeedImmediately(): void
    {
        $this->transitionTo(WorkflowState::Running);
        $this->transitionTo(WorkflowState::Succeeded);
        $this->succeededAt = CarbonImmutable::now();
        $this->currentStepKey = null;
        $this->touch();
    }

    /**
     * @throws InvalidStateTransitionException
     */
    public function fail(?string $code = null, ?string $message = null): void
    {
        $this->transitionTo(WorkflowState::Failed);
        $this->failedAt = CarbonImmutable::now();
        $this->failureCode = $code;
        $this->failureMessage = $message;
        $this->touch();
    }

    /**
     * @throws InvalidStateTransitionException
     * @throws WorkflowAlreadyCancelledException
     */
    public function cancel(): void
    {
        if ($this->isCancelled()) {
            throw WorkflowAlreadyCancelledException::withId($this->id);
        }

        $this->transitionTo(WorkflowState::Cancelled);
        $this->cancelledAt = CarbonImmutable::now();
        $this->currentStepKey = null;
        $this->touch();
    }

    /**
     * @throws InvalidStateTransitionException
     */
    public function retry(): void
    {
        if (! $this->isFailed()) {
            throw InvalidStateTransitionException::forWorkflow($this->workflowState, WorkflowState::Running);
        }

        $this->workflowState = WorkflowState::Running;
        $this->failedAt = null;
        $this->failureCode = null;
        $this->failureMessage = null;
        $this->touch();
    }

    public function advanceToStep(StepKey $stepKey): void
    {
        $this->currentStepKey = $stepKey;
        $this->touch();
    }

    /**
     * @throws WorkflowLockedException
     */
    public function acquireLock(string $lockId): void
    {
        if ($this->isLocked() && $this->lockedBy !== $lockId) {
            throw WorkflowLockedException::withId($this->id, $this->lockedBy ?? 'unknown');
        }

        $this->lockedBy = $lockId;
        $this->lockedAt = CarbonImmutable::now();
        $this->touch();
    }

    public function releaseLock(string $lockId): bool
    {
        if ($this->lockedBy !== $lockId) {
            return false;
        }

        $this->lockedBy = null;
        $this->lockedAt = null;
        $this->touch();

        return true;
    }

    public function forceReleaseLock(): void
    {
        $this->lockedBy = null;
        $this->lockedAt = null;
        $this->touch();
    }

    /**
     * @throws InvalidStateTransitionException
     */
    private function transitionTo(WorkflowState $workflowState): void
    {
        if (! $this->workflowState->canTransitionTo($workflowState)) {
            throw InvalidStateTransitionException::forWorkflow($this->workflowState, $workflowState);
        }

        $this->workflowState = $workflowState;
    }

    private function touch(): void
    {
        $this->updatedAt = CarbonImmutable::now();
    }
}
