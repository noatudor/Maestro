<?php

declare(strict_types=1);

namespace Maestro\Workflow\Infrastructure\Persistence\Hydrators;

use Maestro\Workflow\Domain\ResolutionDecisionRecord;
use Maestro\Workflow\Enums\ResolutionDecision;
use Maestro\Workflow\Exceptions\InvalidStepKeyException;
use Maestro\Workflow\Infrastructure\Persistence\Models\ResolutionDecisionModel;
use Maestro\Workflow\ValueObjects\ResolutionDecisionId;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\WorkflowId;

final readonly class ResolutionDecisionHydrator
{
    /**
     * @throws InvalidStepKeyException
     */
    public function toDomain(ResolutionDecisionModel $resolutionDecisionModel): ResolutionDecisionRecord
    {
        $retryFromStepKey = $resolutionDecisionModel->retry_from_step_key !== null
            ? StepKey::fromString($resolutionDecisionModel->retry_from_step_key)
            : null;

        $compensateStepKeys = null;
        if ($resolutionDecisionModel->compensate_step_keys !== null) {
            $compensateStepKeys = array_values(array_map(
                StepKey::fromString(...),
                $resolutionDecisionModel->compensate_step_keys,
            ));
        }

        return ResolutionDecisionRecord::reconstitute(
            workflowId: WorkflowId::fromString($resolutionDecisionModel->workflow_id),
            decidedBy: $resolutionDecisionModel->decided_by,
            reason: $resolutionDecisionModel->reason,
            retryFromStepKey: $retryFromStepKey,
            compensateStepKeys: $compensateStepKeys,
            createdAt: $resolutionDecisionModel->created_at,
            id: ResolutionDecisionId::fromString($resolutionDecisionModel->id),
            decisionType: ResolutionDecision::from($resolutionDecisionModel->decision_type),
        );
    }

    public function fromDomain(ResolutionDecisionRecord $resolutionDecisionRecord): ResolutionDecisionModel
    {
        $model = new ResolutionDecisionModel();
        $this->updateFromDomain($model, $resolutionDecisionRecord);

        return $model;
    }

    public function updateFromDomain(ResolutionDecisionModel $resolutionDecisionModel, ResolutionDecisionRecord $resolutionDecisionRecord): void
    {
        $compensateStepKeys = null;
        if ($resolutionDecisionRecord->compensateStepKeys !== null) {
            $compensateStepKeys = array_map(
                static fn (StepKey $stepKey): string => $stepKey->value,
                $resolutionDecisionRecord->compensateStepKeys,
            );
        }

        $resolutionDecisionModel->id = $resolutionDecisionRecord->id->value;
        $resolutionDecisionModel->workflow_id = $resolutionDecisionRecord->workflowId->value;
        $resolutionDecisionModel->decision_type = $resolutionDecisionRecord->decisionType->value;
        $resolutionDecisionModel->decided_by = $resolutionDecisionRecord->decidedBy;
        $resolutionDecisionModel->reason = $resolutionDecisionRecord->reason;
        $resolutionDecisionModel->retry_from_step_key = $resolutionDecisionRecord->retryFromStepKey?->value;
        $resolutionDecisionModel->compensate_step_keys = $compensateStepKeys;
    }
}
