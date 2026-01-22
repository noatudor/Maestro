<?php

declare(strict_types=1);

use Maestro\Workflow\Enums\FailureResolutionStrategy;

describe('FailureResolutionStrategy', static function (): void {
    describe('awaitsDecision', static function (): void {
        it('returns true only for AwaitDecision', function (): void {
            expect(FailureResolutionStrategy::AwaitDecision->awaitsDecision())->toBeTrue();
            expect(FailureResolutionStrategy::AutoRetry->awaitsDecision())->toBeFalse();
            expect(FailureResolutionStrategy::AutoCompensate->awaitsDecision())->toBeFalse();
        });
    });

    describe('autoRetries', static function (): void {
        it('returns true only for AutoRetry', function (): void {
            expect(FailureResolutionStrategy::AutoRetry->autoRetries())->toBeTrue();
            expect(FailureResolutionStrategy::AwaitDecision->autoRetries())->toBeFalse();
            expect(FailureResolutionStrategy::AutoCompensate->autoRetries())->toBeFalse();
        });
    });

    describe('autoCompensates', static function (): void {
        it('returns true only for AutoCompensate', function (): void {
            expect(FailureResolutionStrategy::AutoCompensate->autoCompensates())->toBeTrue();
            expect(FailureResolutionStrategy::AwaitDecision->autoCompensates())->toBeFalse();
            expect(FailureResolutionStrategy::AutoRetry->autoCompensates())->toBeFalse();
        });
    });

    describe('requiresManualIntervention', static function (): void {
        it('returns true only for AwaitDecision', function (): void {
            expect(FailureResolutionStrategy::AwaitDecision->requiresManualIntervention())->toBeTrue();
            expect(FailureResolutionStrategy::AutoRetry->requiresManualIntervention())->toBeFalse();
            expect(FailureResolutionStrategy::AutoCompensate->requiresManualIntervention())->toBeFalse();
        });
    });
});
