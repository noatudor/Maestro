<?php

declare(strict_types=1);

namespace Maestro\Workflow\Tests\Fakes;

use Maestro\Workflow\Contracts\StepOutput;
use Maestro\Workflow\Contracts\StepOutputRepository;
use Maestro\Workflow\ValueObjects\WorkflowId;

final class InMemoryStepOutputRepository implements StepOutputRepository
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
     * @param class-string<StepOutput> $outputClass
     */
    public function has(WorkflowId $workflowId, string $outputClass): bool
    {
        return isset($this->outputs[$workflowId->value][$outputClass]);
    }

    public function save(WorkflowId $workflowId, StepOutput $output): void
    {
        if (! isset($this->outputs[$workflowId->value])) {
            $this->outputs[$workflowId->value] = [];
        }

        $this->outputs[$workflowId->value][$output::class] = $output;
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
