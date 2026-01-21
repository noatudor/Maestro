<?php

declare(strict_types=1);

use Maestro\Workflow\Enums\FailurePolicy;

describe('FailurePolicy', static function (): void {
    describe('shouldFailWorkflow', static function (): void {
        it('returns true only for FailWorkflow', function (): void {
            expect(FailurePolicy::FailWorkflow->shouldFailWorkflow())->toBeTrue();
            expect(FailurePolicy::PauseWorkflow->shouldFailWorkflow())->toBeFalse();
            expect(FailurePolicy::RetryStep->shouldFailWorkflow())->toBeFalse();
            expect(FailurePolicy::SkipStep->shouldFailWorkflow())->toBeFalse();
            expect(FailurePolicy::ContinueWithPartial->shouldFailWorkflow())->toBeFalse();
        });
    });

    describe('shouldPauseWorkflow', static function (): void {
        it('returns true only for PauseWorkflow', function (): void {
            expect(FailurePolicy::PauseWorkflow->shouldPauseWorkflow())->toBeTrue();
            expect(FailurePolicy::FailWorkflow->shouldPauseWorkflow())->toBeFalse();
            expect(FailurePolicy::RetryStep->shouldPauseWorkflow())->toBeFalse();
        });
    });

    describe('shouldRetryStep', static function (): void {
        it('returns true only for RetryStep', function (): void {
            expect(FailurePolicy::RetryStep->shouldRetryStep())->toBeTrue();
            expect(FailurePolicy::FailWorkflow->shouldRetryStep())->toBeFalse();
            expect(FailurePolicy::SkipStep->shouldRetryStep())->toBeFalse();
        });
    });

    describe('shouldSkipStep', static function (): void {
        it('returns true only for SkipStep', function (): void {
            expect(FailurePolicy::SkipStep->shouldSkipStep())->toBeTrue();
            expect(FailurePolicy::FailWorkflow->shouldSkipStep())->toBeFalse();
            expect(FailurePolicy::RetryStep->shouldSkipStep())->toBeFalse();
        });
    });

    describe('allowsPartialSuccess', static function (): void {
        it('returns true only for ContinueWithPartial', function (): void {
            expect(FailurePolicy::ContinueWithPartial->allowsPartialSuccess())->toBeTrue();
            expect(FailurePolicy::FailWorkflow->allowsPartialSuccess())->toBeFalse();
            expect(FailurePolicy::RetryStep->allowsPartialSuccess())->toBeFalse();
        });
    });
});
