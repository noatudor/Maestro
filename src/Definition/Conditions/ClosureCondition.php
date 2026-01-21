<?php

declare(strict_types=1);

namespace Maestro\Workflow\Definition\Conditions;

use Closure;
use Maestro\Workflow\Contracts\StepCondition;
use Maestro\Workflow\Contracts\WorkflowContext;
use Maestro\Workflow\Domain\WorkflowInstance;

/**
 * A condition based on a custom closure evaluation.
 *
 * Allows flexible, user-defined conditions without creating custom classes.
 */
final readonly class ClosureCondition implements StepCondition
{
    /**
     * @param Closure(WorkflowInstance, WorkflowContext): bool $evaluator
     */
    private function __construct(
        private Closure $evaluator,
    ) {}

    /**
     * @param Closure(WorkflowInstance, WorkflowContext): bool $evaluator
     */
    public static function create(Closure $evaluator): self
    {
        return new self($evaluator);
    }

    public function shouldExecute(WorkflowInstance $workflowInstance, WorkflowContext $context): bool
    {
        return ($this->evaluator)($workflowInstance, $context);
    }
}
