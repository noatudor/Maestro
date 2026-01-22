<?php

declare(strict_types=1);

use Maestro\Workflow\Enums\ResolutionDecision;

describe('ResolutionDecision', static function (): void {
    describe('retriesFailedStep', static function (): void {
        it('returns true only for Retry', function (): void {
            expect(ResolutionDecision::Retry->retriesFailedStep())->toBeTrue();
            expect(ResolutionDecision::RetryFromStep->retriesFailedStep())->toBeFalse();
            expect(ResolutionDecision::Compensate->retriesFailedStep())->toBeFalse();
            expect(ResolutionDecision::Cancel->retriesFailedStep())->toBeFalse();
            expect(ResolutionDecision::MarkResolved->retriesFailedStep())->toBeFalse();
        });
    });

    describe('retriesFromSpecificStep', static function (): void {
        it('returns true only for RetryFromStep', function (): void {
            expect(ResolutionDecision::RetryFromStep->retriesFromSpecificStep())->toBeTrue();
            expect(ResolutionDecision::Retry->retriesFromSpecificStep())->toBeFalse();
            expect(ResolutionDecision::Compensate->retriesFromSpecificStep())->toBeFalse();
        });
    });

    describe('triggersCompensation', static function (): void {
        it('returns true only for Compensate', function (): void {
            expect(ResolutionDecision::Compensate->triggersCompensation())->toBeTrue();
            expect(ResolutionDecision::Retry->triggersCompensation())->toBeFalse();
            expect(ResolutionDecision::Cancel->triggersCompensation())->toBeFalse();
        });
    });

    describe('cancelsWorkflow', static function (): void {
        it('returns true only for Cancel', function (): void {
            expect(ResolutionDecision::Cancel->cancelsWorkflow())->toBeTrue();
            expect(ResolutionDecision::Retry->cancelsWorkflow())->toBeFalse();
            expect(ResolutionDecision::MarkResolved->cancelsWorkflow())->toBeFalse();
        });
    });

    describe('marksAsResolved', static function (): void {
        it('returns true only for MarkResolved', function (): void {
            expect(ResolutionDecision::MarkResolved->marksAsResolved())->toBeTrue();
            expect(ResolutionDecision::Cancel->marksAsResolved())->toBeFalse();
            expect(ResolutionDecision::Retry->marksAsResolved())->toBeFalse();
        });
    });

    describe('continuesExecution', static function (): void {
        it('returns true for Retry and RetryFromStep', function (): void {
            expect(ResolutionDecision::Retry->continuesExecution())->toBeTrue();
            expect(ResolutionDecision::RetryFromStep->continuesExecution())->toBeTrue();
            expect(ResolutionDecision::Compensate->continuesExecution())->toBeFalse();
            expect(ResolutionDecision::Cancel->continuesExecution())->toBeFalse();
            expect(ResolutionDecision::MarkResolved->continuesExecution())->toBeFalse();
        });
    });

    describe('isTerminal', static function (): void {
        it('returns true for Cancel and MarkResolved', function (): void {
            expect(ResolutionDecision::Cancel->isTerminal())->toBeTrue();
            expect(ResolutionDecision::MarkResolved->isTerminal())->toBeTrue();
            expect(ResolutionDecision::Retry->isTerminal())->toBeFalse();
            expect(ResolutionDecision::RetryFromStep->isTerminal())->toBeFalse();
            expect(ResolutionDecision::Compensate->isTerminal())->toBeFalse();
        });
    });
});
