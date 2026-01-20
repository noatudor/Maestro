<?php

declare(strict_types=1);

use Maestro\Workflow\Enums\WorkflowState;

describe('WorkflowState', static function (): void {
    describe('isTerminal', static function (): void {
        it('returns true for terminal states', function (WorkflowState $workflowState): void {
            expect($workflowState->isTerminal())->toBeTrue();
        })->with([
            'succeeded' => WorkflowState::Succeeded,
            'failed' => WorkflowState::Failed,
            'cancelled' => WorkflowState::Cancelled,
        ]);

        it('returns false for non-terminal states', function (WorkflowState $workflowState): void {
            expect($workflowState->isTerminal())->toBeFalse();
        })->with([
            'pending' => WorkflowState::Pending,
            'running' => WorkflowState::Running,
            'paused' => WorkflowState::Paused,
        ]);
    });

    describe('isActive', static function (): void {
        it('returns true for active states', function (WorkflowState $workflowState): void {
            expect($workflowState->isActive())->toBeTrue();
        })->with([
            'pending' => WorkflowState::Pending,
            'running' => WorkflowState::Running,
            'paused' => WorkflowState::Paused,
        ]);

        it('returns false for inactive states', function (WorkflowState $workflowState): void {
            expect($workflowState->isActive())->toBeFalse();
        })->with([
            'succeeded' => WorkflowState::Succeeded,
            'failed' => WorkflowState::Failed,
            'cancelled' => WorkflowState::Cancelled,
        ]);
    });

    describe('canTransitionTo', static function (): void {
        it('allows pending to running', function (): void {
            expect(WorkflowState::Pending->canTransitionTo(WorkflowState::Running))->toBeTrue();
        });

        it('denies pending to other states', function (WorkflowState $workflowState): void {
            expect(WorkflowState::Pending->canTransitionTo($workflowState))->toBeFalse();
        })->with([
            'paused' => WorkflowState::Paused,
            'succeeded' => WorkflowState::Succeeded,
            'failed' => WorkflowState::Failed,
            'cancelled' => WorkflowState::Cancelled,
        ]);

        it('allows running to valid transitions', function (WorkflowState $workflowState): void {
            expect(WorkflowState::Running->canTransitionTo($workflowState))->toBeTrue();
        })->with([
            'paused' => WorkflowState::Paused,
            'succeeded' => WorkflowState::Succeeded,
            'failed' => WorkflowState::Failed,
        ]);

        it('allows paused to running or cancelled', function (WorkflowState $workflowState): void {
            expect(WorkflowState::Paused->canTransitionTo($workflowState))->toBeTrue();
        })->with([
            'running' => WorkflowState::Running,
            'cancelled' => WorkflowState::Cancelled,
        ]);

        it('allows failed to running (retry) or cancelled', function (WorkflowState $workflowState): void {
            expect(WorkflowState::Failed->canTransitionTo($workflowState))->toBeTrue();
        })->with([
            'running' => WorkflowState::Running,
            'cancelled' => WorkflowState::Cancelled,
        ]);

        it('denies any transition from succeeded', function (WorkflowState $workflowState): void {
            expect(WorkflowState::Succeeded->canTransitionTo($workflowState))->toBeFalse();
        })->with([
            'pending' => WorkflowState::Pending,
            'running' => WorkflowState::Running,
            'paused' => WorkflowState::Paused,
            'failed' => WorkflowState::Failed,
            'cancelled' => WorkflowState::Cancelled,
        ]);

        it('denies any transition from cancelled', function (WorkflowState $workflowState): void {
            expect(WorkflowState::Cancelled->canTransitionTo($workflowState))->toBeFalse();
        })->with([
            'pending' => WorkflowState::Pending,
            'running' => WorkflowState::Running,
            'paused' => WorkflowState::Paused,
            'succeeded' => WorkflowState::Succeeded,
            'failed' => WorkflowState::Failed,
        ]);
    });
});
