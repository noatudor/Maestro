<?php

declare(strict_types=1);

namespace Tests\Fakes;

use Maestro\Workflow\Contracts\BranchDecisionRepository;
use Maestro\Workflow\Domain\BranchDecisionRecord;
use Maestro\Workflow\ValueObjects\BranchDecisionId;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\WorkflowId;

final class InMemoryBranchDecisionRepository implements BranchDecisionRepository
{
    /** @var array<string, BranchDecisionRecord> */
    private array $decisions = [];

    public function save(BranchDecisionRecord $branchDecisionRecord): void
    {
        $this->decisions[$branchDecisionRecord->id->value] = $branchDecisionRecord;
    }

    public function find(BranchDecisionId $branchDecisionId): ?BranchDecisionRecord
    {
        return $this->decisions[$branchDecisionId->value] ?? null;
    }

    public function findByWorkflowAndBranchPoint(
        WorkflowId $workflowId,
        StepKey $stepKey,
    ): ?BranchDecisionRecord {
        foreach ($this->decisions as $decision) {
            if (
                $decision->workflowId->equals($workflowId)
                && $decision->branchPointKey->equals($stepKey)
            ) {
                return $decision;
            }
        }

        return null;
    }

    public function findAllByWorkflowId(WorkflowId $workflowId): array
    {
        $result = [];

        foreach ($this->decisions as $decision) {
            if ($decision->workflowId->equals($workflowId)) {
                $result[] = $decision;
            }
        }

        usort($result, static fn ($a, $b): int => $a->evaluatedAt <=> $b->evaluatedAt);

        return $result;
    }

    public function deleteByWorkflowId(WorkflowId $workflowId): void
    {
        foreach ($this->decisions as $id => $decision) {
            if ($decision->workflowId->equals($workflowId)) {
                unset($this->decisions[$id]);
            }
        }
    }

    /**
     * Clear all stored decisions (for testing).
     */
    public function clear(): void
    {
        $this->decisions = [];
    }

    /**
     * Get all stored decisions (for testing).
     *
     * @return list<BranchDecisionRecord>
     */
    public function all(): array
    {
        return array_values($this->decisions);
    }
}
