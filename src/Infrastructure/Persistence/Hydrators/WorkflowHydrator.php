<?php

declare(strict_types=1);

namespace Maestro\Workflow\Infrastructure\Persistence\Hydrators;

use Carbon\CarbonImmutable;
use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\Enums\WorkflowState;
use Maestro\Workflow\Exceptions\InvalidDefinitionKeyException;
use Maestro\Workflow\Exceptions\InvalidDefinitionVersionException;
use Maestro\Workflow\Exceptions\InvalidStepKeyException;
use Maestro\Workflow\Infrastructure\Persistence\Models\WorkflowModel;
use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\DefinitionVersion;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\WorkflowId;
use Ramsey\Uuid\Uuid;

final readonly class WorkflowHydrator
{
    /**
     * @param array{
     *     id?: WorkflowId,
     *     definition_key: DefinitionKey,
     *     definition_version: DefinitionVersion,
     *     state?: WorkflowState,
     *     current_step_key?: StepKey|null,
     * } $data
     */
    public function createModel(array $data): WorkflowModel
    {
        $model = new WorkflowModel();
        $model->id = isset($data['id']) ? $data['id']->value : Uuid::uuid7()->toString();
        $model->definition_key = $data['definition_key']->value;
        $model->definition_version = $data['definition_version']->toString();
        $model->state = isset($data['state']) ? $data['state']->value : WorkflowState::Pending->value;
        $model->current_step_key = isset($data['current_step_key']) ? $data['current_step_key']->value : null;

        return $model;
    }

    public function extractWorkflowId(WorkflowModel $workflowModel): WorkflowId
    {
        return WorkflowId::fromString($workflowModel->id);
    }

    /**
     * @throws InvalidDefinitionKeyException
     */
    public function extractDefinitionKey(WorkflowModel $workflowModel): DefinitionKey
    {
        return DefinitionKey::fromString($workflowModel->definition_key);
    }

    /**
     * @throws InvalidDefinitionVersionException
     */
    public function extractDefinitionVersion(WorkflowModel $workflowModel): DefinitionVersion
    {
        return DefinitionVersion::fromString($workflowModel->definition_version);
    }

    public function extractState(WorkflowModel $workflowModel): WorkflowState
    {
        return WorkflowState::from($workflowModel->state);
    }

    /**
     * @throws InvalidStepKeyException
     */
    public function extractCurrentStepKey(WorkflowModel $workflowModel): ?StepKey
    {
        if ($workflowModel->current_step_key === null) {
            return null;
        }

        return StepKey::fromString($workflowModel->current_step_key);
    }

    public function extractPausedAt(WorkflowModel $workflowModel): ?CarbonImmutable
    {
        return $workflowModel->paused_at;
    }

    public function extractFailedAt(WorkflowModel $workflowModel): ?CarbonImmutable
    {
        return $workflowModel->failed_at;
    }

    public function extractSucceededAt(WorkflowModel $workflowModel): ?CarbonImmutable
    {
        return $workflowModel->succeeded_at;
    }

    public function extractCancelledAt(WorkflowModel $workflowModel): ?CarbonImmutable
    {
        return $workflowModel->cancelled_at;
    }

    public function extractCreatedAt(WorkflowModel $workflowModel): CarbonImmutable
    {
        return $workflowModel->created_at;
    }

    public function extractUpdatedAt(WorkflowModel $workflowModel): CarbonImmutable
    {
        return $workflowModel->updated_at;
    }

    /**
     * @return array{
     *     id: WorkflowId,
     *     definition_key: DefinitionKey,
     *     definition_version: DefinitionVersion,
     *     state: WorkflowState,
     *     current_step_key: StepKey|null,
     *     paused_at: CarbonImmutable|null,
     *     paused_reason: string|null,
     *     failed_at: CarbonImmutable|null,
     *     failure_code: string|null,
     *     failure_message: string|null,
     *     succeeded_at: CarbonImmutable|null,
     *     cancelled_at: CarbonImmutable|null,
     *     locked_by: string|null,
     *     locked_at: CarbonImmutable|null,
     *     created_at: CarbonImmutable,
     *     updated_at: CarbonImmutable,
     * }
     *
     * @throws InvalidDefinitionKeyException
     * @throws InvalidDefinitionVersionException
     * @throws InvalidStepKeyException
     */
    public function extractAll(WorkflowModel $workflowModel): array
    {
        return [
            'id' => $this->extractWorkflowId($workflowModel),
            'definition_key' => $this->extractDefinitionKey($workflowModel),
            'definition_version' => $this->extractDefinitionVersion($workflowModel),
            'state' => $this->extractState($workflowModel),
            'current_step_key' => $this->extractCurrentStepKey($workflowModel),
            'paused_at' => $workflowModel->paused_at,
            'paused_reason' => $workflowModel->paused_reason,
            'failed_at' => $workflowModel->failed_at,
            'failure_code' => $workflowModel->failure_code,
            'failure_message' => $workflowModel->failure_message,
            'succeeded_at' => $workflowModel->succeeded_at,
            'cancelled_at' => $workflowModel->cancelled_at,
            'locked_by' => $workflowModel->locked_by,
            'locked_at' => $workflowModel->locked_at,
            'created_at' => $workflowModel->created_at,
            'updated_at' => $workflowModel->updated_at,
        ];
    }

    public function updateState(WorkflowModel $workflowModel, WorkflowState $workflowState): void
    {
        $workflowModel->state = $workflowState->value;
    }

    public function updateCurrentStepKey(WorkflowModel $workflowModel, ?StepKey $stepKey): void
    {
        $workflowModel->current_step_key = $stepKey?->value;
    }

    public function markPaused(WorkflowModel $workflowModel, ?string $reason = null): void
    {
        $workflowModel->state = WorkflowState::Paused->value;
        $workflowModel->paused_at = CarbonImmutable::now();
        $workflowModel->paused_reason = $reason;
    }

    public function markFailed(WorkflowModel $workflowModel, ?string $code = null, ?string $message = null): void
    {
        $workflowModel->state = WorkflowState::Failed->value;
        $workflowModel->failed_at = CarbonImmutable::now();
        $workflowModel->failure_code = $code;
        $workflowModel->failure_message = $message;
    }

    public function markSucceeded(WorkflowModel $workflowModel): void
    {
        $workflowModel->state = WorkflowState::Succeeded->value;
        $workflowModel->succeeded_at = CarbonImmutable::now();
    }

    public function markCancelled(WorkflowModel $workflowModel): void
    {
        $workflowModel->state = WorkflowState::Cancelled->value;
        $workflowModel->cancelled_at = CarbonImmutable::now();
    }

    /**
     * @throws InvalidDefinitionKeyException
     * @throws InvalidDefinitionVersionException
     * @throws InvalidStepKeyException
     */
    public function toDomain(WorkflowModel $workflowModel): WorkflowInstance
    {
        return WorkflowInstance::reconstitute(
            workflowId: $this->extractWorkflowId($workflowModel),
            definitionKey: $this->extractDefinitionKey($workflowModel),
            definitionVersion: $this->extractDefinitionVersion($workflowModel),
            workflowState: $this->extractState($workflowModel),
            currentStepKey: $this->extractCurrentStepKey($workflowModel),
            pausedAt: $workflowModel->paused_at,
            pausedReason: $workflowModel->paused_reason,
            failedAt: $workflowModel->failed_at,
            failureCode: $workflowModel->failure_code,
            failureMessage: $workflowModel->failure_message,
            succeededAt: $workflowModel->succeeded_at,
            cancelledAt: $workflowModel->cancelled_at,
            lockedBy: $workflowModel->locked_by,
            lockedAt: $workflowModel->locked_at,
            createdAt: $workflowModel->created_at,
            updatedAt: $workflowModel->updated_at,
            autoRetryCount: $workflowModel->auto_retry_count ?? 0,
            nextAutoRetryAt: $workflowModel->next_auto_retry_at,
            compensationStartedAt: $workflowModel->compensation_started_at,
            compensatedAt: $workflowModel->compensated_at,
            awaitingTriggerKey: $workflowModel->awaiting_trigger_key,
            triggerTimeoutAt: $workflowModel->trigger_timeout_at,
            triggerRegisteredAt: $workflowModel->trigger_registered_at,
            scheduledResumeAt: $workflowModel->scheduled_resume_at,
        );
    }

    public function fromDomain(WorkflowInstance $workflowInstance): WorkflowModel
    {
        $model = new WorkflowModel();
        $model->id = $workflowInstance->id->value;
        $model->definition_key = $workflowInstance->definitionKey->value;
        $model->definition_version = $workflowInstance->definitionVersion->toString();
        $model->state = $workflowInstance->state()->value;
        $model->current_step_key = $workflowInstance->currentStepKey()?->value;
        $model->paused_at = $workflowInstance->pausedAt();
        $model->paused_reason = $workflowInstance->pausedReason();
        $model->failed_at = $workflowInstance->failedAt();
        $model->failure_code = $workflowInstance->failureCode();
        $model->failure_message = $workflowInstance->failureMessage();
        $model->succeeded_at = $workflowInstance->succeededAt();
        $model->cancelled_at = $workflowInstance->cancelledAt();
        $model->locked_by = $workflowInstance->lockedBy();
        $model->locked_at = $workflowInstance->lockedAt();
        $model->created_at = $workflowInstance->createdAt;
        $model->updated_at = $workflowInstance->updatedAt();
        $model->auto_retry_count = $workflowInstance->autoRetryCount();
        $model->next_auto_retry_at = $workflowInstance->nextAutoRetryAt();
        $model->compensation_started_at = $workflowInstance->compensationStartedAt();
        $model->compensated_at = $workflowInstance->compensatedAt();
        $model->awaiting_trigger_key = $workflowInstance->awaitingTriggerKey();
        $model->trigger_timeout_at = $workflowInstance->triggerTimeoutAt();
        $model->trigger_registered_at = $workflowInstance->triggerRegisteredAt();
        $model->scheduled_resume_at = $workflowInstance->scheduledResumeAt();

        return $model;
    }

    public function updateFromDomain(WorkflowModel $workflowModel, WorkflowInstance $workflowInstance): void
    {
        $workflowModel->state = $workflowInstance->state()->value;
        $workflowModel->current_step_key = $workflowInstance->currentStepKey()?->value;
        $workflowModel->paused_at = $workflowInstance->pausedAt();
        $workflowModel->paused_reason = $workflowInstance->pausedReason();
        $workflowModel->failed_at = $workflowInstance->failedAt();
        $workflowModel->failure_code = $workflowInstance->failureCode();
        $workflowModel->failure_message = $workflowInstance->failureMessage();
        $workflowModel->succeeded_at = $workflowInstance->succeededAt();
        $workflowModel->cancelled_at = $workflowInstance->cancelledAt();
        $workflowModel->locked_by = $workflowInstance->lockedBy();
        $workflowModel->locked_at = $workflowInstance->lockedAt();
        $workflowModel->updated_at = $workflowInstance->updatedAt();
        $workflowModel->auto_retry_count = $workflowInstance->autoRetryCount();
        $workflowModel->next_auto_retry_at = $workflowInstance->nextAutoRetryAt();
        $workflowModel->compensation_started_at = $workflowInstance->compensationStartedAt();
        $workflowModel->compensated_at = $workflowInstance->compensatedAt();
        $workflowModel->awaiting_trigger_key = $workflowInstance->awaitingTriggerKey();
        $workflowModel->trigger_timeout_at = $workflowInstance->triggerTimeoutAt();
        $workflowModel->trigger_registered_at = $workflowInstance->triggerRegisteredAt();
        $workflowModel->scheduled_resume_at = $workflowInstance->scheduledResumeAt();
    }
}
