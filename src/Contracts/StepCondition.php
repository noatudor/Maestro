<?php

declare(strict_types=1);

namespace Maestro\Workflow\Contracts;

use Maestro\Workflow\Domain\WorkflowInstance;

/**
 * Evaluates whether a step should be executed.
 *
 * Conditions enable dynamic workflow behavior by determining at runtime
 * whether a step should execute based on workflow state and outputs.
 */
interface StepCondition
{
    /**
     * Evaluate whether the step should execute.
     *
     * @param WorkflowInstance $workflowInstance The current workflow instance
     * @param WorkflowContext $context The workflow context with available outputs
     *
     * @return bool True if the step should execute, false to skip
     */
    public function shouldExecute(WorkflowInstance $workflowInstance, WorkflowContext $context): bool;
}
