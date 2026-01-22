<?php

declare(strict_types=1);

namespace Maestro\Workflow\Domain\Events;

use Carbon\CarbonImmutable;
use Maestro\Workflow\Contracts\BranchCondition;
use Maestro\Workflow\ValueObjects\BranchDecisionId;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\WorkflowId;

/**
 * Dispatched when a branch condition is evaluated at a branch point.
 */
final readonly class BranchEvaluated
{
    /**
     * @param list<string> $selectedBranches
     * @param class-string<BranchCondition> $conditionClass
     */
    public function __construct(
        public WorkflowId $workflowId,
        public BranchDecisionId $branchDecisionId,
        public StepKey $branchPointKey,
        public string $conditionClass,
        public array $selectedBranches,
        public CarbonImmutable $occurredAt,
    ) {}
}
