<?php

declare(strict_types=1);

use Maestro\Workflow\Enums\StepState;

describe('StepState', static function (): void {
    describe('isTerminal', static function (): void {
        it('returns true for terminal states', function (StepState $stepState): void {
            expect($stepState->isTerminal())->toBeTrue();
        })->with([
            'succeeded' => StepState::Succeeded,
            'failed' => StepState::Failed,
        ]);

        it('returns false for non-terminal states', function (StepState $stepState): void {
            expect($stepState->isTerminal())->toBeFalse();
        })->with([
            'pending' => StepState::Pending,
            'running' => StepState::Running,
        ]);
    });

    describe('canTransitionTo', static function (): void {
        it('allows pending to running', function (): void {
            expect(StepState::Pending->canTransitionTo(StepState::Running))->toBeTrue();
        });

        it('denies pending to other states', function (StepState $stepState): void {
            expect(StepState::Pending->canTransitionTo($stepState))->toBeFalse();
        })->with([
            'succeeded' => StepState::Succeeded,
            'failed' => StepState::Failed,
        ]);

        it('allows running to terminal states', function (StepState $stepState): void {
            expect(StepState::Running->canTransitionTo($stepState))->toBeTrue();
        })->with([
            'succeeded' => StepState::Succeeded,
            'failed' => StepState::Failed,
        ]);

        it('denies running to pending', function (): void {
            expect(StepState::Running->canTransitionTo(StepState::Pending))->toBeFalse();
        });

        it('denies any transition from succeeded', function (StepState $stepState): void {
            expect(StepState::Succeeded->canTransitionTo($stepState))->toBeFalse();
        })->with([
            'pending' => StepState::Pending,
            'running' => StepState::Running,
            'failed' => StepState::Failed,
        ]);

        it('denies any transition from failed', function (StepState $stepState): void {
            expect(StepState::Failed->canTransitionTo($stepState))->toBeFalse();
        })->with([
            'pending' => StepState::Pending,
            'running' => StepState::Running,
            'succeeded' => StepState::Succeeded,
        ]);
    });
});
