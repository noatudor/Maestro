<?php

declare(strict_types=1);

namespace Maestro\Workflow\Tests\Fakes;

use Maestro\Workflow\Contracts\StepRunRepository;
use Maestro\Workflow\Domain\Collections\StepRunCollection;
use Maestro\Workflow\Domain\StepRun;
use Maestro\Workflow\Enums\StepState;
use Maestro\Workflow\Exceptions\StepNotFoundException;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\StepRunId;
use Maestro\Workflow\ValueObjects\WorkflowId;

final class InMemoryStepRunRepository implements StepRunRepository
{
    /** @var array<string, StepRun> */
    private array $stepRuns = [];

    public function find(StepRunId $stepRunId): ?StepRun
    {
        return $this->stepRuns[$stepRunId->value] ?? null;
    }

    /**
     * @throws StepNotFoundException
     */
    public function findOrFail(StepRunId $stepRunId): StepRun
    {
        $stepRun = $this->find($stepRunId);

        if ($stepRun === null) {
            throw StepNotFoundException::withId($stepRunId);
        }

        return $stepRun;
    }

    public function save(StepRun $stepRun): void
    {
        $this->stepRuns[$stepRun->id->value] = $stepRun;
    }

    public function findByWorkflowId(WorkflowId $workflowId): StepRunCollection
    {
        $stepRuns = array_filter(
            $this->stepRuns,
            static fn (StepRun $run) => $run->workflowId->value === $workflowId->value,
        );

        return new StepRunCollection(array_values($stepRuns));
    }

    public function findByWorkflowIdAndStepKey(WorkflowId $workflowId, StepKey $stepKey): ?StepRun
    {
        foreach ($this->stepRuns as $stepRun) {
            if ($stepRun->workflowId->value === $workflowId->value
                && $stepRun->stepKey->value === $stepKey->value) {
                return $stepRun;
            }
        }

        return null;
    }

    public function findLatestByWorkflowIdAndStepKey(WorkflowId $workflowId, StepKey $stepKey): ?StepRun
    {
        $matching = array_filter(
            $this->stepRuns,
            static fn (StepRun $run) => $run->workflowId->value === $workflowId->value
                && $run->stepKey->value === $stepKey->value,
        );

        if (empty($matching)) {
            return null;
        }

        usort($matching, static fn (StepRun $a, StepRun $b) => $b->attempt <=> $a->attempt);

        return $matching[0];
    }

    public function findByWorkflowIdAndState(WorkflowId $workflowId, StepState $state): StepRunCollection
    {
        $stepRuns = array_filter(
            $this->stepRuns,
            static fn (StepRun $run) => $run->workflowId->value === $workflowId->value
                && $run->status() === $state,
        );

        return new StepRunCollection(array_values($stepRuns));
    }

    /**
     * @return list<StepRun>
     */
    public function all(): array
    {
        return array_values($this->stepRuns);
    }

    public function count(): int
    {
        return count($this->stepRuns);
    }

    public function clear(): void
    {
        $this->stepRuns = [];
    }
}
