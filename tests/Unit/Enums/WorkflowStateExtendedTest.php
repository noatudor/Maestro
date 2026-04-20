<?php

declare(strict_types=1);

use Maestro\Workflow\Enums\WorkflowState;

describe('WorkflowState extended coverage', function () {
    describe('isActive', function () {
        it('returns true for active states', function () {
            expect(WorkflowState::Pending->isActive())->toBeTrue()
                ->and(WorkflowState::Running->isActive())->toBeTrue()
                ->and(WorkflowState::Paused->isActive())->toBeTrue()
                ->and(WorkflowState::Compensating->isActive())->toBeTrue();
        });

        it('returns false for terminal states', function () {
            expect(WorkflowState::Succeeded->isActive())->toBeFalse()
                ->and(WorkflowState::Failed->isActive())->toBeFalse()
                ->and(WorkflowState::Cancelled->isActive())->toBeFalse()
                ->and(WorkflowState::Compensated->isActive())->toBeFalse();
        });

        it('returns false for compensation failed', function () {
            expect(WorkflowState::CompensationFailed->isActive())->toBeFalse();
        });
    });

    describe('isTerminal', function () {
        it('returns true for terminal states', function () {
            expect(WorkflowState::Succeeded->isTerminal())->toBeTrue()
                ->and(WorkflowState::Failed->isTerminal())->toBeTrue()
                ->and(WorkflowState::Cancelled->isTerminal())->toBeTrue()
                ->and(WorkflowState::Compensated->isTerminal())->toBeTrue();
        });

        it('returns false for non-terminal states', function () {
            expect(WorkflowState::Pending->isTerminal())->toBeFalse()
                ->and(WorkflowState::Running->isTerminal())->toBeFalse()
                ->and(WorkflowState::Paused->isTerminal())->toBeFalse()
                ->and(WorkflowState::Compensating->isTerminal())->toBeFalse();
        });
    });

    describe('isCompensating', function () {
        it('returns true for compensating state', function () {
            expect(WorkflowState::Compensating->isCompensating())->toBeTrue();
        });

        it('returns false for other states', function () {
            expect(WorkflowState::Pending->isCompensating())->toBeFalse()
                ->and(WorkflowState::Running->isCompensating())->toBeFalse()
                ->and(WorkflowState::Failed->isCompensating())->toBeFalse();
        });
    });

    describe('isCompensated', function () {
        it('returns true for compensated state', function () {
            expect(WorkflowState::Compensated->isCompensated())->toBeTrue();
        });

        it('returns false for other states', function () {
            expect(WorkflowState::Pending->isCompensated())->toBeFalse()
                ->and(WorkflowState::Compensating->isCompensated())->toBeFalse();
        });
    });

    describe('isCompensationFailed', function () {
        it('returns true for compensation failed state', function () {
            expect(WorkflowState::CompensationFailed->isCompensationFailed())->toBeTrue();
        });

        it('returns false for other states', function () {
            expect(WorkflowState::Pending->isCompensationFailed())->toBeFalse()
                ->and(WorkflowState::Failed->isCompensationFailed())->toBeFalse();
        });
    });

    describe('requiresCompensationHandling', function () {
        it('returns true for compensation states', function () {
            expect(WorkflowState::Compensating->requiresCompensationHandling())->toBeTrue()
                ->and(WorkflowState::CompensationFailed->requiresCompensationHandling())->toBeTrue();
        });

        it('returns false for non-compensation states', function () {
            expect(WorkflowState::Pending->requiresCompensationHandling())->toBeFalse()
                ->and(WorkflowState::Running->requiresCompensationHandling())->toBeFalse()
                ->and(WorkflowState::Compensated->requiresCompensationHandling())->toBeFalse();
        });
    });

    describe('canTransitionTo', function () {
        it('allows pending to running', function () {
            expect(WorkflowState::Pending->canTransitionTo(WorkflowState::Running))->toBeTrue();
        });

        it('allows running to paused', function () {
            expect(WorkflowState::Running->canTransitionTo(WorkflowState::Paused))->toBeTrue();
        });

        it('allows running to succeeded', function () {
            expect(WorkflowState::Running->canTransitionTo(WorkflowState::Succeeded))->toBeTrue();
        });

        it('allows running to failed', function () {
            expect(WorkflowState::Running->canTransitionTo(WorkflowState::Failed))->toBeTrue();
        });

        it('allows running to cancelled', function () {
            expect(WorkflowState::Running->canTransitionTo(WorkflowState::Cancelled))->toBeTrue();
        });

        it('allows running to compensating', function () {
            expect(WorkflowState::Running->canTransitionTo(WorkflowState::Compensating))->toBeTrue();
        });

        it('allows paused to running', function () {
            expect(WorkflowState::Paused->canTransitionTo(WorkflowState::Running))->toBeTrue();
        });

        it('allows paused to cancelled', function () {
            expect(WorkflowState::Paused->canTransitionTo(WorkflowState::Cancelled))->toBeTrue();
        });

        it('allows paused to compensating', function () {
            expect(WorkflowState::Paused->canTransitionTo(WorkflowState::Compensating))->toBeTrue();
        });

        it('allows failed to running', function () {
            expect(WorkflowState::Failed->canTransitionTo(WorkflowState::Running))->toBeTrue();
        });

        it('allows failed to cancelled', function () {
            expect(WorkflowState::Failed->canTransitionTo(WorkflowState::Cancelled))->toBeTrue();
        });

        it('allows failed to compensating', function () {
            expect(WorkflowState::Failed->canTransitionTo(WorkflowState::Compensating))->toBeTrue();
        });

        it('allows compensating to compensated', function () {
            expect(WorkflowState::Compensating->canTransitionTo(WorkflowState::Compensated))->toBeTrue();
        });

        it('allows compensating to compensation failed', function () {
            expect(WorkflowState::Compensating->canTransitionTo(WorkflowState::CompensationFailed))->toBeTrue();
        });

        it('allows compensation failed to compensating', function () {
            expect(WorkflowState::CompensationFailed->canTransitionTo(WorkflowState::Compensating))->toBeTrue();
        });

        it('allows compensation failed to compensated', function () {
            expect(WorkflowState::CompensationFailed->canTransitionTo(WorkflowState::Compensated))->toBeTrue();
        });

        it('disallows succeeded to any state', function () {
            expect(WorkflowState::Succeeded->canTransitionTo(WorkflowState::Running))->toBeFalse()
                ->and(WorkflowState::Succeeded->canTransitionTo(WorkflowState::Failed))->toBeFalse()
                ->and(WorkflowState::Succeeded->canTransitionTo(WorkflowState::Compensating))->toBeFalse();
        });

        it('disallows cancelled to any state', function () {
            expect(WorkflowState::Cancelled->canTransitionTo(WorkflowState::Running))->toBeFalse()
                ->and(WorkflowState::Cancelled->canTransitionTo(WorkflowState::Succeeded))->toBeFalse();
        });

        it('disallows compensated to any state', function () {
            expect(WorkflowState::Compensated->canTransitionTo(WorkflowState::Compensating))->toBeFalse()
                ->and(WorkflowState::Compensated->canTransitionTo(WorkflowState::Running))->toBeFalse();
        });
    });
});
