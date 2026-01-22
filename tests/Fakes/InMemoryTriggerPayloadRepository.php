<?php

declare(strict_types=1);

namespace Maestro\Workflow\Tests\Fakes;

use Maestro\Workflow\Contracts\TriggerPayloadRepository;
use Maestro\Workflow\Domain\TriggerPayloadRecord;
use Maestro\Workflow\ValueObjects\TriggerPayloadId;
use Maestro\Workflow\ValueObjects\WorkflowId;

final class InMemoryTriggerPayloadRepository implements TriggerPayloadRepository
{
    /** @var array<string, TriggerPayloadRecord> */
    private array $records = [];

    public function save(TriggerPayloadRecord $triggerPayloadRecord): void
    {
        $this->records[$triggerPayloadRecord->id->value] = $triggerPayloadRecord;
    }

    public function find(TriggerPayloadId $triggerPayloadId): ?TriggerPayloadRecord
    {
        return $this->records[$triggerPayloadId->value] ?? null;
    }

    public function findByWorkflowId(WorkflowId $workflowId): ?TriggerPayloadRecord
    {
        $records = array_filter(
            $this->records,
            static fn (TriggerPayloadRecord $triggerPayloadRecord): bool => $triggerPayloadRecord->workflowId->equals($workflowId),
        );

        if ($records === []) {
            return null;
        }

        usort($records, static fn (TriggerPayloadRecord $a, TriggerPayloadRecord $b): int => $b->receivedAt <=> $a->receivedAt);

        return $records[0];
    }

    public function findByWorkflowIdAndTriggerKey(WorkflowId $workflowId, string $triggerKey): ?TriggerPayloadRecord
    {
        $records = array_filter(
            $this->records,
            static fn (TriggerPayloadRecord $triggerPayloadRecord): bool => $triggerPayloadRecord->workflowId->equals($workflowId)
                && $triggerPayloadRecord->triggerKey === $triggerKey,
        );

        if ($records === []) {
            return null;
        }

        usort($records, static fn (TriggerPayloadRecord $a, TriggerPayloadRecord $b): int => $b->receivedAt <=> $a->receivedAt);

        return $records[0];
    }

    /**
     * @return array<TriggerPayloadRecord>
     */
    public function findAllByWorkflowId(WorkflowId $workflowId): array
    {
        $records = array_filter(
            $this->records,
            static fn (TriggerPayloadRecord $triggerPayloadRecord): bool => $triggerPayloadRecord->workflowId->equals($workflowId),
        );

        usort($records, static fn (TriggerPayloadRecord $a, TriggerPayloadRecord $b): int => $b->receivedAt <=> $a->receivedAt);

        return array_values($records);
    }

    public function delete(TriggerPayloadId $triggerPayloadId): void
    {
        unset($this->records[$triggerPayloadId->value]);
    }

    public function deleteByWorkflowId(WorkflowId $workflowId): void
    {
        $this->records = array_filter(
            $this->records,
            static fn (TriggerPayloadRecord $triggerPayloadRecord): bool => ! $triggerPayloadRecord->workflowId->equals($workflowId),
        );
    }

    /**
     * @return array<string, TriggerPayloadRecord>
     */
    public function all(): array
    {
        return $this->records;
    }

    public function clear(): void
    {
        $this->records = [];
    }

    public function count(): int
    {
        return count($this->records);
    }
}
