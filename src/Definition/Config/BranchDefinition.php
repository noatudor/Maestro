<?php

declare(strict_types=1);

namespace Maestro\Workflow\Definition\Config;

use Maestro\Workflow\Contracts\BranchCondition;
use Maestro\Workflow\Enums\BranchType;
use Maestro\Workflow\ValueObjects\BranchKey;
use Maestro\Workflow\ValueObjects\StepKey;

/**
 * Defines a branch point in a workflow.
 *
 * A branch point splits the workflow into multiple possible paths.
 * The condition determines which branches are followed at runtime.
 */
final readonly class BranchDefinition
{
    /**
     * @param class-string<BranchCondition> $conditionClass
     * @param array<string, list<StepKey>> $branches Map of branch key to steps on that branch
     */
    private function __construct(
        private string $conditionClass,
        private BranchType $branchType,
        private array $branches,
        private ?StepKey $convergenceStepKey,
        private ?BranchKey $defaultBranchKey,
    ) {}

    /**
     * @param class-string<BranchCondition> $conditionClass
     * @param array<string, list<StepKey>> $branches Map of branch key to steps on that branch
     */
    public static function create(
        string $conditionClass,
        BranchType $branchType,
        array $branches,
        ?StepKey $convergenceStepKey = null,
        ?BranchKey $defaultBranchKey = null,
    ): self {
        return new self(
            $conditionClass,
            $branchType,
            $branches,
            $convergenceStepKey,
            $defaultBranchKey,
        );
    }

    /**
     * @return class-string<BranchCondition>
     */
    public function conditionClass(): string
    {
        return $this->conditionClass;
    }

    public function branchType(): BranchType
    {
        return $this->branchType;
    }

    /**
     * @return array<string, list<StepKey>>
     */
    public function branches(): array
    {
        return $this->branches;
    }

    /**
     * @return list<string>
     */
    public function branchKeys(): array
    {
        return array_keys($this->branches);
    }

    /**
     * Get the steps for a specific branch.
     *
     * @return list<StepKey>
     */
    public function stepsForBranch(BranchKey $branchKey): array
    {
        return $this->branches[$branchKey->value] ?? [];
    }

    /**
     * Check if a step is on a specific branch.
     */
    public function isStepOnBranch(StepKey $stepKey, BranchKey $branchKey): bool
    {
        $steps = $this->stepsForBranch($branchKey);

        return array_any($steps, static fn ($step) => $step->equals($stepKey));
    }

    /**
     * Get the convergence step where all branches meet.
     */
    public function convergenceStepKey(): ?StepKey
    {
        return $this->convergenceStepKey;
    }

    /**
     * Get the default branch to take if no condition matches.
     */
    public function defaultBranchKey(): ?BranchKey
    {
        return $this->defaultBranchKey;
    }

    /**
     * Check if this branch definition has a convergence point.
     */
    public function hasConvergence(): bool
    {
        return $this->convergenceStepKey instanceof StepKey;
    }

    /**
     * Check if a branch key exists in this definition.
     */
    public function hasBranch(BranchKey $branchKey): bool
    {
        return isset($this->branches[$branchKey->value]);
    }
}
