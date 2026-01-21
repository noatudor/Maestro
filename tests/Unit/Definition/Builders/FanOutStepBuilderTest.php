<?php

declare(strict_types=1);

use Maestro\Workflow\Definition\Builders\FanOutStepBuilder;
use Maestro\Workflow\Definition\Config\NOfMCriteria;
use Maestro\Workflow\Enums\FailurePolicy;
use Maestro\Workflow\Enums\SuccessCriteria;
use Maestro\Workflow\Tests\Fixtures\Jobs\ProcessItemJob;
use Maestro\Workflow\Tests\Fixtures\Outputs\TestOutput;

describe('FanOutStepBuilder', static function (): void {
    describe('build', static function (): void {
        it('builds step with minimal configuration', function (): void {
            $iterator = static fn (): array => [1, 2, 3];

            $fanOutStepDefinition = FanOutStepBuilder::create('process-items')
                ->job(ProcessItemJob::class)
                ->iterateOver($iterator)
                ->build();

            expect($fanOutStepDefinition->key()->toString())->toBe('process-items');
            expect($fanOutStepDefinition->jobClass())->toBe(ProcessItemJob::class);
            expect($fanOutStepDefinition->itemIteratorFactory())->toBe($iterator);
            expect($fanOutStepDefinition->parallelismLimit())->toBeNull();
            expect($fanOutStepDefinition->successCriteria())->toBe(SuccessCriteria::All);
        });

        it('builds step with full configuration', function (): void {
            $iterator = static fn (): array => [1, 2, 3];
            $argsFactory = static fn (mixed $item): array => ['item' => $item];

            $fanOutStepDefinition = FanOutStepBuilder::create('batch')
                ->displayName('Batch Process')
                ->job(ProcessItemJob::class)
                ->iterateOver($iterator)
                ->withJobArguments($argsFactory)
                ->maxParallel(10)
                ->requireMajority()
                ->requires(TestOutput::class)
                ->produces(TestOutput::class)
                ->continueWithPartial()
                ->build();

            expect($fanOutStepDefinition->displayName())->toBe('Batch Process');
            expect($fanOutStepDefinition->jobArgumentsFactory())->toBe($argsFactory);
            expect($fanOutStepDefinition->parallelismLimit())->toBe(10);
            expect($fanOutStepDefinition->successCriteria())->toBe(SuccessCriteria::Majority);
            expect($fanOutStepDefinition->failurePolicy())->toBe(FailurePolicy::ContinueWithPartial);
        });
    });

    describe('parallelism', static function (): void {
        it('sets parallelism limit', function (): void {
            $fanOutStepDefinition = FanOutStepBuilder::create('test')
                ->job(ProcessItemJob::class)
                ->iterateOver(static fn (): array => [])
                ->maxParallel(5)
                ->build();

            expect($fanOutStepDefinition->parallelismLimit())->toBe(5);
        });

        it('sets unlimited parallelism', function (): void {
            $fanOutStepDefinition = FanOutStepBuilder::create('test')
                ->job(ProcessItemJob::class)
                ->iterateOver(static fn (): array => [])
                ->maxParallel(5)
                ->unlimited()
                ->build();

            expect($fanOutStepDefinition->parallelismLimit())->toBeNull();
        });
    });

    describe('success criteria shortcuts', static function (): void {
        it('sets requireAllSuccess', function (): void {
            $fanOutStepDefinition = FanOutStepBuilder::create('test')
                ->job(ProcessItemJob::class)
                ->iterateOver(static fn (): array => [])
                ->requireAllSuccess()
                ->build();

            expect($fanOutStepDefinition->successCriteria())->toBe(SuccessCriteria::All);
        });

        it('sets requireMajority', function (): void {
            $fanOutStepDefinition = FanOutStepBuilder::create('test')
                ->job(ProcessItemJob::class)
                ->iterateOver(static fn (): array => [])
                ->requireMajority()
                ->build();

            expect($fanOutStepDefinition->successCriteria())->toBe(SuccessCriteria::Majority);
        });

        it('sets requireAny', function (): void {
            $fanOutStepDefinition = FanOutStepBuilder::create('test')
                ->job(ProcessItemJob::class)
                ->iterateOver(static fn (): array => [])
                ->requireAny()
                ->build();

            expect($fanOutStepDefinition->successCriteria())->toBe(SuccessCriteria::BestEffort);
        });

        it('sets requireAtLeast with NOfMCriteria', function (): void {
            $fanOutStepDefinition = FanOutStepBuilder::create('test')
                ->job(ProcessItemJob::class)
                ->iterateOver(static fn (): array => [])
                ->requireAtLeast(3)
                ->build();

            expect($fanOutStepDefinition->successCriteria())->toBeInstanceOf(NOfMCriteria::class);
            expect($fanOutStepDefinition->successCriteria()->minimumRequired)->toBe(3);
        });
    });
});
