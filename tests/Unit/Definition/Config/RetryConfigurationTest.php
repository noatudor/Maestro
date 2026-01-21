<?php

declare(strict_types=1);

use Maestro\Workflow\Definition\Config\RetryConfiguration;
use Maestro\Workflow\Enums\RetryScope;

describe('RetryConfiguration', static function (): void {
    describe('create', static function (): void {
        it('creates with specified values', function (): void {
            $retryConfiguration = RetryConfiguration::create(
                maxAttempts: 5,
                delaySeconds: 30,
                backoffMultiplier: 1.5,
                maxDelaySeconds: 1800,
                retryScope: RetryScope::FailedOnly,
            );

            expect($retryConfiguration->maxAttempts)->toBe(5);
            expect($retryConfiguration->delaySeconds)->toBe(30);
            expect($retryConfiguration->backoffMultiplier)->toBe(1.5);
            expect($retryConfiguration->maxDelaySeconds)->toBe(1800);
            expect($retryConfiguration->scope)->toBe(RetryScope::FailedOnly);
        });

        it('enforces minimum values', function (): void {
            $retryConfiguration = RetryConfiguration::create(
                maxAttempts: 0,
                delaySeconds: -10,
                backoffMultiplier: 0.5,
                maxDelaySeconds: -1,
            );

            expect($retryConfiguration->maxAttempts)->toBe(1);
            expect($retryConfiguration->delaySeconds)->toBe(0);
            expect($retryConfiguration->backoffMultiplier)->toBe(1.0);
            expect($retryConfiguration->maxDelaySeconds)->toBe(0);
        });
    });

    describe('none', static function (): void {
        it('creates config with no retries', function (): void {
            $retryConfiguration = RetryConfiguration::none();

            expect($retryConfiguration->maxAttempts)->toBe(1);
            expect($retryConfiguration->allowsRetry())->toBeFalse();
        });
    });

    describe('default', static function (): void {
        it('creates config with sensible defaults', function (): void {
            $retryConfiguration = RetryConfiguration::default();

            expect($retryConfiguration->maxAttempts)->toBe(3);
            expect($retryConfiguration->delaySeconds)->toBe(60);
            expect($retryConfiguration->backoffMultiplier)->toBe(2.0);
            expect($retryConfiguration->maxDelaySeconds)->toBe(3600);
            expect($retryConfiguration->scope)->toBe(RetryScope::All);
        });
    });

    describe('allowsRetry', static function (): void {
        it('returns true when maxAttempts > 1', function (): void {
            $retryConfiguration = RetryConfiguration::create(maxAttempts: 3);
            expect($retryConfiguration->allowsRetry())->toBeTrue();
        });

        it('returns false when maxAttempts is 1', function (): void {
            $retryConfiguration = RetryConfiguration::none();
            expect($retryConfiguration->allowsRetry())->toBeFalse();
        });
    });

    describe('hasReachedMaxAttempts', static function (): void {
        it('returns true when current attempt equals max', function (): void {
            $retryConfiguration = RetryConfiguration::create(maxAttempts: 3);
            expect($retryConfiguration->hasReachedMaxAttempts(3))->toBeTrue();
        });

        it('returns true when current attempt exceeds max', function (): void {
            $retryConfiguration = RetryConfiguration::create(maxAttempts: 3);
            expect($retryConfiguration->hasReachedMaxAttempts(4))->toBeTrue();
        });

        it('returns false when current attempt is less than max', function (): void {
            $retryConfiguration = RetryConfiguration::create(maxAttempts: 3);
            expect($retryConfiguration->hasReachedMaxAttempts(2))->toBeFalse();
        });
    });

    describe('getDelayForAttempt', static function (): void {
        it('returns base delay for first attempt', function (): void {
            $retryConfiguration = RetryConfiguration::create(delaySeconds: 60, backoffMultiplier: 2.0);
            expect($retryConfiguration->getDelayForAttempt(1))->toBe(60);
        });

        it('applies backoff multiplier for subsequent attempts', function (): void {
            $retryConfiguration = RetryConfiguration::create(delaySeconds: 60, backoffMultiplier: 2.0, maxDelaySeconds: 3600);

            expect($retryConfiguration->getDelayForAttempt(2))->toBe(120);
            expect($retryConfiguration->getDelayForAttempt(3))->toBe(240);
            expect($retryConfiguration->getDelayForAttempt(4))->toBe(480);
        });

        it('caps delay at maxDelaySeconds', function (): void {
            $retryConfiguration = RetryConfiguration::create(delaySeconds: 60, backoffMultiplier: 2.0, maxDelaySeconds: 200);

            expect($retryConfiguration->getDelayForAttempt(3))->toBe(200);
            expect($retryConfiguration->getDelayForAttempt(10))->toBe(200);
        });

        it('returns 0 when delay is 0', function (): void {
            $retryConfiguration = RetryConfiguration::create(delaySeconds: 0, backoffMultiplier: 2.0);
            expect($retryConfiguration->getDelayForAttempt(5))->toBe(0);
        });
    });

    describe('with methods', static function (): void {
        it('returns new instance with updated maxAttempts', function (): void {
            $retryConfiguration = RetryConfiguration::default();
            $updated = $retryConfiguration->withMaxAttempts(5);

            expect($updated->maxAttempts)->toBe(5);
            expect($retryConfiguration->maxAttempts)->toBe(3);
        });

        it('returns new instance with updated delay', function (): void {
            $retryConfiguration = RetryConfiguration::default();
            $updated = $retryConfiguration->withDelay(120);

            expect($updated->delaySeconds)->toBe(120);
            expect($retryConfiguration->delaySeconds)->toBe(60);
        });

        it('returns new instance with updated backoff', function (): void {
            $retryConfiguration = RetryConfiguration::default();
            $updated = $retryConfiguration->withBackoff(3.0);

            expect($updated->backoffMultiplier)->toBe(3.0);
            expect($retryConfiguration->backoffMultiplier)->toBe(2.0);
        });

        it('returns new instance with updated scope', function (): void {
            $retryConfiguration = RetryConfiguration::default();
            $updated = $retryConfiguration->withScope(RetryScope::FailedOnly);

            expect($updated->scope)->toBe(RetryScope::FailedOnly);
            expect($retryConfiguration->scope)->toBe(RetryScope::All);
        });
    });

    describe('equals', static function (): void {
        it('returns true for equal configurations', function (): void {
            $retryConfiguration = RetryConfiguration::create(3, 60, 2.0, 3600, RetryScope::All);
            $b = RetryConfiguration::create(3, 60, 2.0, 3600, RetryScope::All);

            expect($retryConfiguration->equals($b))->toBeTrue();
        });

        it('returns false for different configurations', function (): void {
            $retryConfiguration = RetryConfiguration::create(3, 60, 2.0, 3600, RetryScope::All);
            $b = RetryConfiguration::create(3, 60, 2.0, 3600, RetryScope::FailedOnly);

            expect($retryConfiguration->equals($b))->toBeFalse();
        });
    });
});
