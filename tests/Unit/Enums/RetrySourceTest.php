<?php

declare(strict_types=1);

use Maestro\Workflow\Enums\RetrySource;

describe('RetrySource', static function (): void {
    describe('values', static function (): void {
        it('has failed_retry value', function (): void {
            expect(RetrySource::FailedRetry->value)->toBe('failed_retry');
        });

        it('has retry_from_step value', function (): void {
            expect(RetrySource::RetryFromStep->value)->toBe('retry_from_step');
        });

        it('has auto_retry value', function (): void {
            expect(RetrySource::AutoRetry->value)->toBe('auto_retry');
        });
    });

    describe('isManual', static function (): void {
        it('returns true for FailedRetry', function (): void {
            expect(RetrySource::FailedRetry->isManual())->toBeTrue();
        });

        it('returns true for RetryFromStep', function (): void {
            expect(RetrySource::RetryFromStep->isManual())->toBeTrue();
        });

        it('returns false for AutoRetry', function (): void {
            expect(RetrySource::AutoRetry->isManual())->toBeFalse();
        });
    });

    describe('isAutomatic', static function (): void {
        it('returns false for FailedRetry', function (): void {
            expect(RetrySource::FailedRetry->isAutomatic())->toBeFalse();
        });

        it('returns false for RetryFromStep', function (): void {
            expect(RetrySource::RetryFromStep->isAutomatic())->toBeFalse();
        });

        it('returns true for AutoRetry', function (): void {
            expect(RetrySource::AutoRetry->isAutomatic())->toBeTrue();
        });
    });

    describe('isFromEarlierStep', static function (): void {
        it('returns false for FailedRetry', function (): void {
            expect(RetrySource::FailedRetry->isFromEarlierStep())->toBeFalse();
        });

        it('returns true for RetryFromStep', function (): void {
            expect(RetrySource::RetryFromStep->isFromEarlierStep())->toBeTrue();
        });

        it('returns false for AutoRetry', function (): void {
            expect(RetrySource::AutoRetry->isFromEarlierStep())->toBeFalse();
        });
    });
});
