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
        private StepOutputRepository $outputRepository,
    ) {}

    /**
     * Check if all dependencies for a step are satisfied.
     */
    public function areDependenciesMet(WorkflowId $workflowId, StepDefinition $step): bool
    {
        $requiredOutputs = $step->requires();

        if ($requiredOutputs === []) {
            return true;
        }

        foreach ($requiredOutputs as $outputClass) {
            if (! $this->outputRepository->has($workflowId, $outputClass)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the list of missing dependencies for a step.
     *
     * @return list<class-string<StepOutput>>
     */
    public function getMissingDependencies(WorkflowId $workflowId, StepDefinition $step): array
    {
        $missing = [];

        foreach ($step->requires() as $outputClass) {
            if (! $this->outputRepository->has($workflowId, $outputClass)) {
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
    public function getSatisfiedDependencies(WorkflowId $workflowId, StepDefinition $step): array
    {
        $satisfied = [];

        foreach ($step->requires() as $outputClass) {
            if ($this->outputRepository->has($workflowId, $outputClass)) {
                $satisfied[] = $outputClass;
            }
        }

        return $satisfied;
    }
}
