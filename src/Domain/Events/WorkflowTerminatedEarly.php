<?php

declare(strict_types=1);

namespace Maestro\Workflow\Domain\Events;

use Carbon\CarbonImmutable;
use Maestro\Workflow\Contracts\TerminationCondition;
use Maestro\Workflow\Enums\WorkflowState;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\WorkflowId;

/**
 * Dispatched when a workflow terminates early due to a termination condition.
 */
final readonly class WorkflowTerminatedEarly
{
    /**
     * @param class-string<TerminationCondition> $conditionClass
     */
    public function __construct(
        public WorkflowId $workflowId,
        public StepKey $lastStepKey,
        public string $conditionClass,
        public WorkflowState $terminalState,
        public string $reason,
        public CarbonImmutable $occurredAt,
    ) {}
}
