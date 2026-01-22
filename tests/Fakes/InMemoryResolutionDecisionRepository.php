<?php

declare(strict_types=1);

namespace Maestro\Workflow\Tests\Fakes;

use Maestro\Workflow\Contracts\ResolutionDecisionRepository;
use Maestro\Workflow\Domain\ResolutionDecisionRecord;
use Maestro\Workflow\ValueObjects\ResolutionDecisionId;
use Maestro\Workflow\ValueObjects\WorkflowId;

final class InMemoryResolutionDecisionRepository implements ResolutionDecisionRepository
{
    /** @var array<string, ResolutionDecisionRecord> */
    private array $decisions = [];

    public function find(ResolutionDecisionId $resolutionDecisionId): ?ResolutionDecisionRecord
    {
        return $this->decisions[$resolutionDecisionId->value] ?? null;
    }

    public function save(ResolutionDecisionRecord $resolutionDecisionRecord): void
    {
        $this->decisions[$resolutionDecisionRecord->id->value] = $resolutionDecisionRecord;
    }

    /**
     * @return list<ResolutionDecisionRecord>
     */
    public function findByWorkflow(WorkflowId $workflowId): array
    {
        $records = array_filter(
            $this->decisions,
            static fn (ResolutionDecisionRecord $resolutionDecisionRecord): bool => $resolutionDecisionRecord->workflowId->equals($workflowId),
        );

        usort(
            $records,
            static fn (ResolutionDecisionRecord $a, ResolutionDecisionRecord $b): int => $b->createdAt <=> $a->createdAt,
        );

        return array_values($records);
    }

    public function findLatestByWorkflow(WorkflowId $workflowId): ?ResolutionDecisionRecord
    {
        $records = $this->findByWorkflow($workflowId);

        return $records[0] ?? null;
    }

    public function countByWorkflow(WorkflowId $workflowId): int
    {
        return count($this->findByWorkflow($workflowId));
    }

    public function deleteByWorkflow(WorkflowId $workflowId): int
    {
        $count = 0;
        foreach ($this->decisions as $id => $record) {
            if ($record->workflowId->equals($workflowId)) {
                unset($this->decisions[$id]);
                $count++;
            }
        }

        return $count;
    }

    public function all(): array
    {
        return array_values($this->decisions);
    }

    public function clear(): void
    {
        $this->decisions = [];
    }
}
