<?php

declare(strict_types=1);

namespace Maestro\Workflow\Domain\Events;

use Carbon\CarbonImmutable;
use Maestro\Workflow\Enums\ResolutionDecision;
use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\DefinitionVersion;
use Maestro\Workflow\ValueObjects\ResolutionDecisionId;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\WorkflowId;

/**
 * Emitted when a resolution decision is made for a failed workflow.
 *
 * This event records the decision (retry, compensate, cancel, etc.)
 * and who made it.
 */
final readonly class ResolutionDecisionMade
{
    /**
     * @param list<string>|null $compensateStepKeys
     */
    public function __construct(
        public ResolutionDecisionId $decisionId,
        public WorkflowId $workflowId,
        public DefinitionKey $definitionKey,
        public DefinitionVersion $definitionVersion,
        public ResolutionDecision $decision,
        public ?string $decidedBy,
        public ?string $reason,
        public ?StepKey $retryFromStepKey,
        public ?array $compensateStepKeys,
        public CarbonImmutable $occurredAt,
    ) {}
}
