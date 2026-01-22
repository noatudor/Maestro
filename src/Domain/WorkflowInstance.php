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
        private int $autoRetryCount,
        private ?CarbonImmutable $nextAutoRetryAt,
        private ?CarbonImmutable $compensationStartedAt,
        private ?CarbonImmutable $compensatedAt,
        private ?string $awaitingTriggerKey,
        private ?CarbonImmutable $triggerTimeoutAt,
        private ?CarbonImmutable $triggerRegisteredAt,
        private ?CarbonImmutable $scheduledResumeAt,
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
            autoRetryCount: 0,
            nextAutoRetryAt: null,
            compensationStartedAt: null,
            compensatedAt: null,
            awaitingTriggerKey: null,
            triggerTimeoutAt: null,
            triggerRegisteredAt: null,
            scheduledResumeAt: null,
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
        int $autoRetryCount = 0,
        ?CarbonImmutable $nextAutoRetryAt = null,
        ?CarbonImmutable $compensationStartedAt = null,
        ?CarbonImmutable $compensatedAt = null,
        ?string $awaitingTriggerKey = null,
        ?CarbonImmutable $triggerTimeoutAt = null,
        ?CarbonImmutable $triggerRegisteredAt = null,
        ?CarbonImmutable $scheduledResumeAt = null,
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
            autoRetryCount: $autoRetryCount,
            nextAutoRetryAt: $nextAutoRetryAt,
            compensationStartedAt: $compensationStartedAt,
            compensatedAt: $compensatedAt,
            awaitingTriggerKey: $awaitingTriggerKey,
            triggerTimeoutAt: $triggerTimeoutAt,
            triggerRegisteredAt: $triggerRegisteredAt,
            scheduledResumeAt: $scheduledResumeAt,
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

    public function autoRetryCount(): int
    {
        return $this->autoRetryCount;
    }

    public function nextAutoRetryAt(): ?CarbonImmutable
    {
        return $this->nextAutoRetryAt;
    }

    public function hasScheduledAutoRetry(): bool
    {
        return $this->nextAutoRetryAt instanceof CarbonImmutable;
    }

    public function isAutoRetryDue(): bool
    {
        if (! $this->nextAutoRetryAt instanceof CarbonImmutable) {
            return false;
        }

        return CarbonImmutable::now()->gte($this->nextAutoRetryAt);
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

    public function isCompensating(): bool
    {
        return $this->workflowState === WorkflowState::Compensating;
    }

    public function isCompensated(): bool
    {
        return $this->workflowState === WorkflowState::Compensated;
    }

    public function isCompensationFailed(): bool
    {
        return $this->workflowState === WorkflowState::CompensationFailed;
    }

    public function compensationStartedAt(): ?CarbonImmutable
    {
        return $this->compensationStartedAt;
    }

    public function compensatedAt(): ?CarbonImmutable
    {
        return $this->compensatedAt;
    }

    public function awaitingTriggerKey(): ?string
    {
        return $this->awaitingTriggerKey;
    }

    public function triggerTimeoutAt(): ?CarbonImmutable
    {
        return $this->triggerTimeoutAt;
    }

    public function triggerRegisteredAt(): ?CarbonImmutable
    {
        return $this->triggerRegisteredAt;
    }

    public function scheduledResumeAt(): ?CarbonImmutable
    {
        return $this->scheduledResumeAt;
    }

    public function isAwaitingTrigger(): bool
    {
        return $this->isPaused() && $this->awaitingTriggerKey !== null;
    }

    public function isAwaitingTriggerKey(string $triggerKey): bool
    {
        return $this->isAwaitingTrigger() && $this->awaitingTriggerKey === $triggerKey;
    }

    public function isTriggerTimedOut(): bool
    {
        if (! $this->triggerTimeoutAt instanceof CarbonImmutable) {
            return false;
        }

        return CarbonImmutable::now()->gte($this->triggerTimeoutAt);
    }

    public function isScheduledResumeDue(): bool
    {
        if (! $this->scheduledResumeAt instanceof CarbonImmutable) {
            return false;
        }

        return CarbonImmutable::now()->gte($this->scheduledResumeAt);
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
        $this->clearTriggerState();
        $this->touch();
    }

    /**
     * Pause the workflow awaiting an external trigger.
     *
     * @throws InvalidStateTransitionException
     */
    public function pauseForTrigger(
        string $triggerKey,
        CarbonImmutable $timeoutAt,
        ?CarbonImmutable $scheduledResumeAt = null,
        ?string $reason = null,
    ): void {
        $this->transitionTo(WorkflowState::Paused);
        $this->pausedAt = CarbonImmutable::now();
        $this->pausedReason = $reason ?? 'Awaiting trigger: '.$triggerKey;
        $this->awaitingTriggerKey = $triggerKey;
        $this->triggerTimeoutAt = $timeoutAt;
        $this->triggerRegisteredAt = CarbonImmutable::now();
        $this->scheduledResumeAt = $scheduledResumeAt;
        $this->touch();
    }

    /**
     * Resume workflow from a trigger.
     *
     * @throws InvalidStateTransitionException
     */
    public function resumeFromTrigger(): void
    {
        $this->transitionTo(WorkflowState::Running);
        $this->pausedAt = null;
        $this->pausedReason = null;
        $this->clearTriggerState();
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

    /**
     * Start compensation execution.
     *
     * @throws InvalidStateTransitionException
     */
    public function startCompensation(): void
    {
        $this->transitionTo(WorkflowState::Compensating);
        $this->compensationStartedAt = CarbonImmutable::now();
        $this->currentStepKey = null;
        $this->touch();
    }

    /**
     * Mark compensation as complete.
     *
     * @throws InvalidStateTransitionException
     */
    public function completeCompensation(): void
    {
        $this->transitionTo(WorkflowState::Compensated);
        $this->compensatedAt = CarbonImmutable::now();
        $this->touch();
    }

    /**
     * Mark compensation as failed.
     *
     * @throws InvalidStateTransitionException
     */
    public function failCompensation(): void
    {
        $this->transitionTo(WorkflowState::CompensationFailed);
        $this->touch();
    }

    /**
     * Retry compensation from failed state.
     *
     * @throws InvalidStateTransitionException
     */
    public function retryCompensation(): void
    {
        if (! $this->isCompensationFailed()) {
            throw InvalidStateTransitionException::forWorkflow($this->workflowState, WorkflowState::Compensating);
        }

        $this->transitionTo(WorkflowState::Compensating);
        $this->touch();
    }

    /**
     * Skip remaining compensation and mark as compensated.
     *
     * Used when compensation has failed but we want to consider it complete.
     *
     * @throws InvalidStateTransitionException
     */
    public function skipRemainingCompensation(): void
    {
        if (! $this->isCompensationFailed()) {
            throw InvalidStateTransitionException::forWorkflow($this->workflowState, WorkflowState::Compensated);
        }

        $this->transitionTo(WorkflowState::Compensated);
        $this->compensatedAt = CarbonImmutable::now();
        $this->touch();
    }

    public function advanceToStep(StepKey $stepKey): void
    {
        $this->currentStepKey = $stepKey;
        $this->touch();
    }

    /**
     * Schedule an automatic retry at the specified time.
     *
     * This increments the auto-retry count and sets the scheduled time.
     */
    public function scheduleAutoRetry(CarbonImmutable $scheduledFor): void
    {
        $this->autoRetryCount++;
        $this->nextAutoRetryAt = $scheduledFor;
        $this->touch();
    }

    /**
     * Clear the scheduled auto-retry.
     *
     * Called when auto-retry is executed or cancelled.
     */
    public function clearAutoRetry(): void
    {
        $this->nextAutoRetryAt = null;
        $this->touch();
    }

    /**
     * Reset auto-retry count.
     *
     * Called when the workflow succeeds or when manual intervention occurs.
     */
    public function resetAutoRetryCount(): void
    {
        $this->autoRetryCount = 0;
        $this->nextAutoRetryAt = null;
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
     * Clear trigger state without changing workflow state.
     */
    private function clearTriggerState(): void
    {
        $this->awaitingTriggerKey = null;
        $this->triggerTimeoutAt = null;
        $this->triggerRegisteredAt = null;
        $this->scheduledResumeAt = null;
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
