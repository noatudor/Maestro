<?php

declare(strict_types=1);

namespace Maestro\Workflow\Contracts;

use Maestro\Workflow\Enums\WorkflowState;

/**
 * Evaluates whether a workflow should terminate early after a step completes.
 *
 * Termination conditions are resolved via the Laravel container, allowing
 * dependency injection of services needed for condition evaluation.
 */
interface TerminationCondition
{
    /**
     * Evaluate whether the workflow should terminate early.
     *
     * @return bool True if the workflow should terminate, false to continue
     */
    public function shouldTerminate(StepOutputReader $stepOutputReader): bool;

    /**
     * Get the terminal state to transition to.
     *
     * @return WorkflowState Must be either Succeeded or Failed
     */
    public function terminalState(): WorkflowState;

    /**
     * Get the reason for early termination.
     */
    public function terminationReason(): string;
}
