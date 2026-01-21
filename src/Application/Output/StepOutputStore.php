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
 * Delegates atomic merge operations to the repository for proper transaction handling.
 */
final readonly class StepOutputStore
{
    public function __construct(
        private WorkflowId $workflowId,
        private StepOutputRepository $stepOutputRepository,
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
        $output = $this->stepOutputRepository->find($this->workflowId, $outputClass);

        if (! $output instanceof StepOutput) {
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
        return $this->stepOutputRepository->has($this->workflowId, $outputClass);
    }

    /**
     * Write a step output.
     *
     * For MergeableOutput instances, the repository handles atomic merge operations
     * with proper transaction and locking to prevent lost updates in fan-out scenarios.
     */
    public function write(StepOutput $stepOutput): void
    {
        if ($stepOutput instanceof MergeableOutput) {
            $this->stepOutputRepository->saveWithAtomicMerge($this->workflowId, $stepOutput);

            return;
        }

        $this->stepOutputRepository->save($this->workflowId, $stepOutput);
    }

    /**
     * Get all outputs for this workflow.
     *
     * @return list<StepOutput>
     */
    public function all(): array
    {
        return $this->stepOutputRepository->findAllByWorkflowId($this->workflowId);
    }

    /**
     * Get the workflow ID this store is scoped to.
     */
    public function workflowId(): WorkflowId
    {
        return $this->workflowId;
    }
}
