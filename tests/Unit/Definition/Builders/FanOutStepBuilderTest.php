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

            $step = FanOutStepBuilder::create('process-items')
                ->job(ProcessItemJob::class)
                ->iterateOver($iterator)
                ->build();

            expect($step->key()->toString())->toBe('process-items');
            expect($step->jobClass())->toBe(ProcessItemJob::class);
            expect($step->itemIteratorFactory())->toBe($iterator);
            expect($step->parallelismLimit())->toBeNull();
            expect($step->successCriteria())->toBe(SuccessCriteria::All);
        });

        it('builds step with full configuration', function (): void {
            $iterator = static fn (): array => [1, 2, 3];
            $argsFactory = static fn (mixed $item): array => ['item' => $item];

            $step = FanOutStepBuilder::create('batch')
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

            expect($step->displayName())->toBe('Batch Process');
            expect($step->jobArgumentsFactory())->toBe($argsFactory);
            expect($step->parallelismLimit())->toBe(10);
            expect($step->successCriteria())->toBe(SuccessCriteria::Majority);
            expect($step->failurePolicy())->toBe(FailurePolicy::ContinueWithPartial);
        });
    });

    describe('parallelism', static function (): void {
        it('sets parallelism limit', function (): void {
            $step = FanOutStepBuilder::create('test')
                ->job(ProcessItemJob::class)
                ->iterateOver(static fn (): array => [])
                ->maxParallel(5)
                ->build();

            expect($step->parallelismLimit())->toBe(5);
        });

        it('sets unlimited parallelism', function (): void {
            $step = FanOutStepBuilder::create('test')
                ->job(ProcessItemJob::class)
                ->iterateOver(static fn (): array => [])
                ->maxParallel(5)
                ->unlimited()
                ->build();

            expect($step->parallelismLimit())->toBeNull();
        });
    });

    describe('success criteria shortcuts', static function (): void {
        it('sets requireAllSuccess', function (): void {
            $step = FanOutStepBuilder::create('test')
                ->job(ProcessItemJob::class)
                ->iterateOver(static fn (): array => [])
                ->requireAllSuccess()
                ->build();

            expect($step->successCriteria())->toBe(SuccessCriteria::All);
        });

        it('sets requireMajority', function (): void {
            $step = FanOutStepBuilder::create('test')
                ->job(ProcessItemJob::class)
                ->iterateOver(static fn (): array => [])
                ->requireMajority()
                ->build();

            expect($step->successCriteria())->toBe(SuccessCriteria::Majority);
        });

        it('sets requireAny', function (): void {
            $step = FanOutStepBuilder::create('test')
                ->job(ProcessItemJob::class)
                ->iterateOver(static fn (): array => [])
                ->requireAny()
                ->build();

            expect($step->successCriteria())->toBe(SuccessCriteria::BestEffort);
        });

        it('sets requireAtLeast with NOfMCriteria', function (): void {
            $step = FanOutStepBuilder::create('test')
                ->job(ProcessItemJob::class)
                ->iterateOver(static fn (): array => [])
                ->requireAtLeast(3)
                ->build();

            expect($step->successCriteria())->toBeInstanceOf(NOfMCriteria::class);
            expect($step->successCriteria()->minimumRequired)->toBe(3);
        });
    });
});
