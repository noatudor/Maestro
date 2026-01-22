<?php

declare(strict_types=1);

use Maestro\Workflow\Definition\Config\AutoRetryConfig;
use Maestro\Workflow\Enums\FailureResolutionStrategy;

describe('AutoRetryConfig', static function (): void {
    describe('create', static function (): void {
        it('creates config with specified values', function (): void {
            $autoRetryConfig = AutoRetryConfig::create(
                maxRetries: 5,
                delaySeconds: 120,
                backoffMultiplier: 3.0,
                maxDelaySeconds: 7200,
                failureResolutionStrategy: FailureResolutionStrategy::AutoCompensate,
            );

            expect($autoRetryConfig->maxRetries)->toBe(5);
            expect($autoRetryConfig->delaySeconds)->toBe(120);
            expect($autoRetryConfig->backoffMultiplier)->toBe(3.0);
            expect($autoRetryConfig->maxDelaySeconds)->toBe(7200);
            expect($autoRetryConfig->fallbackStrategy)->toBe(FailureResolutionStrategy::AutoCompensate);
        });

        it('enforces minimum values', function (): void {
            $autoRetryConfig = AutoRetryConfig::create(
                maxRetries: 0,
                delaySeconds: -10,
                backoffMultiplier: 0.5,
                maxDelaySeconds: -100,
            );

            expect($autoRetryConfig->maxRetries)->toBe(1);
            expect($autoRetryConfig->delaySeconds)->toBe(0);
            expect($autoRetryConfig->backoffMultiplier)->toBe(1.0);
            expect($autoRetryConfig->maxDelaySeconds)->toBe(0);
        });
    });

    describe('default', static function (): void {
        it('creates config with sensible defaults', function (): void {
            $autoRetryConfig = AutoRetryConfig::default();

            expect($autoRetryConfig->maxRetries)->toBe(3);
            expect($autoRetryConfig->delaySeconds)->toBe(60);
            expect($autoRetryConfig->backoffMultiplier)->toBe(2.0);
            expect($autoRetryConfig->maxDelaySeconds)->toBe(3600);
            expect($autoRetryConfig->fallbackStrategy)->toBe(FailureResolutionStrategy::AwaitDecision);
        });
    });

    describe('disabled', static function (): void {
        it('creates config with zero retries', function (): void {
            $autoRetryConfig = AutoRetryConfig::disabled();

            expect($autoRetryConfig->maxRetries)->toBe(0);
            expect($autoRetryConfig->isEnabled())->toBeFalse();
        });
    });

    describe('isEnabled', static function (): void {
        it('returns true when maxRetries greater than 0', function (): void {
            $autoRetryConfig = AutoRetryConfig::create(maxRetries: 3);
            expect($autoRetryConfig->isEnabled())->toBeTrue();
        });

        it('returns false when maxRetries is 0', function (): void {
            $autoRetryConfig = AutoRetryConfig::disabled();
            expect($autoRetryConfig->isEnabled())->toBeFalse();
        });
    });

    describe('hasReachedMaxRetries', static function (): void {
        it('returns true when current count equals max', function (): void {
            $autoRetryConfig = AutoRetryConfig::create(maxRetries: 3);
            expect($autoRetryConfig->hasReachedMaxRetries(3))->toBeTrue();
        });

        it('returns true when current count exceeds max', function (): void {
            $autoRetryConfig = AutoRetryConfig::create(maxRetries: 3);
            expect($autoRetryConfig->hasReachedMaxRetries(5))->toBeTrue();
        });

        it('returns false when current count is less than max', function (): void {
            $autoRetryConfig = AutoRetryConfig::create(maxRetries: 3);
            expect($autoRetryConfig->hasReachedMaxRetries(2))->toBeFalse();
        });
    });

    describe('getDelayForRetry', static function (): void {
        it('returns base delay for first retry', function (): void {
            $autoRetryConfig = AutoRetryConfig::create(delaySeconds: 60, backoffMultiplier: 2.0);
            expect($autoRetryConfig->getDelayForRetry(1))->toBe(60);
        });

        it('applies backoff multiplier for subsequent retries', function (): void {
            $autoRetryConfig = AutoRetryConfig::create(delaySeconds: 60, backoffMultiplier: 2.0);
            expect($autoRetryConfig->getDelayForRetry(2))->toBe(120);
            expect($autoRetryConfig->getDelayForRetry(3))->toBe(240);
        });

        it('caps delay at maxDelaySeconds', function (): void {
            $autoRetryConfig = AutoRetryConfig::create(
                delaySeconds: 60,
                backoffMultiplier: 2.0,
                maxDelaySeconds: 180,
            );
            expect($autoRetryConfig->getDelayForRetry(5))->toBe(180);
        });
    });

    describe('equals', static function (): void {
        it('returns true for equal configs', function (): void {
            $autoRetryConfig = AutoRetryConfig::create(maxRetries: 3, delaySeconds: 60);
            $config2 = AutoRetryConfig::create(maxRetries: 3, delaySeconds: 60);

            expect($autoRetryConfig->equals($config2))->toBeTrue();
        });

        it('returns false for different configs', function (): void {
            $autoRetryConfig = AutoRetryConfig::create(maxRetries: 3);
            $config2 = AutoRetryConfig::create(maxRetries: 5);

            expect($autoRetryConfig->equals($config2))->toBeFalse();
        });
    });
});
