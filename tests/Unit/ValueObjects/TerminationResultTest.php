<?php

declare(strict_types=1);

use Maestro\Workflow\Enums\WorkflowState;
use Maestro\Workflow\ValueObjects\TerminationResult;

describe('TerminationResult', static function (): void {
    describe('continue', static function (): void {
        it('creates a result indicating continuation', function (): void {
            $terminationResult = TerminationResult::continue();

            expect($terminationResult->shouldContinue())->toBeTrue();
            expect($terminationResult->shouldTerminate())->toBeFalse();
            expect($terminationResult->terminalState())->toBeNull();
            expect($terminationResult->reason())->toBeNull();
        });
    });

    describe('terminate', static function (): void {
        it('creates a result indicating termination with Succeeded state', function (): void {
            $terminationResult = TerminationResult::terminate(
                WorkflowState::Succeeded,
                'All goals achieved',
            );

            expect($terminationResult->shouldTerminate())->toBeTrue();
            expect($terminationResult->shouldContinue())->toBeFalse();
            expect($terminationResult->terminalState())->toBe(WorkflowState::Succeeded);
            expect($terminationResult->reason())->toBe('All goals achieved');
        });

        it('creates a result indicating termination with Failed state', function (): void {
            $terminationResult = TerminationResult::terminate(
                WorkflowState::Failed,
                'Critical error detected',
            );

            expect($terminationResult->shouldTerminate())->toBeTrue();
            expect($terminationResult->shouldContinue())->toBeFalse();
            expect($terminationResult->terminalState())->toBe(WorkflowState::Failed);
            expect($terminationResult->reason())->toBe('Critical error detected');
        });
    });

    describe('shouldTerminate', static function (): void {
        it('returns true only for terminate results', function (): void {
            $terminationResult = TerminationResult::continue();
            $terminateResult = TerminationResult::terminate(WorkflowState::Succeeded, 'Done');

            expect($terminationResult->shouldTerminate())->toBeFalse();
            expect($terminateResult->shouldTerminate())->toBeTrue();
        });
    });

    describe('shouldContinue', static function (): void {
        it('returns true only for continue results', function (): void {
            $terminationResult = TerminationResult::continue();
            $terminateResult = TerminationResult::terminate(WorkflowState::Succeeded, 'Done');

            expect($terminationResult->shouldContinue())->toBeTrue();
            expect($terminateResult->shouldContinue())->toBeFalse();
        });
    });

    it('is readonly', function (): void {
        expect(TerminationResult::class)->toBeImmutable();
    });
});
