<?php

declare(strict_types=1);

namespace Maestro\Workflow\Tests\Fakes;

use Maestro\Workflow\Contracts\CompensationRunRepository;
use Maestro\Workflow\Domain\CompensationRun;
use Maestro\Workflow\Enums\CompensationRunStatus;
use Maestro\Workflow\ValueObjects\CompensationRunId;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\WorkflowId;

final class InMemoryCompensationRunRepository implements CompensationRunRepository
{
    /** @var array<string, CompensationRun> */
    private array $runs = [];

    public function find(CompensationRunId $compensationRunId): ?CompensationRun
    {
        return $this->runs[$compensationRunId->value] ?? null;
    }

    public function save(CompensationRun $compensationRun): void
    {
        $this->runs[$compensationRun->id->value] = $compensationRun;
    }

    public function findByWorkflow(WorkflowId $workflowId): array
    {
        $runs = array_filter(
            $this->runs,
            static fn (CompensationRun $compensationRun): bool => $compensationRun->workflowId->equals($workflowId),
        );

        usort($runs, static fn (CompensationRun $a, CompensationRun $b): int => $a->executionOrder <=> $b->executionOrder);

        return array_values($runs);
    }

    public function findByWorkflowAndStep(WorkflowId $workflowId, StepKey $stepKey): ?CompensationRun
    {
        foreach ($this->runs as $run) {
            if ($run->workflowId->equals($workflowId) && $run->stepKey->equals($stepKey)) {
                return $run;
            }
        }

        return null;
    }

    public function findByWorkflowAndStatus(WorkflowId $workflowId, CompensationRunStatus $compensationRunStatus): array
    {
        $runs = array_filter(
            $this->runs,
            static fn (CompensationRun $compensationRun): bool => $compensationRun->workflowId->equals($workflowId)
                && $compensationRun->status() === $compensationRunStatus,
        );

        usort($runs, static fn (CompensationRun $a, CompensationRun $b): int => $a->executionOrder <=> $b->executionOrder);

        return array_values($runs);
    }

    public function findNextPending(WorkflowId $workflowId): ?CompensationRun
    {
        $pendingRuns = $this->findByWorkflowAndStatus($workflowId, CompensationRunStatus::Pending);

        return $pendingRuns[0] ?? null;
    }

    public function allTerminal(WorkflowId $workflowId): bool
    {
        $runs = $this->findByWorkflow($workflowId);

        return array_all($runs, static fn ($run) => $run->isTerminal());
    }

    public function allSuccessful(WorkflowId $workflowId): bool
    {
        $runs = $this->findByWorkflow($workflowId);

        return array_all($runs, static fn ($run) => $run->status()->isSuccessful());
    }

    public function anyFailed(WorkflowId $workflowId): bool
    {
        $runs = $this->findByWorkflow($workflowId);

        return array_any($runs, static fn ($run) => $run->isFailed());
    }

    public function countByWorkflow(WorkflowId $workflowId): int
    {
        return count($this->findByWorkflow($workflowId));
    }

    public function deleteByWorkflow(WorkflowId $workflowId): int
    {
        $deletedCount = 0;

        foreach ($this->runs as $id => $run) {
            if ($run->workflowId->equals($workflowId)) {
                unset($this->runs[$id]);
                $deletedCount++;
            }
        }

        return $deletedCount;
    }

    public function clear(): void
    {
        $this->runs = [];
    }

    public function all(): array
    {
        return array_values($this->runs);
    }
}
