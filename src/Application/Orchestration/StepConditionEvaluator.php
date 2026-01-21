<?php

declare(strict_types=1);

namespace Maestro\Workflow\Application\Orchestration;

use Maestro\Workflow\Contracts\StepCondition;
use Maestro\Workflow\Contracts\StepDefinition;
use Maestro\Workflow\Contracts\WorkflowContextLoader;
use Maestro\Workflow\Definition\WorkflowDefinition;
use Maestro\Workflow\Domain\WorkflowInstance;

/**
 * Evaluates step conditions to determine if a step should execute.
 */
final readonly class StepConditionEvaluator
{
    public function __construct(
        private WorkflowContextLoader $contextLoader,
    ) {}

    /**
     * Check if a step should execute based on its condition.
     *
     * @return bool True if the step should execute, false to skip
     */
    public function shouldExecute(
        WorkflowInstance $workflowInstance,
        WorkflowDefinition $workflowDefinition,
        StepDefinition $stepDefinition,
    ): bool {
        $condition = $stepDefinition->condition();

        if (! $condition instanceof StepCondition) {
            return true;
        }

        $context = $this->contextLoader->load($workflowInstance, $workflowDefinition);

        return $condition->shouldExecute($workflowInstance, $context);
    }
}
