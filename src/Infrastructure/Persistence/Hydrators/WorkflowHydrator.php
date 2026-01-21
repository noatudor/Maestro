<?php

declare(strict_types=1);

namespace Maestro\Workflow\Infrastructure\Persistence\Hydrators;

use Carbon\CarbonImmutable;
use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\Enums\WorkflowState;
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

    public function extractWorkflowId(WorkflowModel $model): WorkflowId
    {
        return WorkflowId::fromString($model->id);
    }

    /**
     * @throws \Maestro\Workflow\Exceptions\InvalidDefinitionKeyException
     */
    public function extractDefinitionKey(WorkflowModel $model): DefinitionKey
    {
        return DefinitionKey::fromString($model->definition_key);
    }

    /**
     * @throws \Maestro\Workflow\Exceptions\InvalidDefinitionVersionException
     */
    public function extractDefinitionVersion(WorkflowModel $model): DefinitionVersion
    {
        return DefinitionVersion::fromString($model->definition_version);
    }

    public function extractState(WorkflowModel $model): WorkflowState
    {
        return WorkflowState::from($model->state);
    }

    /**
     * @throws \Maestro\Workflow\Exceptions\InvalidStepKeyException
     */
    public function extractCurrentStepKey(WorkflowModel $model): ?StepKey
    {
        if ($model->current_step_key === null) {
            return null;
        }

        return StepKey::fromString($model->current_step_key);
    }

    public function extractPausedAt(WorkflowModel $model): ?CarbonImmutable
    {
        return $model->paused_at;
    }

    public function extractFailedAt(WorkflowModel $model): ?CarbonImmutable
    {
        return $model->failed_at;
    }

    public function extractSucceededAt(WorkflowModel $model): ?CarbonImmutable
    {
        return $model->succeeded_at;
    }

    public function extractCancelledAt(WorkflowModel $model): ?CarbonImmutable
    {
        return $model->cancelled_at;
    }

    public function extractCreatedAt(WorkflowModel $model): CarbonImmutable
    {
        return $model->created_at;
    }

    public function extractUpdatedAt(WorkflowModel $model): CarbonImmutable
    {
        return $model->updated_at;
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
     * @throws \Maestro\Workflow\Exceptions\InvalidDefinitionKeyException
     * @throws \Maestro\Workflow\Exceptions\InvalidDefinitionVersionException
     * @throws \Maestro\Workflow\Exceptions\InvalidStepKeyException
     */
    public function extractAll(WorkflowModel $model): array
    {
        return [
            'id' => $this->extractWorkflowId($model),
            'definition_key' => $this->extractDefinitionKey($model),
            'definition_version' => $this->extractDefinitionVersion($model),
            'state' => $this->extractState($model),
            'current_step_key' => $this->extractCurrentStepKey($model),
            'paused_at' => $model->paused_at,
            'paused_reason' => $model->paused_reason,
            'failed_at' => $model->failed_at,
            'failure_code' => $model->failure_code,
            'failure_message' => $model->failure_message,
            'succeeded_at' => $model->succeeded_at,
            'cancelled_at' => $model->cancelled_at,
            'locked_by' => $model->locked_by,
            'locked_at' => $model->locked_at,
            'created_at' => $model->created_at,
            'updated_at' => $model->updated_at,
        ];
    }

    public function updateState(WorkflowModel $model, WorkflowState $state): void
    {
        $model->state = $state->value;
    }

    public function updateCurrentStepKey(WorkflowModel $model, ?StepKey $stepKey): void
    {
        $model->current_step_key = $stepKey?->value;
    }

    public function markPaused(WorkflowModel $model, ?string $reason = null): void
    {
        $model->state = WorkflowState::Paused->value;
        $model->paused_at = CarbonImmutable::now();
        $model->paused_reason = $reason;
    }

    public function markFailed(WorkflowModel $model, ?string $code = null, ?string $message = null): void
    {
        $model->state = WorkflowState::Failed->value;
        $model->failed_at = CarbonImmutable::now();
        $model->failure_code = $code;
        $model->failure_message = $message;
    }

    public function markSucceeded(WorkflowModel $model): void
    {
        $model->state = WorkflowState::Succeeded->value;
        $model->succeeded_at = CarbonImmutable::now();
    }

    public function markCancelled(WorkflowModel $model): void
    {
        $model->state = WorkflowState::Cancelled->value;
        $model->cancelled_at = CarbonImmutable::now();
    }

    /**
     * @throws \Maestro\Workflow\Exceptions\InvalidDefinitionKeyException
     * @throws \Maestro\Workflow\Exceptions\InvalidDefinitionVersionException
     * @throws \Maestro\Workflow\Exceptions\InvalidStepKeyException
     */
    public function toDomain(WorkflowModel $model): WorkflowInstance
    {
        return WorkflowInstance::reconstitute(
            id: $this->extractWorkflowId($model),
            definitionKey: $this->extractDefinitionKey($model),
            definitionVersion: $this->extractDefinitionVersion($model),
            state: $this->extractState($model),
            currentStepKey: $this->extractCurrentStepKey($model),
            pausedAt: $model->paused_at,
            pausedReason: $model->paused_reason,
            failedAt: $model->failed_at,
            failureCode: $model->failure_code,
            failureMessage: $model->failure_message,
            succeededAt: $model->succeeded_at,
            cancelledAt: $model->cancelled_at,
            lockedBy: $model->locked_by,
            lockedAt: $model->locked_at,
            createdAt: $model->created_at,
            updatedAt: $model->updated_at,
        );
    }

    public function fromDomain(WorkflowInstance $workflow): WorkflowModel
    {
        $model = new WorkflowModel();
        $model->id = $workflow->id->value;
        $model->definition_key = $workflow->definitionKey->value;
        $model->definition_version = $workflow->definitionVersion->toString();
        $model->state = $workflow->state()->value;
        $model->current_step_key = $workflow->currentStepKey()?->value;
        $model->paused_at = $workflow->pausedAt();
        $model->paused_reason = $workflow->pausedReason();
        $model->failed_at = $workflow->failedAt();
        $model->failure_code = $workflow->failureCode();
        $model->failure_message = $workflow->failureMessage();
        $model->succeeded_at = $workflow->succeededAt();
        $model->cancelled_at = $workflow->cancelledAt();
        $model->locked_by = $workflow->lockedBy();
        $model->locked_at = $workflow->lockedAt();
        $model->created_at = $workflow->createdAt;
        $model->updated_at = $workflow->updatedAt();

        return $model;
    }

    public function updateFromDomain(WorkflowModel $model, WorkflowInstance $workflow): void
    {
        $model->state = $workflow->state()->value;
        $model->current_step_key = $workflow->currentStepKey()?->value;
        $model->paused_at = $workflow->pausedAt();
        $model->paused_reason = $workflow->pausedReason();
        $model->failed_at = $workflow->failedAt();
        $model->failure_code = $workflow->failureCode();
        $model->failure_message = $workflow->failureMessage();
        $model->succeeded_at = $workflow->succeededAt();
        $model->cancelled_at = $workflow->cancelledAt();
        $model->locked_by = $workflow->lockedBy();
        $model->locked_at = $workflow->lockedAt();
        $model->updated_at = $workflow->updatedAt();
    }
}
