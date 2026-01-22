<?php

declare(strict_types=1);

namespace Maestro\Workflow\Contracts;

use Maestro\Workflow\Domain\TriggerPayloadRecord;
use Maestro\Workflow\ValueObjects\TriggerPayloadId;
use Maestro\Workflow\ValueObjects\WorkflowId;

interface TriggerPayloadRepository
{
    public function save(TriggerPayloadRecord $triggerPayloadRecord): void;

    public function find(TriggerPayloadId $triggerPayloadId): ?TriggerPayloadRecord;

    public function findByWorkflowId(WorkflowId $workflowId): ?TriggerPayloadRecord;

    public function findByWorkflowIdAndTriggerKey(WorkflowId $workflowId, string $triggerKey): ?TriggerPayloadRecord;

    /**
     * @return array<TriggerPayloadRecord>
     */
    public function findAllByWorkflowId(WorkflowId $workflowId): array;

    public function delete(TriggerPayloadId $triggerPayloadId): void;

    public function deleteByWorkflowId(WorkflowId $workflowId): void;
}
