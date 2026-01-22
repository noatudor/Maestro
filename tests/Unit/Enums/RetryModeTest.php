<?php

declare(strict_types=1);

use Maestro\Workflow\Enums\RetryMode;

describe('RetryMode', static function (): void {
    describe('values', static function (): void {
        it('has retry_only value', function (): void {
            expect(RetryMode::RetryOnly->value)->toBe('retry_only');
        });

        it('has compensate_then_retry value', function (): void {
            expect(RetryMode::CompensateThenRetry->value)->toBe('compensate_then_retry');
        });
    });

    describe('requiresCompensation', static function (): void {
        it('returns false for RetryOnly', function (): void {
            expect(RetryMode::RetryOnly->requiresCompensation())->toBeFalse();
        });

        it('returns true for CompensateThenRetry', function (): void {
            expect(RetryMode::CompensateThenRetry->requiresCompensation())->toBeTrue();
        });
    });

    describe('skipsCompensation', static function (): void {
        it('returns true for RetryOnly', function (): void {
            expect(RetryMode::RetryOnly->skipsCompensation())->toBeTrue();
        });

        it('returns false for CompensateThenRetry', function (): void {
            expect(RetryMode::CompensateThenRetry->skipsCompensation())->toBeFalse();
        });
    });
});
