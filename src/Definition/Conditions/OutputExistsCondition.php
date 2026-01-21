<?php

declare(strict_types=1);

namespace Maestro\Workflow\Definition\Conditions;

use Maestro\Workflow\Contracts\StepCondition;
use Maestro\Workflow\Contracts\StepOutput;
use Maestro\Workflow\Contracts\WorkflowContext;
use Maestro\Workflow\Domain\WorkflowInstance;

/**
 * A condition that checks if a specific output is available.
 *
 * Useful for conditional branching based on prior step results.
 */
final readonly class OutputExistsCondition implements StepCondition
{
    /**
     * @param class-string<StepOutput> $outputClass
     */
    private function __construct(
        private string $outputClass,
    ) {}

    /**
     * @param class-string<StepOutput> $outputClass
     */
    public static function create(string $outputClass): self
    {
        return new self($outputClass);
    }

    public function shouldExecute(WorkflowInstance $workflowInstance, WorkflowContext $context): bool
    {
        return $context->hasOutput($this->outputClass);
    }
}
