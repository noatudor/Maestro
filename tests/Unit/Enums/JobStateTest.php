<?php

declare(strict_types=1);

use Maestro\Workflow\Enums\JobState;

describe('JobState', static function (): void {
    describe('isTerminal', static function (): void {
        it('returns true for terminal states', function (JobState $jobState): void {
            expect($jobState->isTerminal())->toBeTrue();
        })->with([
            'succeeded' => JobState::Succeeded,
            'failed' => JobState::Failed,
        ]);

        it('returns false for non-terminal states', function (JobState $jobState): void {
            expect($jobState->isTerminal())->toBeFalse();
        })->with([
            'dispatched' => JobState::Dispatched,
            'running' => JobState::Running,
        ]);
    });

    describe('canTransitionTo', static function (): void {
        it('allows dispatched to running', function (): void {
            expect(JobState::Dispatched->canTransitionTo(JobState::Running))->toBeTrue();
        });

        it('allows dispatched to failed for stale job detection', function (): void {
            expect(JobState::Dispatched->canTransitionTo(JobState::Failed))->toBeTrue();
        });

        it('denies dispatched to succeeded', function (): void {
            expect(JobState::Dispatched->canTransitionTo(JobState::Succeeded))->toBeFalse();
        });

        it('allows running to terminal states', function (JobState $jobState): void {
            expect(JobState::Running->canTransitionTo($jobState))->toBeTrue();
        })->with([
            'succeeded' => JobState::Succeeded,
            'failed' => JobState::Failed,
        ]);

        it('denies running to dispatched', function (): void {
            expect(JobState::Running->canTransitionTo(JobState::Dispatched))->toBeFalse();
        });

        it('denies any transition from succeeded', function (JobState $jobState): void {
            expect(JobState::Succeeded->canTransitionTo($jobState))->toBeFalse();
        })->with([
            'dispatched' => JobState::Dispatched,
            'running' => JobState::Running,
            'failed' => JobState::Failed,
        ]);

        it('denies any transition from failed', function (JobState $jobState): void {
            expect(JobState::Failed->canTransitionTo($jobState))->toBeFalse();
        })->with([
            'dispatched' => JobState::Dispatched,
            'running' => JobState::Running,
            'succeeded' => JobState::Succeeded,
        ]);
    });
});
