<?php

declare(strict_types=1);

use Maestro\Workflow\Definition\Config\BranchDefinition;
use Maestro\Workflow\Enums\BranchType;
use Maestro\Workflow\ValueObjects\BranchKey;
use Maestro\Workflow\ValueObjects\StepKey;

describe('BranchDefinition', function (): void {
    beforeEach(function (): void {
        $this->conditionClass = 'TestBranchCondition';
        $this->successStep = StepKey::fromString('success-step');
        $this->failureStep = StepKey::fromString('failure-step');
        $this->convergenceStep = StepKey::fromString('convergence-step');
    });

    describe('create', function (): void {
        it('creates a branch definition with exclusive type', function (): void {
            $branches = [
                'success' => [$this->successStep],
                'failure' => [$this->failureStep],
            ];

            $branchDefinition = BranchDefinition::create(
                $this->conditionClass,
                BranchType::Exclusive,
                $branches,
            );

            expect($branchDefinition->conditionClass())->toBe($this->conditionClass);
            expect($branchDefinition->branchType())->toBe(BranchType::Exclusive);
            expect($branchDefinition->branches())->toBe($branches);
            expect($branchDefinition->convergenceStepKey())->toBeNull();
            expect($branchDefinition->defaultBranchKey())->toBeNull();
        });

        it('creates a branch definition with inclusive type', function (): void {
            $branches = [
                'branch-a' => [$this->successStep],
                'branch-b' => [$this->failureStep],
            ];

            $branchDefinition = BranchDefinition::create(
                $this->conditionClass,
                BranchType::Inclusive,
                $branches,
            );

            expect($branchDefinition->branchType())->toBe(BranchType::Inclusive);
        });

        it('creates with convergence step', function (): void {
            $branches = [
                'success' => [$this->successStep],
            ];

            $branchDefinition = BranchDefinition::create(
                $this->conditionClass,
                BranchType::Exclusive,
                $branches,
                $this->convergenceStep,
            );

            expect($branchDefinition->convergenceStepKey())->toBe($this->convergenceStep);
            expect($branchDefinition->hasConvergence())->toBeTrue();
        });

        it('creates with default branch', function (): void {
            $branchKey = BranchKey::fromString('success');
            $branches = [
                'success' => [$this->successStep],
                'failure' => [$this->failureStep],
            ];

            $branchDefinition = BranchDefinition::create(
                $this->conditionClass,
                BranchType::Exclusive,
                $branches,
                null,
                $branchKey,
            );

            expect($branchDefinition->defaultBranchKey())->toBe($branchKey);
        });
    });

    describe('branchKeys', function (): void {
        it('returns all branch keys', function (): void {
            $branches = [
                'success' => [$this->successStep],
                'failure' => [$this->failureStep],
            ];

            $branchDefinition = BranchDefinition::create(
                $this->conditionClass,
                BranchType::Exclusive,
                $branches,
            );

            expect($branchDefinition->branchKeys())->toBe(['success', 'failure']);
        });
    });

    describe('stepsForBranch', function (): void {
        it('returns steps for existing branch', function (): void {
            $branches = [
                'success' => [$this->successStep],
                'failure' => [$this->failureStep],
            ];

            $branchDefinition = BranchDefinition::create(
                $this->conditionClass,
                BranchType::Exclusive,
                $branches,
            );

            $branchKey = BranchKey::fromString('success');
            expect($branchDefinition->stepsForBranch($branchKey))->toBe([$this->successStep]);
        });

        it('returns empty array for non-existent branch', function (): void {
            $branches = [
                'success' => [$this->successStep],
            ];

            $branchDefinition = BranchDefinition::create(
                $this->conditionClass,
                BranchType::Exclusive,
                $branches,
            );

            $branchKey = BranchKey::fromString('unknown');
            expect($branchDefinition->stepsForBranch($branchKey))->toBe([]);
        });
    });

    describe('isStepOnBranch', function (): void {
        it('returns true when step is on branch', function (): void {
            $branches = [
                'success' => [$this->successStep],
                'failure' => [$this->failureStep],
            ];

            $branchDefinition = BranchDefinition::create(
                $this->conditionClass,
                BranchType::Exclusive,
                $branches,
            );

            $branchKey = BranchKey::fromString('success');
            expect($branchDefinition->isStepOnBranch($this->successStep, $branchKey))->toBeTrue();
        });

        it('returns false when step is not on branch', function (): void {
            $branches = [
                'success' => [$this->successStep],
                'failure' => [$this->failureStep],
            ];

            $branchDefinition = BranchDefinition::create(
                $this->conditionClass,
                BranchType::Exclusive,
                $branches,
            );

            $branchKey = BranchKey::fromString('success');
            expect($branchDefinition->isStepOnBranch($this->failureStep, $branchKey))->toBeFalse();
        });
    });

    describe('hasConvergence', function (): void {
        it('returns true when convergence step is set', function (): void {
            $branchDefinition = BranchDefinition::create(
                $this->conditionClass,
                BranchType::Exclusive,
                ['success' => [$this->successStep]],
                $this->convergenceStep,
            );

            expect($branchDefinition->hasConvergence())->toBeTrue();
        });

        it('returns false when no convergence step', function (): void {
            $branchDefinition = BranchDefinition::create(
                $this->conditionClass,
                BranchType::Exclusive,
                ['success' => [$this->successStep]],
            );

            expect($branchDefinition->hasConvergence())->toBeFalse();
        });
    });

    describe('hasBranch', function (): void {
        it('returns true for existing branch', function (): void {
            $branches = [
                'success' => [$this->successStep],
            ];

            $branchDefinition = BranchDefinition::create(
                $this->conditionClass,
                BranchType::Exclusive,
                $branches,
            );

            $branchKey = BranchKey::fromString('success');
            expect($branchDefinition->hasBranch($branchKey))->toBeTrue();
        });

        it('returns false for non-existent branch', function (): void {
            $branches = [
                'success' => [$this->successStep],
            ];

            $branchDefinition = BranchDefinition::create(
                $this->conditionClass,
                BranchType::Exclusive,
                $branches,
            );

            $branchKey = BranchKey::fromString('unknown');
            expect($branchDefinition->hasBranch($branchKey))->toBeFalse();
        });
    });

    it('is readonly', function (): void {
        expect(BranchDefinition::class)->toBeImmutable();
    });
});
