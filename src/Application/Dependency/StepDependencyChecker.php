<?php

declare(strict_types=1);

namespace Maestro\Workflow\Application\Dependency;

use Maestro\Workflow\Contracts\StepDefinition;
use Maestro\Workflow\Contracts\StepOutput;
use Maestro\Workflow\Contracts\StepOutputRepository;
use Maestro\Workflow\ValueObjects\WorkflowId;

/**
 * Checks if step dependencies (required outputs) are satisfied.
 *
 * Used by the workflow advancer to determine if a step is eligible for execution.
 */
final readonly class StepDependencyChecker
{
    public function __construct(
        private StepOutputRepository $stepOutputRepository,
    ) {}

    /**
     * Check if all dependencies for a step are satisfied.
     */
    public function areDependenciesMet(WorkflowId $workflowId, StepDefinition $stepDefinition): bool
    {
        $requiredOutputs = $stepDefinition->requires();

        if ($requiredOutputs === []) {
            return true;
        }

        return array_all($requiredOutputs, fn (string $outputClass): bool => $this->stepOutputRepository->has($workflowId, $outputClass));
    }

    /**
     * Get the list of missing dependencies for a step.
     *
     * @return list<class-string<StepOutput>>
     */
    public function getMissingDependencies(WorkflowId $workflowId, StepDefinition $stepDefinition): array
    {
        $missing = [];

        foreach ($stepDefinition->requires() as $outputClass) {
            if (! $this->stepOutputRepository->has($workflowId, $outputClass)) {
                $missing[] = $outputClass;
            }
        }

        return $missing;
    }

    /**
     * Get the list of satisfied dependencies for a step.
     *
     * @return list<class-string<StepOutput>>
     */
    public function getSatisfiedDependencies(WorkflowId $workflowId, StepDefinition $stepDefinition): array
    {
        $satisfied = [];

        foreach ($stepDefinition->requires() as $outputClass) {
            if ($this->stepOutputRepository->has($workflowId, $outputClass)) {
                $satisfied[] = $outputClass;
            }
        }

        return $satisfied;
    }
}
