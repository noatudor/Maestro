<?php

declare(strict_types=1);

use Maestro\Workflow\Definition\Builders\FanOutStepBuilder;
use Maestro\Workflow\Definition\Builders\SingleJobStepBuilder;
use Maestro\Workflow\Definition\Builders\WorkflowDefinitionBuilder;
use Maestro\Workflow\Tests\Fixtures\Jobs\ProcessItemJob;
use Maestro\Workflow\Tests\Fixtures\Jobs\TestJob;
use Maestro\Workflow\Tests\Fixtures\Outputs\TestOutput;
use Maestro\Workflow\Tests\Fixtures\TestContextLoader;

describe('WorkflowDefinitionBuilder', static function (): void {
    describe('build', static function (): void {
        it('builds definition with minimal configuration', function (): void {
            $workflowDefinition = WorkflowDefinitionBuilder::create('my-workflow')
                ->build();

            expect($workflowDefinition->key()->toString())->toBe('my-workflow');
            expect($workflowDefinition->displayName())->toBe('my-workflow');
            expect($workflowDefinition->version()->toString())->toBe('1.0.0');
            expect($workflowDefinition->stepCount())->toBe(0);
        });

        it('builds definition with full configuration', function (): void {
            $workflowDefinition = WorkflowDefinitionBuilder::create('order-fulfillment')
                ->version('2.1.0')
                ->displayName('Order Fulfillment Workflow')
                ->contextLoader(TestContextLoader::class)
                ->build();

            expect($workflowDefinition->key()->toString())->toBe('order-fulfillment');
            expect($workflowDefinition->displayName())->toBe('Order Fulfillment Workflow');
            expect($workflowDefinition->version()->toString())->toBe('2.1.0');
            expect($workflowDefinition->contextLoaderClass())->toBe(TestContextLoader::class);
        });
    });

    describe('singleJob', static function (): void {
        it('adds single job step using builder', function (): void {
            $workflowDefinition = WorkflowDefinitionBuilder::create('test')
                ->singleJob('validate', static fn ($builder): SingleJobStepBuilder => $builder
                    ->displayName('Validate Order')
                    ->job(TestJob::class)
                    ->produces(TestOutput::class))
                ->build();

            expect($workflowDefinition->stepCount())->toBe(1);

            $step = $workflowDefinition->getFirstStep();
            expect($step->key()->toString())->toBe('validate');
            expect($step->displayName())->toBe('Validate Order');
        });
    });

    describe('fanOut', static function (): void {
        it('adds fan-out step using builder', function (): void {
            $workflowDefinition = WorkflowDefinitionBuilder::create('test')
                ->fanOut('process-items', static fn ($builder): FanOutStepBuilder => $builder
                    ->displayName('Process All Items')
                    ->job(ProcessItemJob::class)
                    ->iterateOver(static fn (): array => [1, 2, 3])
                    ->maxParallel(5)
                    ->requireMajority())
                ->build();

            expect($workflowDefinition->stepCount())->toBe(1);

            $step = $workflowDefinition->getFirstStep();
            expect($step->key()->toString())->toBe('process-items');
        });
    });

    describe('chaining', static function (): void {
        it('chains multiple steps', function (): void {
            $workflowDefinition = WorkflowDefinitionBuilder::create('multi-step')
                ->singleJob('step-one', static fn ($b): SingleJobStepBuilder => $b
                    ->job(TestJob::class)
                    ->produces(TestOutput::class))
                ->singleJob('step-two', static fn ($b): SingleJobStepBuilder => $b
                    ->job(TestJob::class)
                    ->requires(TestOutput::class))
                ->fanOut('step-three', static fn ($b): FanOutStepBuilder => $b
                    ->job(ProcessItemJob::class)
                    ->iterateOver(static fn (): array => [])
                    ->requires(TestOutput::class))
                ->build();

            expect($workflowDefinition->stepCount())->toBe(3);
        });
    });
});
