<?php

declare(strict_types=1);

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Maestro\Workflow\Application\Branching\ConditionEvaluator;
use Maestro\Workflow\Application\Context\WorkflowContextProviderFactory;
use Maestro\Workflow\Application\Dependency\StepDependencyChecker;
use Maestro\Workflow\Application\Job\JobDispatchService;
use Maestro\Workflow\Application\Orchestration\StepDispatcher;
use Maestro\Workflow\Application\Output\StepOutputStoreFactory;
use Maestro\Workflow\Definition\Builders\FanOutStepBuilder;
use Maestro\Workflow\Definition\Builders\SingleJobStepBuilder;
use Maestro\Workflow\Definition\Builders\WorkflowDefinitionBuilder;
use Maestro\Workflow\Definition\WorkflowDefinitionRegistry;
use Maestro\Workflow\Domain\StepRun;
use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\Enums\StepState;
use Maestro\Workflow\Exceptions\StepDependencyException;
use Maestro\Workflow\Tests\Fakes\InMemoryJobLedgerRepository;
use Maestro\Workflow\Tests\Fakes\InMemoryStepOutputRepository;
use Maestro\Workflow\Tests\Fakes\InMemoryStepRunRepository;
use Maestro\Workflow\Tests\Fixtures\Jobs\CustomArgsJob;
use Maestro\Workflow\Tests\Fixtures\Jobs\ProcessItemJob;
use Maestro\Workflow\Tests\Fixtures\Jobs\TestJob;
use Maestro\Workflow\Tests\Fixtures\Outputs\TestOutput;
use Maestro\Workflow\ValueObjects\StepKey;

describe('StepDispatcher', function (): void {
    beforeEach(function (): void {
        $this->stepRunRepository = new InMemoryStepRunRepository();
        $this->jobLedgerRepository = new InMemoryJobLedgerRepository();
        $this->stepOutputRepository = new InMemoryStepOutputRepository();
        $this->workflowDefinitionRegistry = new WorkflowDefinitionRegistry();

        $mock = Mockery::mock(Container::class);
        $mock->shouldReceive('make')->andReturnUsing(static fn (string $class): object => new $class());

        $stepDependencyChecker = new StepDependencyChecker(
            $this->stepOutputRepository,
        );

        $stepOutputStoreFactory = new StepOutputStoreFactory(
            $this->stepOutputRepository,
        );

        $workflowContextProviderFactory = new WorkflowContextProviderFactory($mock);

        $dispatcherMock = Mockery::mock(Dispatcher::class);
        $dispatcherMock->shouldReceive('dispatch');

        $eventDispatcherMock = Mockery::mock(EventDispatcher::class);
        $eventDispatcherMock->shouldReceive('dispatch');

        $jobDispatchService = new JobDispatchService(
            $dispatcherMock,
            $this->jobLedgerRepository,
            $eventDispatcherMock,
        );

        $conditionEvaluator = new ConditionEvaluator($mock);

        $this->dispatcher = new StepDispatcher(
            $this->stepRunRepository,
            $jobDispatchService,
            $stepDependencyChecker,
            $stepOutputStoreFactory,
            $workflowContextProviderFactory,
            $this->workflowDefinitionRegistry,
            $conditionEvaluator,
            $eventDispatcherMock,
        );
    });

    describe('dispatchStep', function (): void {
        it('creates step run for single job step', function (): void {
            $singleJobStepDefinition = SingleJobStepBuilder::create('test-step')
                ->displayName('Test Step')
                ->job(TestJob::class)
                ->build();

            $workflowDefinition = WorkflowDefinitionBuilder::create('test-workflow')
                ->addStep($singleJobStepDefinition)
                ->build();
            $this->workflowDefinitionRegistry->register($workflowDefinition);

            $workflowInstance = WorkflowInstance::create(
                $workflowDefinition->key(),
                $workflowDefinition->version(),
            );
            $workflowInstance->start(StepKey::fromString('test-step'));

            $stepRun = $this->dispatcher->dispatchStep($workflowInstance, $singleJobStepDefinition);

            expect($stepRun)->toBeInstanceOf(StepRun::class);
            expect($stepRun->stepKey->toString())->toBe('test-step');
            expect($stepRun->status())->toBe(StepState::Running);
            expect($stepRun->totalJobCount())->toBe(1);
        });

        it('increments attempt for retry', function (): void {
            $singleJobStepDefinition = SingleJobStepBuilder::create('test-step')
                ->displayName('Test Step')
                ->job(TestJob::class)
                ->build();

            $workflowDefinition = WorkflowDefinitionBuilder::create('test-workflow')
                ->addStep($singleJobStepDefinition)
                ->build();
            $this->workflowDefinitionRegistry->register($workflowDefinition);

            $workflowInstance = WorkflowInstance::create(
                $workflowDefinition->key(),
                $workflowDefinition->version(),
            );
            $workflowInstance->start(StepKey::fromString('test-step'));

            $firstStepRun = StepRun::create(
                $workflowInstance->id,
                StepKey::fromString('test-step'),
            );
            $firstStepRun->start();
            $firstStepRun->fail('ERROR', 'Failed');
            $this->stepRunRepository->save($firstStepRun);

            $newStepRun = $this->dispatcher->dispatchStep($workflowInstance, $singleJobStepDefinition);

            expect($newStepRun->attempt)->toBe(2);
        });

        it('dispatches fan-out jobs', function (): void {
            $fanOutStepDefinition = FanOutStepBuilder::create('fan-out-step')
                ->displayName('Fan Out Step')
                ->job(ProcessItemJob::class)
                ->iterateOver(static fn (): array => [1, 2, 3])
                ->build();

            $workflowDefinition = WorkflowDefinitionBuilder::create('test-workflow')
                ->addStep($fanOutStepDefinition)
                ->build();
            $this->workflowDefinitionRegistry->register($workflowDefinition);

            $workflowInstance = WorkflowInstance::create(
                $workflowDefinition->key(),
                $workflowDefinition->version(),
            );
            $workflowInstance->start(StepKey::fromString('fan-out-step'));

            $stepRun = $this->dispatcher->dispatchStep($workflowInstance, $fanOutStepDefinition);

            expect($stepRun->totalJobCount())->toBe(3);
        });

        it('handles empty fan-out gracefully', function (): void {
            $fanOutStepDefinition = FanOutStepBuilder::create('fan-out-step')
                ->displayName('Fan Out Step')
                ->job(TestJob::class)
                ->iterateOver(static fn (): array => [])
                ->build();

            $workflowDefinition = WorkflowDefinitionBuilder::create('test-workflow')
                ->addStep($fanOutStepDefinition)
                ->build();
            $this->workflowDefinitionRegistry->register($workflowDefinition);

            $workflowInstance = WorkflowInstance::create(
                $workflowDefinition->key(),
                $workflowDefinition->version(),
            );
            $workflowInstance->start(StepKey::fromString('fan-out-step'));

            $stepRun = $this->dispatcher->dispatchStep($workflowInstance, $fanOutStepDefinition);

            expect($stepRun->totalJobCount())->toBe(0);
        });

        it('throws when dependencies not met', function (): void {
            $singleJobStepDefinition = SingleJobStepBuilder::create('test-step')
                ->displayName('Test Step')
                ->job(TestJob::class)
                ->requires(TestOutput::class)
                ->build();

            $workflowDefinition = WorkflowDefinitionBuilder::create('test-workflow')
                ->addStep($singleJobStepDefinition)
                ->build();
            $this->workflowDefinitionRegistry->register($workflowDefinition);

            $workflowInstance = WorkflowInstance::create(
                $workflowDefinition->key(),
                $workflowDefinition->version(),
            );
            $workflowInstance->start(StepKey::fromString('test-step'));

            expect(fn () => $this->dispatcher->dispatchStep($workflowInstance, $singleJobStepDefinition))
                ->toThrow(StepDependencyException::class);
        });

        it('saves step run to repository', function (): void {
            $singleJobStepDefinition = SingleJobStepBuilder::create('test-step')
                ->displayName('Test Step')
                ->job(TestJob::class)
                ->build();

            $workflowDefinition = WorkflowDefinitionBuilder::create('test-workflow')
                ->addStep($singleJobStepDefinition)
                ->build();
            $this->workflowDefinitionRegistry->register($workflowDefinition);

            $workflowInstance = WorkflowInstance::create(
                $workflowDefinition->key(),
                $workflowDefinition->version(),
            );
            $workflowInstance->start(StepKey::fromString('test-step'));

            $stepRun = $this->dispatcher->dispatchStep($workflowInstance, $singleJobStepDefinition);

            $savedStepRun = $this->stepRunRepository->find($stepRun->id);
            expect($savedStepRun)->not->toBeNull();
            expect($savedStepRun->id->value)->toBe($stepRun->id->value);
        });

        it('creates job records for dispatched jobs', function (): void {
            $singleJobStepDefinition = SingleJobStepBuilder::create('test-step')
                ->displayName('Test Step')
                ->job(TestJob::class)
                ->build();

            $workflowDefinition = WorkflowDefinitionBuilder::create('test-workflow')
                ->addStep($singleJobStepDefinition)
                ->build();
            $this->workflowDefinitionRegistry->register($workflowDefinition);

            $workflowInstance = WorkflowInstance::create(
                $workflowDefinition->key(),
                $workflowDefinition->version(),
            );
            $workflowInstance->start(StepKey::fromString('test-step'));

            $stepRun = $this->dispatcher->dispatchStep($workflowInstance, $singleJobStepDefinition);

            $jobRecords = $this->jobLedgerRepository->findByStepRunId($stepRun->id);
            expect($jobRecords)->toHaveCount(1);
        });
    });

    describe('retryStep', function (): void {
        it('dispatches step for retry', function (): void {
            $singleJobStepDefinition = SingleJobStepBuilder::create('test-step')
                ->displayName('Test Step')
                ->job(TestJob::class)
                ->build();

            $workflowDefinition = WorkflowDefinitionBuilder::create('test-workflow')
                ->addStep($singleJobStepDefinition)
                ->build();
            $this->workflowDefinitionRegistry->register($workflowDefinition);

            $workflowInstance = WorkflowInstance::create(
                $workflowDefinition->key(),
                $workflowDefinition->version(),
            );
            $workflowInstance->start(StepKey::fromString('test-step'));

            $stepRun = $this->dispatcher->retryStep($workflowInstance, $singleJobStepDefinition);

            expect($stepRun->status())->toBe(StepState::Running);
        });
    });

    describe('fan-out with job arguments factory', function (): void {
        it('uses job arguments factory when provided', function (): void {
            $fanOutStepDefinition = FanOutStepBuilder::create('fan-out-step')
                ->displayName('Fan Out Step')
                ->job(CustomArgsJob::class)
                ->iterateOver(static fn (): array => ['item1', 'item2'])
                ->withJobArguments(static fn ($item): array => ['custom' => $item])
                ->build();

            $workflowDefinition = WorkflowDefinitionBuilder::create('test-workflow')
                ->addStep($fanOutStepDefinition)
                ->build();
            $this->workflowDefinitionRegistry->register($workflowDefinition);

            $workflowInstance = WorkflowInstance::create(
                $workflowDefinition->key(),
                $workflowDefinition->version(),
            );
            $workflowInstance->start(StepKey::fromString('fan-out-step'));

            $stepRun = $this->dispatcher->dispatchStep($workflowInstance, $fanOutStepDefinition);

            expect($stepRun->totalJobCount())->toBe(2);
        });
    });
});
