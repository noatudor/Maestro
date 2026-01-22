<?php

declare(strict_types=1);

use Maestro\Workflow\Definition\Config\AutoRetryConfig;
use Maestro\Workflow\Definition\Config\FailureResolutionConfig;
use Maestro\Workflow\Enums\CancelBehavior;
use Maestro\Workflow\Enums\CompensationScope;
use Maestro\Workflow\Enums\FailureResolutionStrategy;
use Maestro\Workflow\Enums\TimeoutBehavior;

describe('FailureResolutionConfig', static function (): void {
    describe('awaitDecision', static function (): void {
        it('creates config with AwaitDecision strategy', function (): void {
            $failureResolutionConfig = FailureResolutionConfig::awaitDecision();

            expect($failureResolutionConfig->strategy)->toBe(FailureResolutionStrategy::AwaitDecision);
            expect($failureResolutionConfig->autoRetryConfig->isEnabled())->toBeFalse();
            expect($failureResolutionConfig->awaitsDecision())->toBeTrue();
        });

        it('allows custom cancel and timeout behaviors', function (): void {
            $failureResolutionConfig = FailureResolutionConfig::awaitDecision(
                cancelBehavior: CancelBehavior::Compensate,
                timeoutBehavior: TimeoutBehavior::AwaitDecision,
            );

            expect($failureResolutionConfig->cancelBehavior)->toBe(CancelBehavior::Compensate);
            expect($failureResolutionConfig->timeoutBehavior)->toBe(TimeoutBehavior::AwaitDecision);
        });
    });

    describe('autoRetry', static function (): void {
        it('creates config with AutoRetry strategy', function (): void {
            $autoRetryConfig = AutoRetryConfig::create(maxRetries: 5);
            $failureResolutionConfig = FailureResolutionConfig::autoRetry($autoRetryConfig);

            expect($failureResolutionConfig->strategy)->toBe(FailureResolutionStrategy::AutoRetry);
            expect($failureResolutionConfig->autoRetryConfig->maxRetries)->toBe(5);
            expect($failureResolutionConfig->autoRetries())->toBeTrue();
        });
    });

    describe('autoCompensate', static function (): void {
        it('creates config with AutoCompensate strategy', function (): void {
            $failureResolutionConfig = FailureResolutionConfig::autoCompensate();

            expect($failureResolutionConfig->strategy)->toBe(FailureResolutionStrategy::AutoCompensate);
            expect($failureResolutionConfig->autoCompensates())->toBeTrue();
        });

        it('allows custom compensation scope', function (): void {
            $failureResolutionConfig = FailureResolutionConfig::autoCompensate(
                compensationScope: CompensationScope::FailedStepOnly,
            );

            expect($failureResolutionConfig->compensationScope)->toBe(CompensationScope::FailedStepOnly);
        });
    });

    describe('default', static function (): void {
        it('creates AwaitDecision config', function (): void {
            $failureResolutionConfig = FailureResolutionConfig::default();

            expect($failureResolutionConfig->strategy)->toBe(FailureResolutionStrategy::AwaitDecision);
        });
    });

    describe('shouldCompensateOnCancel', static function (): void {
        it('returns true when cancelBehavior is Compensate', function (): void {
            $failureResolutionConfig = FailureResolutionConfig::awaitDecision(
                cancelBehavior: CancelBehavior::Compensate,
            );

            expect($failureResolutionConfig->shouldCompensateOnCancel())->toBeTrue();
        });

        it('returns false when cancelBehavior is NoCompensate', function (): void {
            $failureResolutionConfig = FailureResolutionConfig::awaitDecision(
                cancelBehavior: CancelBehavior::NoCompensate,
            );

            expect($failureResolutionConfig->shouldCompensateOnCancel())->toBeFalse();
        });
    });

    describe('compensatesAllSteps', static function (): void {
        it('returns true when scope is All', function (): void {
            $failureResolutionConfig = FailureResolutionConfig::autoCompensate(compensationScope: CompensationScope::All);
            expect($failureResolutionConfig->compensatesAllSteps())->toBeTrue();
        });

        it('returns false when scope is FailedStepOnly', function (): void {
            $failureResolutionConfig = FailureResolutionConfig::autoCompensate(compensationScope: CompensationScope::FailedStepOnly);
            expect($failureResolutionConfig->compensatesAllSteps())->toBeFalse();
        });
    });

    describe('equals', static function (): void {
        it('returns true for equal configs', function (): void {
            $failureResolutionConfig = FailureResolutionConfig::awaitDecision();
            $config2 = FailureResolutionConfig::awaitDecision();

            expect($failureResolutionConfig->equals($config2))->toBeTrue();
        });

        it('returns false for different strategies', function (): void {
            $failureResolutionConfig = FailureResolutionConfig::awaitDecision();
            $config2 = FailureResolutionConfig::autoCompensate();

            expect($failureResolutionConfig->equals($config2))->toBeFalse();
        });
    });
});
