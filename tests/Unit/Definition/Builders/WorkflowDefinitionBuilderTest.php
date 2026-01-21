<?php

declare(strict_types=1);

use Maestro\Workflow\Definition\Builders\WorkflowDefinitionBuilder;
use Maestro\Workflow\Tests\Fixtures\Jobs\ProcessItemJob;
use Maestro\Workflow\Tests\Fixtures\Jobs\TestJob;
use Maestro\Workflow\Tests\Fixtures\Outputs\TestOutput;
use Maestro\Workflow\Tests\Fixtures\TestContextLoader;

describe('WorkflowDefinitionBuilder', static function (): void {
    describe('build', static function (): void {
        it('builds definition with minimal configuration', function (): void {
            $definition = WorkflowDefinitionBuilder::create('my-workflow')
                ->build();

            expect($definition->key()->toString())->toBe('my-workflow');
            expect($definition->displayName())->toBe('my-workflow');
            expect($definition->version()->toString())->toBe('1.0.0');
            expect($definition->stepCount())->toBe(0);
        });

        it('builds definition with full configuration', function (): void {
            $definition = WorkflowDefinitionBuilder::create('order-fulfillment')
                ->version('2.1.0')
                ->displayName('Order Fulfillment Workflow')
                ->contextLoader(TestContextLoader::class)
                ->build();

            expect($definition->key()->toString())->toBe('order-fulfillment');
            expect($definition->displayName())->toBe('Order Fulfillment Workflow');
            expect($definition->version()->toString())->toBe('2.1.0');
            expect($definition->contextLoaderClass())->toBe(TestContextLoader::class);
        });
    });

    describe('singleJob', static function (): void {
        it('adds single job step using builder', function (): void {
            $definition = WorkflowDefinitionBuilder::create('test')
                ->singleJob('validate', fn ($builder) => $builder
                    ->displayName('Validate Order')
                    ->job(TestJob::class)
                    ->produces(TestOutput::class))
                ->build();

            expect($definition->stepCount())->toBe(1);

            $step = $definition->getFirstStep();
            expect($step->key()->toString())->toBe('validate');
            expect($step->displayName())->toBe('Validate Order');
        });
    });

    describe('fanOut', static function (): void {
        it('adds fan-out step using builder', function (): void {
            $definition = WorkflowDefinitionBuilder::create('test')
                ->fanOut('process-items', fn ($builder) => $builder
                    ->displayName('Process All Items')
                    ->job(ProcessItemJob::class)
                    ->iterateOver(static fn (): array => [1, 2, 3])
                    ->maxParallel(5)
                    ->requireMajority())
                ->build();

            expect($definition->stepCount())->toBe(1);

            $step = $definition->getFirstStep();
            expect($step->key()->toString())->toBe('process-items');
        });
    });

    describe('chaining', static function (): void {
        it('chains multiple steps', function (): void {
            $definition = WorkflowDefinitionBuilder::create('multi-step')
                ->singleJob('step-one', fn ($b) => $b
                    ->job(TestJob::class)
                    ->produces(TestOutput::class))
                ->singleJob('step-two', fn ($b) => $b
                    ->job(TestJob::class)
                    ->requires(TestOutput::class))
                ->fanOut('step-three', fn ($b) => $b
                    ->job(ProcessItemJob::class)
                    ->iterateOver(static fn (): array => [])
                    ->requires(TestOutput::class))
                ->build();

            expect($definition->stepCount())->toBe(3);
        });
    });
});
