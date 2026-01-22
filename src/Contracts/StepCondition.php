<?php

declare(strict_types=1);

namespace Maestro\Workflow\Contracts;

/**
 * Evaluates whether a step should execute based on workflow state.
 *
 * Step conditions are resolved via the Laravel container, allowing
 * dependency injection of services needed for condition evaluation.
 */
interface StepCondition
{
    /**
     * Evaluate whether the step should execute.
     *
     * @return bool True if the step should execute, false if it should be skipped
     */
    public function evaluate(StepOutputReader $stepOutputReader): bool;
}
