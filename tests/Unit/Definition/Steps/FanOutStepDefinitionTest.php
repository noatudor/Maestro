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

            $definition = FanOutStepDefinition::create(
                key: StepKey::fromString('process-items'),
                displayName: 'Process Items',
                jobClass: ProcessItemJob::class,
                itemIteratorFactory: $iterator,
            );

            expect($definition->key()->toString())->toBe('process-items');
            expect($definition->displayName())->toBe('Process Items');
            expect($definition->jobClass())->toBe(ProcessItemJob::class);
            expect($definition->itemIteratorFactory())->toBe($iterator);
            expect($definition->jobArgumentsFactory())->toBeNull();
            expect($definition->parallelismLimit())->toBeNull();
            expect($definition->successCriteria())->toBe(SuccessCriteria::All);
        });

        it('creates definition with all parameters', function (): void {
            $iterator = static fn (): array => [1, 2, 3];
            $argsFactory = static fn (mixed $item): array => ['item' => $item];

            $definition = FanOutStepDefinition::create(
                key: StepKey::fromString('batch'),
                displayName: 'Batch Process',
                jobClass: ProcessItemJob::class,
                itemIteratorFactory: $iterator,
                jobArgumentsFactory: $argsFactory,
                parallelismLimit: 10,
                successCriteria: SuccessCriteria::Majority,
                requires: [TestOutput::class],
                produces: TestOutput::class,
                failurePolicy: FailurePolicy::ContinueWithPartial,
            );

            expect($definition->jobArgumentsFactory())->toBe($argsFactory);
            expect($definition->parallelismLimit())->toBe(10);
            expect($definition->successCriteria())->toBe(SuccessCriteria::Majority);
            expect($definition->requires())->toBe([TestOutput::class]);
            expect($definition->failurePolicy())->toBe(FailurePolicy::ContinueWithPartial);
        });

        it('enforces minimum parallelism limit of 1', function (): void {
            $definition = FanOutStepDefinition::create(
                key: StepKey::fromString('test'),
                displayName: 'Test',
                jobClass: ProcessItemJob::class,
                itemIteratorFactory: static fn (): array => [],
                parallelismLimit: 0,
            );

            expect($definition->parallelismLimit())->toBe(1);
        });
    });

    describe('hasParallelismLimit', static function (): void {
        it('returns true when limit is set', function (): void {
            $definition = FanOutStepDefinition::create(
                key: StepKey::fromString('test'),
                displayName: 'Test',
                jobClass: ProcessItemJob::class,
                itemIteratorFactory: static fn (): array => [],
                parallelismLimit: 5,
            );

            expect($definition->hasParallelismLimit())->toBeTrue();
        });

        it('returns false when limit is null', function (): void {
            $definition = FanOutStepDefinition::create(
                key: StepKey::fromString('test'),
                displayName: 'Test',
                jobClass: ProcessItemJob::class,
                itemIteratorFactory: static fn (): array => [],
            );

            expect($definition->hasParallelismLimit())->toBeFalse();
        });
    });

    describe('evaluateSuccess', static function (): void {
        it('evaluates with SuccessCriteria', function (): void {
            $definition = FanOutStepDefinition::create(
                key: StepKey::fromString('test'),
                displayName: 'Test',
                jobClass: ProcessItemJob::class,
                itemIteratorFactory: static fn (): array => [],
                successCriteria: SuccessCriteria::Majority,
            );

            expect($definition->evaluateSuccess(3, 5))->toBeTrue();
            expect($definition->evaluateSuccess(2, 5))->toBeFalse();
        });

        it('evaluates with NOfMCriteria', function (): void {
            $definition = FanOutStepDefinition::create(
                key: StepKey::fromString('test'),
                displayName: 'Test',
                jobClass: ProcessItemJob::class,
                itemIteratorFactory: static fn (): array => [],
                successCriteria: NOfMCriteria::atLeast(3),
            );

            expect($definition->evaluateSuccess(3, 5))->toBeTrue();
            expect($definition->evaluateSuccess(2, 5))->toBeFalse();
        });
    });
});
