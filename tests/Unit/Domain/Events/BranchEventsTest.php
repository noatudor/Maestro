<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Maestro\Workflow\Domain\Events\BranchEvaluated;
use Maestro\Workflow\Domain\Events\StepSkipped;
use Maestro\Workflow\Domain\Events\WorkflowTerminatedEarly;
use Maestro\Workflow\Enums\SkipReason;
use Maestro\Workflow\Enums\WorkflowState;
use Maestro\Workflow\ValueObjects\BranchDecisionId;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\StepRunId;
use Maestro\Workflow\ValueObjects\WorkflowId;

describe('BranchEvaluated', static function (): void {
    it('creates event with all properties', function (): void {
        $workflowId = WorkflowId::generate();
        $branchDecisionId = BranchDecisionId::generate();
        $stepKey = StepKey::fromString('branch-point');
        $conditionClass = 'App\\Conditions\\MyBranchCondition';
        $selectedBranches = ['success', 'notification'];
        $occurredAt = CarbonImmutable::now();

        $event = new BranchEvaluated(
            $workflowId,
            $branchDecisionId,
            $stepKey,
            $conditionClass,
            $selectedBranches,
            $occurredAt,
        );

        expect($event->workflowId)->toBe($workflowId);
        expect($event->branchDecisionId)->toBe($branchDecisionId);
        expect($event->branchPointKey)->toBe($stepKey);
        expect($event->conditionClass)->toBe($conditionClass);
        expect($event->selectedBranches)->toBe($selectedBranches);
        expect($event->occurredAt)->toBe($occurredAt);
    });

    it('is readonly', function (): void {
        expect(BranchEvaluated::class)->toBeImmutable();
    });
});

describe('StepSkipped', static function (): void {
    it('creates event with all properties', function (): void {
        $workflowId = WorkflowId::generate();
        $stepRunId = StepRunId::generate();
        $stepKey = StepKey::fromString('skipped-step');
        $reason = SkipReason::ConditionFalse;
        $message = 'Condition evaluated to false';
        $occurredAt = CarbonImmutable::now();

        $event = new StepSkipped(
            $workflowId,
            $stepRunId,
            $stepKey,
            $reason,
            $message,
            $occurredAt,
        );

        expect($event->workflowId)->toBe($workflowId);
        expect($event->stepRunId)->toBe($stepRunId);
        expect($event->stepKey)->toBe($stepKey);
        expect($event->reason)->toBe($reason);
        expect($event->message)->toBe($message);
        expect($event->occurredAt)->toBe($occurredAt);
    });

    it('can have null message', function (): void {
        $event = new StepSkipped(
            WorkflowId::generate(),
            StepRunId::generate(),
            StepKey::fromString('skipped-step'),
            SkipReason::NotOnActiveBranch,
            null,
            CarbonImmutable::now(),
        );

        expect($event->message)->toBeNull();
    });

    it('is readonly', function (): void {
        expect(StepSkipped::class)->toBeImmutable();
    });
});

describe('WorkflowTerminatedEarly', static function (): void {
    it('creates event with Succeeded state', function (): void {
        $workflowId = WorkflowId::generate();
        $lastStepKey = StepKey::fromString('last-step');
        $conditionClass = 'App\\Conditions\\EarlySuccessCondition';
        $terminalState = WorkflowState::Succeeded;
        $reason = 'All goals achieved early';
        $occurredAt = CarbonImmutable::now();

        $event = new WorkflowTerminatedEarly(
            $workflowId,
            $lastStepKey,
            $conditionClass,
            $terminalState,
            $reason,
            $occurredAt,
        );

        expect($event->workflowId)->toBe($workflowId);
        expect($event->lastStepKey)->toBe($lastStepKey);
        expect($event->conditionClass)->toBe($conditionClass);
        expect($event->terminalState)->toBe($terminalState);
        expect($event->reason)->toBe($reason);
        expect($event->occurredAt)->toBe($occurredAt);
    });

    it('creates event with Failed state', function (): void {
        $event = new WorkflowTerminatedEarly(
            WorkflowId::generate(),
            StepKey::fromString('error-step'),
            'App\\Conditions\\CriticalErrorCondition',
            WorkflowState::Failed,
            'Critical error detected',
            CarbonImmutable::now(),
        );

        expect($event->terminalState)->toBe(WorkflowState::Failed);
    });

    it('is readonly', function (): void {
        expect(WorkflowTerminatedEarly::class)->toBeImmutable();
    });
});
