<?php

declare(strict_types=1);

namespace Maestro\Workflow\Tests\Fakes;

use Maestro\Workflow\Contracts\MergeableOutput;
use Maestro\Workflow\Contracts\StepOutput;
use Maestro\Workflow\Contracts\StepOutputRepository;
use Maestro\Workflow\ValueObjects\WorkflowId;

/**
 * @internal For testing purposes - can be extended in tests
 */
class InMemoryStepOutputRepository implements StepOutputRepository
{
    /**
     * @var array<string, array<class-string<StepOutput>, StepOutput>>
     */
    private array $outputs = [];

    /**
     * @template T of StepOutput
     *
     * @param class-string<T> $outputClass
     *
     * @return T|null
     */
    public function find(WorkflowId $workflowId, string $outputClass): ?StepOutput
    {
        return $this->outputs[$workflowId->value][$outputClass] ?? null;
    }

    /**
     * @template T of StepOutput
     *
     * @param class-string<T> $outputClass
     *
     * @return T|null
     */
    public function findForUpdate(WorkflowId $workflowId, string $outputClass): ?StepOutput
    {
        return $this->find($workflowId, $outputClass);
    }

    /**
     * @param class-string<StepOutput> $outputClass
     */
    public function has(WorkflowId $workflowId, string $outputClass): bool
    {
        return isset($this->outputs[$workflowId->value][$outputClass]);
    }

    public function save(WorkflowId $workflowId, StepOutput $stepOutput): void
    {
        if (! isset($this->outputs[$workflowId->value])) {
            $this->outputs[$workflowId->value] = [];
        }

        $this->outputs[$workflowId->value][$stepOutput::class] = $stepOutput;
    }

    public function saveWithAtomicMerge(WorkflowId $workflowId, MergeableOutput $mergeableOutput): void
    {
        $outputClass = $mergeableOutput::class;

        $existing = $this->find($workflowId, $outputClass);

        $finalOutput = $mergeableOutput;
        if ($existing instanceof MergeableOutput) {
            $finalOutput = $existing->mergeWith($mergeableOutput);
        }

        $this->save($workflowId, $finalOutput);
    }

    /**
     * @return list<StepOutput>
     */
    public function findAllByWorkflowId(WorkflowId $workflowId): array
    {
        if (! isset($this->outputs[$workflowId->value])) {
            return [];
        }

        return array_values($this->outputs[$workflowId->value]);
    }

    public function deleteByWorkflowId(WorkflowId $workflowId): void
    {
        unset($this->outputs[$workflowId->value]);
    }

    public function clear(): void
    {
        $this->outputs = [];
    }
}
