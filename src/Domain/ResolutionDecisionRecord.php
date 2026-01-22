<?php

declare(strict_types=1);

namespace Maestro\Workflow\Domain;

use Carbon\CarbonImmutable;
use Maestro\Workflow\Enums\ResolutionDecision;
use Maestro\Workflow\ValueObjects\ResolutionDecisionId;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\WorkflowId;

/**
 * Records a resolution decision made for a failed workflow.
 *
 * This is an immutable audit record that persists the decision,
 * who made it, and any associated metadata.
 */
final readonly class ResolutionDecisionRecord
{
    /**
     * @param list<StepKey>|null $compensateStepKeys
     */
    private function __construct(
        public ResolutionDecisionId $id,
        public WorkflowId $workflowId,
        public ResolutionDecision $decisionType,
        public ?string $decidedBy,
        public ?string $reason,
        public ?StepKey $retryFromStepKey,
        public ?array $compensateStepKeys,
        public CarbonImmutable $createdAt,
    ) {}

    /**
     * @param list<StepKey>|null $compensateStepKeys
     */
    public static function create(
        WorkflowId $workflowId,
        ResolutionDecision $resolutionDecision,
        ?string $decidedBy = null,
        ?string $reason = null,
        ?StepKey $retryFromStepKey = null,
        ?array $compensateStepKeys = null,
    ): self {
        return new self(
            id: ResolutionDecisionId::generate(),
            workflowId: $workflowId,
            decisionType: $resolutionDecision,
            decidedBy: $decidedBy,
            reason: $reason,
            retryFromStepKey: $retryFromStepKey,
            compensateStepKeys: $compensateStepKeys,
            createdAt: CarbonImmutable::now(),
        );
    }

    /**
     * @param list<StepKey>|null $compensateStepKeys
     */
    public static function reconstitute(
        ResolutionDecisionId $resolutionDecisionId,
        WorkflowId $workflowId,
        ResolutionDecision $resolutionDecision,
        ?string $decidedBy,
        ?string $reason,
        ?StepKey $retryFromStepKey,
        ?array $compensateStepKeys,
        CarbonImmutable $createdAt,
    ): self {
        return new self(
            id: $resolutionDecisionId,
            workflowId: $workflowId,
            decisionType: $resolutionDecision,
            decidedBy: $decidedBy,
            reason: $reason,
            retryFromStepKey: $retryFromStepKey,
            compensateStepKeys: $compensateStepKeys,
            createdAt: $createdAt,
        );
    }

    public function retriesFailedStep(): bool
    {
        return $this->decisionType->retriesFailedStep();
    }

    public function retriesFromSpecificStep(): bool
    {
        return $this->decisionType->retriesFromSpecificStep();
    }

    public function triggersCompensation(): bool
    {
        return $this->decisionType->triggersCompensation();
    }

    public function cancelsWorkflow(): bool
    {
        return $this->decisionType->cancelsWorkflow();
    }

    public function marksAsResolved(): bool
    {
        return $this->decisionType->marksAsResolved();
    }

    public function continuesExecution(): bool
    {
        return $this->decisionType->continuesExecution();
    }
}
