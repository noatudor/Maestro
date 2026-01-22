<?php

declare(strict_types=1);

namespace Maestro\Workflow\Domain;

use Carbon\CarbonImmutable;
use Maestro\Workflow\Contracts\BranchCondition;
use Maestro\Workflow\ValueObjects\BranchDecisionId;
use Maestro\Workflow\ValueObjects\BranchKey;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\WorkflowId;

/**
 * Records a branch decision made during workflow execution.
 */
final readonly class BranchDecisionRecord
{
    /**
     * @param list<BranchKey> $selectedBranches
     * @param class-string<BranchCondition> $conditionClass
     * @param array<string, mixed>|null $inputSummary
     */
    private function __construct(
        public BranchDecisionId $id,
        public WorkflowId $workflowId,
        public StepKey $branchPointKey,
        public string $conditionClass,
        public array $selectedBranches,
        public CarbonImmutable $evaluatedAt,
        public ?array $inputSummary,
        public CarbonImmutable $createdAt,
    ) {}

    /**
     * @param list<BranchKey> $selectedBranches
     * @param class-string<BranchCondition> $conditionClass
     * @param array<string, mixed>|null $inputSummary
     */
    public static function create(
        WorkflowId $workflowId,
        StepKey $stepKey,
        string $conditionClass,
        array $selectedBranches,
        ?array $inputSummary = null,
    ): self {
        $now = CarbonImmutable::now();

        return new self(
            id: BranchDecisionId::generate(),
            workflowId: $workflowId,
            branchPointKey: $stepKey,
            conditionClass: $conditionClass,
            selectedBranches: $selectedBranches,
            evaluatedAt: $now,
            inputSummary: $inputSummary,
            createdAt: $now,
        );
    }

    /**
     * @param list<BranchKey> $selectedBranches
     * @param class-string<BranchCondition> $conditionClass
     * @param array<string, mixed>|null $inputSummary
     */
    public static function reconstitute(
        BranchDecisionId $branchDecisionId,
        WorkflowId $workflowId,
        StepKey $stepKey,
        string $conditionClass,
        array $selectedBranches,
        CarbonImmutable $evaluatedAt,
        ?array $inputSummary,
        CarbonImmutable $createdAt,
    ): self {
        return new self(
            id: $branchDecisionId,
            workflowId: $workflowId,
            branchPointKey: $stepKey,
            conditionClass: $conditionClass,
            selectedBranches: $selectedBranches,
            evaluatedAt: $evaluatedAt,
            inputSummary: $inputSummary,
            createdAt: $createdAt,
        );
    }

    /**
     * Check if a branch was selected in this decision.
     */
    public function wasSelected(BranchKey $branchKey): bool
    {
        return array_any($this->selectedBranches, static fn ($selected) => $selected->equals($branchKey));
    }

    /**
     * Get the branch keys as strings.
     *
     * @return list<string>
     */
    public function selectedBranchKeys(): array
    {
        return array_map(
            static fn (BranchKey $branchKey): string => $branchKey->value,
            $this->selectedBranches,
        );
    }
}
