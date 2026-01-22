<?php

declare(strict_types=1);

namespace Maestro\Workflow\Infrastructure\Persistence\Hydrators;

use Carbon\CarbonImmutable;
use Maestro\Workflow\Domain\TriggerPayloadRecord;
use Maestro\Workflow\Infrastructure\Persistence\Models\TriggerPayloadModel;
use Maestro\Workflow\ValueObjects\TriggerPayload;
use Maestro\Workflow\ValueObjects\TriggerPayloadId;
use Maestro\Workflow\ValueObjects\WorkflowId;

final readonly class TriggerPayloadHydrator
{
    public function toDomain(TriggerPayloadModel $triggerPayloadModel): TriggerPayloadRecord
    {
        return TriggerPayloadRecord::reconstitute(
            workflowId: WorkflowId::fromString($triggerPayloadModel->workflow_id),
            triggerKey: $triggerPayloadModel->trigger_key,
            receivedAt: $triggerPayloadModel->received_at,
            sourceIp: $triggerPayloadModel->source_ip,
            sourceIdentifier: $triggerPayloadModel->source_identifier,
            createdAt: $triggerPayloadModel->created_at,
            id: TriggerPayloadId::fromString($triggerPayloadModel->id),
            payload: $this->deserializePayload($triggerPayloadModel->payload),
        );
    }

    public function fromDomain(TriggerPayloadRecord $triggerPayloadRecord): TriggerPayloadModel
    {
        $model = new TriggerPayloadModel();
        $model->id = $triggerPayloadRecord->id->value;
        $model->workflow_id = $triggerPayloadRecord->workflowId->value;
        $model->trigger_key = $triggerPayloadRecord->triggerKey;
        $model->payload = $this->serializePayload($triggerPayloadRecord->payload);
        $model->received_at = $triggerPayloadRecord->receivedAt;
        $model->source_ip = $triggerPayloadRecord->sourceIp;
        $model->source_identifier = $triggerPayloadRecord->sourceIdentifier;
        $model->created_at = $triggerPayloadRecord->createdAt;
        $model->updated_at = CarbonImmutable::now();

        return $model;
    }

    private function serializePayload(TriggerPayload $triggerPayload): string
    {
        return json_encode($triggerPayload->toArray(), JSON_THROW_ON_ERROR);
    }

    private function deserializePayload(string $payload): TriggerPayload
    {
        /** @var array<string, mixed> $data */
        $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

        return TriggerPayload::fromArray($data);
    }
}
