<?php

declare(strict_types=1);

namespace Maestro\Workflow\Infrastructure\Persistence\Repositories;

use Maestro\Workflow\Contracts\TriggerPayloadRepository;
use Maestro\Workflow\Domain\TriggerPayloadRecord;
use Maestro\Workflow\Infrastructure\Persistence\Hydrators\TriggerPayloadHydrator;
use Maestro\Workflow\Infrastructure\Persistence\Models\TriggerPayloadModel;
use Maestro\Workflow\ValueObjects\TriggerPayloadId;
use Maestro\Workflow\ValueObjects\WorkflowId;

final readonly class EloquentTriggerPayloadRepository implements TriggerPayloadRepository
{
    public function __construct(
        private TriggerPayloadHydrator $triggerPayloadHydrator,
    ) {}

    public function save(TriggerPayloadRecord $triggerPayloadRecord): void
    {
        $triggerPayloadModel = $this->triggerPayloadHydrator->fromDomain($triggerPayloadRecord);

        $existing = TriggerPayloadModel::query()->find($triggerPayloadRecord->id->value);

        if ($existing instanceof TriggerPayloadModel) {
            $existing->workflow_id = $triggerPayloadModel->workflow_id;
            $existing->trigger_key = $triggerPayloadModel->trigger_key;
            $existing->payload = $triggerPayloadModel->payload;
            $existing->received_at = $triggerPayloadModel->received_at;
            $existing->source_ip = $triggerPayloadModel->source_ip;
            $existing->source_identifier = $triggerPayloadModel->source_identifier;
            $existing->save();

            return;
        }

        $triggerPayloadModel->save();
    }

    public function find(TriggerPayloadId $triggerPayloadId): ?TriggerPayloadRecord
    {
        $model = TriggerPayloadModel::query()->find($triggerPayloadId->value);

        if (! $model instanceof TriggerPayloadModel) {
            return null;
        }

        return $this->triggerPayloadHydrator->toDomain($model);
    }

    public function findByWorkflowId(WorkflowId $workflowId): ?TriggerPayloadRecord
    {
        $model = TriggerPayloadModel::query()
            ->where('workflow_id', $workflowId->value)
            ->orderByDesc('received_at')
            ->first();

        if (! $model instanceof TriggerPayloadModel) {
            return null;
        }

        return $this->triggerPayloadHydrator->toDomain($model);
    }

    public function findByWorkflowIdAndTriggerKey(WorkflowId $workflowId, string $triggerKey): ?TriggerPayloadRecord
    {
        $model = TriggerPayloadModel::query()
            ->where('workflow_id', $workflowId->value)
            ->where('trigger_key', $triggerKey)
            ->orderByDesc('received_at')
            ->first();

        if (! $model instanceof TriggerPayloadModel) {
            return null;
        }

        return $this->triggerPayloadHydrator->toDomain($model);
    }

    /**
     * @return array<TriggerPayloadRecord>
     */
    public function findAllByWorkflowId(WorkflowId $workflowId): array
    {
        return TriggerPayloadModel::query()
            ->where('workflow_id', $workflowId->value)
            ->orderByDesc('received_at')
            ->get()
            ->map(fn (TriggerPayloadModel $triggerPayloadModel): TriggerPayloadRecord => $this->triggerPayloadHydrator->toDomain($triggerPayloadModel))
            ->all();
    }

    public function delete(TriggerPayloadId $triggerPayloadId): void
    {
        TriggerPayloadModel::query()
            ->where('id', $triggerPayloadId->value)
            ->delete();
    }

    public function deleteByWorkflowId(WorkflowId $workflowId): void
    {
        TriggerPayloadModel::query()
            ->where('workflow_id', $workflowId->value)
            ->delete();
    }
}
