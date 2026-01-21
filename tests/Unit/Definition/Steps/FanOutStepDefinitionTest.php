<?php

declare(strict_types=1);

use Maestro\Workflow\Definition\Config\NOfMCriteria;
use Maestro\Workflow\Definition\Steps\FanOutStepDefinition;
use Maestro\Workflow\Enums\FailurePolicy;
use Maestro\Workflow\Enums\SuccessCriteria;
use Maestro\Workflow\Tests\Fixtures\Jobs\ProcessItemJob;
use Maestro\Workflow\Tests\Fixtures\Outputs\TestOutput;
use Maestro\Workflow\ValueObjects\StepKey;

describe('FanOutStepDefinition', static function (): void {
    describe('create', static function (): void {
        it('creates definition with required parameters', function (): void {
            $iterator = static fn (): array => [1, 2, 3];

            $fanOutStepDefinition = FanOutStepDefinition::create(
                displayName: 'Process Items',
                jobClass: ProcessItemJob::class,
                itemIteratorFactory: $iterator,
                key: StepKey::fromString('process-items'),
            );

            expect($fanOutStepDefinition->key()->toString())->toBe('process-items');
            expect($fanOutStepDefinition->displayName())->toBe('Process Items');
            expect($fanOutStepDefinition->jobClass())->toBe(ProcessItemJob::class);
            expect($fanOutStepDefinition->itemIteratorFactory())->toBe($iterator);
            expect($fanOutStepDefinition->jobArgumentsFactory())->toBeNull();
            expect($fanOutStepDefinition->parallelismLimit())->toBeNull();
            expect($fanOutStepDefinition->successCriteria())->toBe(SuccessCriteria::All);
        });

        it('creates definition with all parameters', function (): void {
            $iterator = static fn (): array => [1, 2, 3];
            $argsFactory = static fn (mixed $item): array => ['item' => $item];

            $fanOutStepDefinition = FanOutStepDefinition::create(
                displayName: 'Batch Process',
                jobClass: ProcessItemJob::class,
                itemIteratorFactory: $iterator,
                jobArgumentsFactory: $argsFactory,
                parallelismLimit: 10,
                successCriteria: SuccessCriteria::Majority,
                requires: [TestOutput::class],
                produces: TestOutput::class,
                failurePolicy: FailurePolicy::ContinueWithPartial,
                key: StepKey::fromString('batch'),
            );

            expect($fanOutStepDefinition->jobArgumentsFactory())->toBe($argsFactory);
            expect($fanOutStepDefinition->parallelismLimit())->toBe(10);
            expect($fanOutStepDefinition->successCriteria())->toBe(SuccessCriteria::Majority);
            expect($fanOutStepDefinition->requires())->toBe([TestOutput::class]);
            expect($fanOutStepDefinition->failurePolicy())->toBe(FailurePolicy::ContinueWithPartial);
        });

        it('enforces minimum parallelism limit of 1', function (): void {
            $fanOutStepDefinition = FanOutStepDefinition::create(
                displayName: 'Test',
                jobClass: ProcessItemJob::class,
                itemIteratorFactory: static fn (): array => [],
                parallelismLimit: 0,
                key: StepKey::fromString('test'),
            );

            expect($fanOutStepDefinition->parallelismLimit())->toBe(1);
        });
    });

    describe('hasParallelismLimit', static function (): void {
        it('returns true when limit is set', function (): void {
            $fanOutStepDefinition = FanOutStepDefinition::create(
                displayName: 'Test',
                jobClass: ProcessItemJob::class,
                itemIteratorFactory: static fn (): array => [],
                parallelismLimit: 5,
                key: StepKey::fromString('test'),
            );

            expect($fanOutStepDefinition->hasParallelismLimit())->toBeTrue();
        });

        it('returns false when limit is null', function (): void {
            $fanOutStepDefinition = FanOutStepDefinition::create(
                displayName: 'Test',
                jobClass: ProcessItemJob::class,
                itemIteratorFactory: static fn (): array => [],
                key: StepKey::fromString('test'),
            );

            expect($fanOutStepDefinition->hasParallelismLimit())->toBeFalse();
        });
    });

    describe('evaluateSuccess', static function (): void {
        it('evaluates with SuccessCriteria', function (): void {
            $fanOutStepDefinition = FanOutStepDefinition::create(
                displayName: 'Test',
                jobClass: ProcessItemJob::class,
                itemIteratorFactory: static fn (): array => [],
                successCriteria: SuccessCriteria::Majority,
                key: StepKey::fromString('test'),
            );

            expect($fanOutStepDefinition->evaluateSuccess(3, 5))->toBeTrue();
            expect($fanOutStepDefinition->evaluateSuccess(2, 5))->toBeFalse();
        });

        it('evaluates with NOfMCriteria', function (): void {
            $fanOutStepDefinition = FanOutStepDefinition::create(
                displayName: 'Test',
                jobClass: ProcessItemJob::class,
                itemIteratorFactory: static fn (): array => [],
                successCriteria: NOfMCriteria::atLeast(3),
                key: StepKey::fromString('test'),
            );

            expect($fanOutStepDefinition->evaluateSuccess(3, 5))->toBeTrue();
            expect($fanOutStepDefinition->evaluateSuccess(2, 5))->toBeFalse();
        });
    });
});
