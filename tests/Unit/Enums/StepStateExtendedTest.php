<?php

declare(strict_types=1);

use Maestro\Workflow\Enums\StepState;

describe('StepState extended coverage', function () {
    describe('isTerminal', function () {
        it('returns true for terminal states', function () {
            expect(StepState::Succeeded->isTerminal())->toBeTrue()
                ->and(StepState::Failed->isTerminal())->toBeTrue()
                ->and(StepState::Skipped->isTerminal())->toBeTrue()
                ->and(StepState::TimedOut->isTerminal())->toBeTrue()
                ->and(StepState::Superseded->isTerminal())->toBeTrue();
        });

        it('returns false for non-terminal states', function () {
            expect(StepState::Pending->isTerminal())->toBeFalse()
                ->and(StepState::Running->isTerminal())->toBeFalse()
                ->and(StepState::Polling->isTerminal())->toBeFalse();
        });
    });

    describe('isSuperseded', function () {
        it('returns true for superseded state', function () {
            expect(StepState::Superseded->isSuperseded())->toBeTrue();
        });

        it('returns false for other states', function () {
            expect(StepState::Pending->isSuperseded())->toBeFalse()
                ->and(StepState::Running->isSuperseded())->toBeFalse()
                ->and(StepState::Succeeded->isSuperseded())->toBeFalse();
        });
    });

    describe('isSkipped', function () {
        it('returns true for skipped state', function () {
            expect(StepState::Skipped->isSkipped())->toBeTrue();
        });

        it('returns false for other states', function () {
            expect(StepState::Pending->isSkipped())->toBeFalse()
                ->and(StepState::Running->isSkipped())->toBeFalse();
        });
    });

    describe('isPolling', function () {
        it('returns true for polling state', function () {
            expect(StepState::Polling->isPolling())->toBeTrue();
        });

        it('returns false for other states', function () {
            expect(StepState::Pending->isPolling())->toBeFalse()
                ->and(StepState::Running->isPolling())->toBeFalse();
        });
    });

    describe('isTimedOut', function () {
        it('returns true for timed out state', function () {
            expect(StepState::TimedOut->isTimedOut())->toBeTrue();
        });

        it('returns false for other states', function () {
            expect(StepState::Pending->isTimedOut())->toBeFalse()
                ->and(StepState::Running->isTimedOut())->toBeFalse();
        });
    });

    describe('canTransitionTo', function () {
        it('allows pending to running', function () {
            expect(StepState::Pending->canTransitionTo(StepState::Running))->toBeTrue();
        });

        it('allows pending to polling', function () {
            expect(StepState::Pending->canTransitionTo(StepState::Polling))->toBeTrue();
        });

        it('allows pending to skipped', function () {
            expect(StepState::Pending->canTransitionTo(StepState::Skipped))->toBeTrue();
        });

        it('allows pending to superseded', function () {
            expect(StepState::Pending->canTransitionTo(StepState::Superseded))->toBeTrue();
        });

        it('allows running to succeeded', function () {
            expect(StepState::Running->canTransitionTo(StepState::Succeeded))->toBeTrue();
        });

        it('allows running to failed', function () {
            expect(StepState::Running->canTransitionTo(StepState::Failed))->toBeTrue();
        });

        it('allows running to superseded', function () {
            expect(StepState::Running->canTransitionTo(StepState::Superseded))->toBeTrue();
        });

        it('allows polling to succeeded', function () {
            expect(StepState::Polling->canTransitionTo(StepState::Succeeded))->toBeTrue();
        });

        it('allows polling to failed', function () {
            expect(StepState::Polling->canTransitionTo(StepState::Failed))->toBeTrue();
        });

        it('allows polling to timed out', function () {
            expect(StepState::Polling->canTransitionTo(StepState::TimedOut))->toBeTrue();
        });

        it('allows polling to running', function () {
            expect(StepState::Polling->canTransitionTo(StepState::Running))->toBeTrue();
        });

        it('allows polling to superseded', function () {
            expect(StepState::Polling->canTransitionTo(StepState::Superseded))->toBeTrue();
        });

        it('allows succeeded to superseded', function () {
            expect(StepState::Succeeded->canTransitionTo(StepState::Superseded))->toBeTrue();
        });

        it('allows failed to superseded', function () {
            expect(StepState::Failed->canTransitionTo(StepState::Superseded))->toBeTrue();
        });

        it('allows timed out to superseded', function () {
            expect(StepState::TimedOut->canTransitionTo(StepState::Superseded))->toBeTrue();
        });

        it('disallows superseded to any state', function () {
            expect(StepState::Superseded->canTransitionTo(StepState::Running))->toBeFalse()
                ->and(StepState::Superseded->canTransitionTo(StepState::Succeeded))->toBeFalse();
        });

        it('disallows skipped to any state', function () {
            expect(StepState::Skipped->canTransitionTo(StepState::Running))->toBeFalse()
                ->and(StepState::Skipped->canTransitionTo(StepState::Succeeded))->toBeFalse();
        });
    });
});
