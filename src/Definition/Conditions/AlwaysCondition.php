<?php

declare(strict_types=1);

namespace Maestro\Workflow\Definition\Conditions;

use Maestro\Workflow\Contracts\StepCondition;
use Maestro\Workflow\Contracts\WorkflowContext;
use Maestro\Workflow\Domain\WorkflowInstance;

/**
 * A condition that always evaluates to true.
 *
 * Used as a placeholder or default condition.
 */
final readonly class AlwaysCondition implements StepCondition
{
    public function shouldExecute(WorkflowInstance $workflowInstance, WorkflowContext $context): bool
    {
        return true;
    }
}
