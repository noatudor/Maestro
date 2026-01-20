<?php

declare(strict_types=1);

use Maestro\Workflow\Enums\WorkflowState;

describe('WorkflowState', function () {
    describe('isTerminal', function () {
        it('returns true for terminal states', function (WorkflowState $state) {
            expect($state->isTerminal())->toBeTrue();
        })->with([
            'succeeded' => WorkflowState::Succeeded,
            'failed' => WorkflowState::Failed,
            'cancelled' => WorkflowState::Cancelled,
        ]);

        it('returns false for non-terminal states', function (WorkflowState $state) {
            expect($state->isTerminal())->toBeFalse();
        })->with([
            'pending' => WorkflowState::Pending,
            'running' => WorkflowState::Running,
            'paused' => WorkflowState::Paused,
        ]);
    });

    describe('isActive', function () {
        it('returns true for active states', function (WorkflowState $state) {
            expect($state->isActive())->toBeTrue();
        })->with([
            'pending' => WorkflowState::Pending,
            'running' => WorkflowState::Running,
            'paused' => WorkflowState::Paused,
        ]);

        it('returns false for inactive states', function (WorkflowState $state) {
            expect($state->isActive())->toBeFalse();
        })->with([
            'succeeded' => WorkflowState::Succeeded,
            'failed' => WorkflowState::Failed,
            'cancelled' => WorkflowState::Cancelled,
        ]);
    });

    describe('canTransitionTo', function () {
        it('allows pending to running', function () {
            expect(WorkflowState::Pending->canTransitionTo(WorkflowState::Running))->toBeTrue();
        });

        it('denies pending to other states', function (WorkflowState $target) {
            expect(WorkflowState::Pending->canTransitionTo($target))->toBeFalse();
        })->with([
            'paused' => WorkflowState::Paused,
            'succeeded' => WorkflowState::Succeeded,
            'failed' => WorkflowState::Failed,
            'cancelled' => WorkflowState::Cancelled,
        ]);

        it('allows running to valid transitions', function (WorkflowState $target) {
            expect(WorkflowState::Running->canTransitionTo($target))->toBeTrue();
        })->with([
            'paused' => WorkflowState::Paused,
            'succeeded' => WorkflowState::Succeeded,
            'failed' => WorkflowState::Failed,
        ]);

        it('allows paused to running or cancelled', function (WorkflowState $target) {
            expect(WorkflowState::Paused->canTransitionTo($target))->toBeTrue();
        })->with([
            'running' => WorkflowState::Running,
            'cancelled' => WorkflowState::Cancelled,
        ]);

        it('allows failed to running (retry) or cancelled', function (WorkflowState $target) {
            expect(WorkflowState::Failed->canTransitionTo($target))->toBeTrue();
        })->with([
            'running' => WorkflowState::Running,
            'cancelled' => WorkflowState::Cancelled,
        ]);

        it('denies any transition from succeeded', function (WorkflowState $target) {
            expect(WorkflowState::Succeeded->canTransitionTo($target))->toBeFalse();
        })->with([
            'pending' => WorkflowState::Pending,
            'running' => WorkflowState::Running,
            'paused' => WorkflowState::Paused,
            'failed' => WorkflowState::Failed,
            'cancelled' => WorkflowState::Cancelled,
        ]);

        it('denies any transition from cancelled', function (WorkflowState $target) {
            expect(WorkflowState::Cancelled->canTransitionTo($target))->toBeFalse();
        })->with([
            'pending' => WorkflowState::Pending,
            'running' => WorkflowState::Running,
            'paused' => WorkflowState::Paused,
            'succeeded' => WorkflowState::Succeeded,
            'failed' => WorkflowState::Failed,
        ]);
    });
});
