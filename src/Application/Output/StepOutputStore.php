<?php

declare(strict_types=1);

namespace Maestro\Workflow\Application\Output;

use Maestro\Workflow\Contracts\MergeableOutput;
use Maestro\Workflow\Contracts\StepOutput;
use Maestro\Workflow\Contracts\StepOutputRepository;
use Maestro\Workflow\Exceptions\MissingRequiredOutputException;
use Maestro\Workflow\ValueObjects\WorkflowId;

/**
 * Provides typed access to step outputs for a specific workflow.
 *
 * Handles reading, writing, and merging of step outputs with proper type safety.
 */
final readonly class StepOutputStore
{
    public function __construct(
        private WorkflowId $workflowId,
        private StepOutputRepository $repository,
    ) {}

    /**
     * Read a step output by its class type.
     *
     * @template T of StepOutput
     *
     * @param class-string<T> $outputClass
     *
     * @return T
     *
     * @throws MissingRequiredOutputException
     */
    public function read(string $outputClass): StepOutput
    {
        $output = $this->repository->find($this->workflowId, $outputClass);

        if ($output === null) {
            throw MissingRequiredOutputException::inWorkflow($this->workflowId, $outputClass);
        }

        return $output;
    }

    /**
     * Check if an output exists.
     *
     * @param class-string<StepOutput> $outputClass
     */
    public function has(string $outputClass): bool
    {
        return $this->repository->has($this->workflowId, $outputClass);
    }

    /**
     * Write a step output.
     *
     * For MergeableOutput instances, if an output of the same type already exists,
     * it will be merged with the new output before persisting.
     */
    public function write(StepOutput $output): void
    {
        $outputClass = $output::class;

        if ($output instanceof MergeableOutput && $this->has($outputClass)) {
            $existing = $this->repository->find($this->workflowId, $outputClass);

            if ($existing instanceof MergeableOutput) {
                $output = $existing->mergeWith($output);
            }
        }

        $this->repository->save($this->workflowId, $output);
    }

    /**
     * Get all outputs for this workflow.
     *
     * @return list<StepOutput>
     */
    public function all(): array
    {
        return $this->repository->findAllByWorkflowId($this->workflowId);
    }

    /**
     * Get the workflow ID this store is scoped to.
     */
    public function workflowId(): WorkflowId
    {
        return $this->workflowId;
    }
}
