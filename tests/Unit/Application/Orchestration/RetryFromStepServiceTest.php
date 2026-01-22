<?php

declare(strict_types=1);

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Maestro\Workflow\Application\Branching\ConditionEvaluator;
use Maestro\Workflow\Application\Context\WorkflowContextProviderFactory;
use Maestro\Workflow\Application\Dependency\StepDependencyChecker;
use Maestro\Workflow\Application\Job\JobDispatchService;
use Maestro\Workflow\Application\Orchestration\CompensationExecutor;
use Maestro\Workflow\Application\Orchestration\RetryFromStepService;
use Maestro\Workflow\Application\Orchestration\StepDispatcher;
use Maestro\Workflow\Application\Output\StepOutputStoreFactory;
use Maestro\Workflow\Definition\Builders\SingleJobStepBuilder;
use Maestro\Workflow\Definition\Builders\WorkflowDefinitionBuilder;
use Maestro\Workflow\Definition\WorkflowDefinitionRegistry;
use Maestro\Workflow\Domain\Events\RetryFromStepCompleted;
use Maestro\Workflow\Domain\Events\RetryFromStepInitiated;
use Maestro\Workflow\Domain\Events\StepRunSuperseded;
use Maestro\Workflow\Domain\StepRun;
use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\Enums\RetryMode;
use Maestro\Workflow\Enums\StepState;
use Maestro\Workflow\Enums\WorkflowState;
use Maestro\Workflow\Exceptions\StepNotFoundException;
use Maestro\Workflow\Exceptions\WorkflowNotFoundException;
use Maestro\Workflow\Tests\Fakes\InMemoryCompensationRunRepository;
use Maestro\Workflow\Tests\Fakes\InMemoryJobLedgerRepository;
use Maestro\Workflow\Tests\Fakes\InMemoryStepOutputRepository;
use Maestro\Workflow\Tests\Fakes\InMemoryStepRunRepository;
use Maestro\Workflow\Tests\Fakes\InMemoryWorkflowRepository;
use Maestro\Workflow\Tests\Fixtures\Jobs\TestJob;
use Maestro\Workflow\ValueObjects\RetryFromStepRequest;
use Maestro\Workflow\ValueObjects\RetryFromStepResult;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\WorkflowId;

describe('RetryFromStepService', function (): void {
    beforeEach(function (): void {
        $this->workflowRepository = new InMemoryWorkflowRepository();
        $this->stepRunRepository = new InMemoryStepRunRepository();
        $this->stepOutputRepository = new InMemoryStepOutputRepository();
        $this->jobLedgerRepository = new InMemoryJobLedgerRepository();
        $this->workflowDefinitionRegistry = new WorkflowDefinitionRegistry();
        $this->dispatchedEvents = [];

        $containerMock = Mockery::mock(Container::class);
        $containerMock->shouldReceive('make')->andReturnUsing(static fn (string $class): object => new $class());

        $stepDependencyChecker = new StepDependencyChecker(
            $this->stepOutputRepository,
        );

        $stepOutputStoreFactory = new StepOutputStoreFactory(
            $this->stepOutputRepository,
        );

        $workflowContextProviderFactory = new WorkflowContextProviderFactory($containerMock);

        $dispatcherMock = Mockery::mock(Dispatcher::class);
        $dispatcherMock->shouldReceive('dispatch');

        $this->eventDispatcherMock = Mockery::mock(EventDispatcher::class);
        $this->eventDispatcherMock->shouldReceive('dispatch')->andReturnUsing(function ($event): void {
            $this->dispatchedEvents[] = $event;
        });

        $jobDispatchService = new JobDispatchService(
            $dispatcherMock,
            $this->jobLedgerRepository,
            $this->eventDispatcherMock,
        );

        $conditionEvaluator = new ConditionEvaluator($containerMock);

        $stepDispatcher = new StepDispatcher(
            $this->stepRunRepository,
            $jobDispatchService,
            $stepDependencyChecker,
            $stepOutputStoreFactory,
            $workflowContextProviderFactory,
            $this->workflowDefinitionRegistry,
            $conditionEvaluator,
            $this->eventDispatcherMock,
        );

        $compensationRunRepository = new InMemoryCompensationRunRepository();
        $compensationExecutor = new CompensationExecutor(
            $this->workflowRepository,
            $compensationRunRepository,
            $this->workflowDefinitionRegistry,
            $jobDispatchService,
            $this->eventDispatcherMock,
        );

        $this->retryFromStepService = new RetryFromStepService(
            $this->workflowRepository,
            $this->stepRunRepository,
            $this->stepOutputRepository,
            $this->workflowDefinitionRegistry,
            $stepDispatcher,
            $compensationExecutor,
            $this->eventDispatcherMock,
        );
    });

    describe('execute', function (): void {
        it('throws when workflow not found', function (): void {
            $retryFromStepRequest = RetryFromStepRequest::create(
                workflowId: WorkflowId::generate(),
                retryFromStepKey: StepKey::fromString('step-1'),
            );

            expect(fn () => $this->retryFromStepService->execute($retryFromStepRequest))
                ->toThrow(WorkflowNotFoundException::class);
        });

        it('throws when step not found in definition', function (): void {
            $singleJobStepDefinition = SingleJobStepBuilder::create('step-1')
                ->displayName('Step 1')
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
            $this->workflowRepository->save($workflowInstance);

            $retryFromStepRequest = RetryFromStepRequest::create(
                workflowId: $workflowInstance->id,
                retryFromStepKey: StepKey::fromString('non-existent-step'),
            );

            expect(fn () => $this->retryFromStepService->execute($retryFromStepRequest))
                ->toThrow(StepNotFoundException::class);
        });

        it('supersedes existing step runs from retry point onwards', function (): void {
            $singleJobStepDefinition = SingleJobStepBuilder::create('step-1')
                ->displayName('Step 1')
                ->job(TestJob::class)
                ->build();

            $step2Definition = SingleJobStepBuilder::create('step-2')
                ->displayName('Step 2')
                ->job(TestJob::class)
                ->build();

            $step3Definition = SingleJobStepBuilder::create('step-3')
                ->displayName('Step 3')
                ->job(TestJob::class)
                ->build();

            $workflowDefinition = WorkflowDefinitionBuilder::create('test-workflow')
                ->addStep($singleJobStepDefinition)
                ->addStep($step2Definition)
                ->addStep($step3Definition)
                ->build();
            $this->workflowDefinitionRegistry->register($workflowDefinition);

            $workflowInstance = WorkflowInstance::create(
                $workflowDefinition->key(),
                $workflowDefinition->version(),
            );
            $workflowInstance->start(StepKey::fromString('step-1'));
            $this->workflowRepository->save($workflowInstance);

            $stepRun1 = StepRun::create($workflowInstance->id, StepKey::fromString('step-1'));
            $stepRun1->start();
            $stepRun1->succeed();
            $this->stepRunRepository->save($stepRun1);

            $stepRun2 = StepRun::create($workflowInstance->id, StepKey::fromString('step-2'));
            $stepRun2->start();
            $stepRun2->succeed();
            $this->stepRunRepository->save($stepRun2);

            $stepRun3 = StepRun::create($workflowInstance->id, StepKey::fromString('step-3'));
            $stepRun3->start();
            $stepRun3->fail('ERROR', 'Something went wrong');
            $this->stepRunRepository->save($stepRun3);

            $workflowInstance->fail('ERROR', 'Step 3 failed');
            $this->workflowRepository->save($workflowInstance);

            $retryFromStepRequest = RetryFromStepRequest::create(
                workflowId: $workflowInstance->id,
                retryFromStepKey: StepKey::fromString('step-2'),
            );

            $result = $this->retryFromStepService->execute($retryFromStepRequest);

            expect($result->supersededCount())->toBe(2);
            expect($result->supersededStepRunIds)->toContain($stepRun2->id)
                ->and($result->supersededStepRunIds)->toContain($stepRun3->id);

            $supersededRun2 = $this->stepRunRepository->find($stepRun2->id);
            $supersededRun3 = $this->stepRunRepository->find($stepRun3->id);
            expect($supersededRun2->status())->toBe(StepState::Superseded);
            expect($supersededRun3->status())->toBe(StepState::Superseded);
        });

        it('clears outputs from affected steps', function (): void {
            $singleJobStepDefinition = SingleJobStepBuilder::create('step-1')
                ->displayName('Step 1')
                ->job(TestJob::class)
                ->build();

            $step2Definition = SingleJobStepBuilder::create('step-2')
                ->displayName('Step 2')
                ->job(TestJob::class)
                ->build();

            $workflowDefinition = WorkflowDefinitionBuilder::create('test-workflow')
                ->addStep($singleJobStepDefinition)
                ->addStep($step2Definition)
                ->build();
            $this->workflowDefinitionRegistry->register($workflowDefinition);

            $workflowInstance = WorkflowInstance::create(
                $workflowDefinition->key(),
                $workflowDefinition->version(),
            );
            $workflowInstance->start(StepKey::fromString('step-1'));
            $this->workflowRepository->save($workflowInstance);

            $stepRun1 = StepRun::create($workflowInstance->id, StepKey::fromString('step-1'));
            $stepRun1->start();
            $stepRun1->succeed();
            $this->stepRunRepository->save($stepRun1);

            $stepRun2 = StepRun::create($workflowInstance->id, StepKey::fromString('step-2'));
            $stepRun2->start();
            $stepRun2->fail('ERROR', 'Failed');
            $this->stepRunRepository->save($stepRun2);

            $workflowInstance->fail('ERROR', 'Step 2 failed');
            $this->workflowRepository->save($workflowInstance);

            $retryFromStepRequest = RetryFromStepRequest::create(
                workflowId: $workflowInstance->id,
                retryFromStepKey: StepKey::fromString('step-2'),
            );

            $result = $this->retryFromStepService->execute($retryFromStepRequest);

            expect($result->clearedOutputStepKeys)->toBeArray();
        });

        it('creates new step run for the retry step', function (): void {
            $singleJobStepDefinition = SingleJobStepBuilder::create('step-1')
                ->displayName('Step 1')
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
            $workflowInstance->start(StepKey::fromString('step-1'));
            $this->workflowRepository->save($workflowInstance);

            $stepRun1 = StepRun::create($workflowInstance->id, StepKey::fromString('step-1'));
            $stepRun1->start();
            $stepRun1->fail('ERROR', 'Failed');
            $this->stepRunRepository->save($stepRun1);

            $workflowInstance->fail('ERROR', 'Step 1 failed');
            $this->workflowRepository->save($workflowInstance);

            $retryFromStepRequest = RetryFromStepRequest::create(
                workflowId: $workflowInstance->id,
                retryFromStepKey: StepKey::fromString('step-1'),
            );

            $result = $this->retryFromStepService->execute($retryFromStepRequest);

            expect($result->newStepRunId)->not->toBeNull();

            $newStepRun = $this->stepRunRepository->find($result->newStepRunId);
            expect($newStepRun)->not->toBeNull();
            expect($newStepRun->stepKey->value)->toBe('step-1');
            expect($newStepRun->status())->toBe(StepState::Running);
            expect($newStepRun->attempt)->toBe(2);
        });

        it('returns result with all information', function (): void {
            $singleJobStepDefinition = SingleJobStepBuilder::create('step-1')
                ->displayName('Step 1')
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
            $workflowInstance->start(StepKey::fromString('step-1'));
            $this->workflowRepository->save($workflowInstance);

            $stepRun1 = StepRun::create($workflowInstance->id, StepKey::fromString('step-1'));
            $stepRun1->start();
            $stepRun1->fail('ERROR', 'Failed');
            $this->stepRunRepository->save($stepRun1);

            $workflowInstance->fail('ERROR', 'Step 1 failed');
            $this->workflowRepository->save($workflowInstance);

            $retryFromStepRequest = RetryFromStepRequest::create(
                workflowId: $workflowInstance->id,
                retryFromStepKey: StepKey::fromString('step-1'),
                retryMode: RetryMode::RetryOnly,
                initiatedBy: 'admin',
                reason: 'Manual retry',
            );

            $result = $this->retryFromStepService->execute($retryFromStepRequest);

            expect($result)->toBeInstanceOf(RetryFromStepResult::class);
            expect($result->retryFromStepKey->value)->toBe('step-1');
            expect($result->workflowInstance)->not->toBeNull();
            expect($result->compensationExecuted)->toBeFalse();
        });

        it('dispatches RetryFromStepInitiated event', function (): void {
            $singleJobStepDefinition = SingleJobStepBuilder::create('step-1')
                ->displayName('Step 1')
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
            $workflowInstance->start(StepKey::fromString('step-1'));
            $this->workflowRepository->save($workflowInstance);

            $stepRun1 = StepRun::create($workflowInstance->id, StepKey::fromString('step-1'));
            $stepRun1->start();
            $stepRun1->fail('ERROR', 'Failed');
            $this->stepRunRepository->save($stepRun1);

            $workflowInstance->fail('ERROR', 'Step 1 failed');
            $this->workflowRepository->save($workflowInstance);

            $retryFromStepRequest = RetryFromStepRequest::create(
                workflowId: $workflowInstance->id,
                retryFromStepKey: StepKey::fromString('step-1'),
                initiatedBy: 'admin',
                reason: 'Test retry',
            );

            $this->retryFromStepService->execute($retryFromStepRequest);

            $initiatedEvents = array_filter(
                $this->dispatchedEvents,
                static fn ($event): bool => $event instanceof RetryFromStepInitiated,
            );

            expect($initiatedEvents)->toHaveCount(1);
            $event = array_values($initiatedEvents)[0];
            expect($event->workflowId)->toBe($workflowInstance->id);
            expect($event->retryFromStepKey->value)->toBe('step-1');
            expect($event->initiatedBy)->toBe('admin');
            expect($event->reason)->toBe('Test retry');
        });

        it('dispatches StepRunSuperseded events for each superseded step run', function (): void {
            $singleJobStepDefinition = SingleJobStepBuilder::create('step-1')
                ->displayName('Step 1')
                ->job(TestJob::class)
                ->build();

            $step2Definition = SingleJobStepBuilder::create('step-2')
                ->displayName('Step 2')
                ->job(TestJob::class)
                ->build();

            $workflowDefinition = WorkflowDefinitionBuilder::create('test-workflow')
                ->addStep($singleJobStepDefinition)
                ->addStep($step2Definition)
                ->build();
            $this->workflowDefinitionRegistry->register($workflowDefinition);

            $workflowInstance = WorkflowInstance::create(
                $workflowDefinition->key(),
                $workflowDefinition->version(),
            );
            $workflowInstance->start(StepKey::fromString('step-1'));
            $this->workflowRepository->save($workflowInstance);

            $stepRun1 = StepRun::create($workflowInstance->id, StepKey::fromString('step-1'));
            $stepRun1->start();
            $stepRun1->succeed();
            $this->stepRunRepository->save($stepRun1);

            $stepRun2 = StepRun::create($workflowInstance->id, StepKey::fromString('step-2'));
            $stepRun2->start();
            $stepRun2->fail('ERROR', 'Failed');
            $this->stepRunRepository->save($stepRun2);

            $workflowInstance->fail('ERROR', 'Step 2 failed');
            $this->workflowRepository->save($workflowInstance);

            $retryFromStepRequest = RetryFromStepRequest::create(
                workflowId: $workflowInstance->id,
                retryFromStepKey: StepKey::fromString('step-1'),
            );

            $this->retryFromStepService->execute($retryFromStepRequest);

            $supersededEvents = array_filter(
                $this->dispatchedEvents,
                static fn ($event): bool => $event instanceof StepRunSuperseded,
            );

            expect($supersededEvents)->toHaveCount(2);
        });

        it('dispatches RetryFromStepCompleted event', function (): void {
            $singleJobStepDefinition = SingleJobStepBuilder::create('step-1')
                ->displayName('Step 1')
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
            $workflowInstance->start(StepKey::fromString('step-1'));
            $this->workflowRepository->save($workflowInstance);

            $stepRun1 = StepRun::create($workflowInstance->id, StepKey::fromString('step-1'));
            $stepRun1->start();
            $stepRun1->fail('ERROR', 'Failed');
            $this->stepRunRepository->save($stepRun1);

            $workflowInstance->fail('ERROR', 'Step 1 failed');
            $this->workflowRepository->save($workflowInstance);

            $retryFromStepRequest = RetryFromStepRequest::create(
                workflowId: $workflowInstance->id,
                retryFromStepKey: StepKey::fromString('step-1'),
            );

            $this->retryFromStepService->execute($retryFromStepRequest);

            $completedEvents = array_filter(
                $this->dispatchedEvents,
                static fn ($event): bool => $event instanceof RetryFromStepCompleted,
            );

            expect($completedEvents)->toHaveCount(1);
            $event = array_values($completedEvents)[0];
            expect($event->workflowId)->toBe($workflowInstance->id);
            expect($event->retryFromStepKey->value)->toBe('step-1');
        });

        it('sets compensationExecuted to false when mode is RetryOnly', function (): void {
            $singleJobStepDefinition = SingleJobStepBuilder::create('step-1')
                ->displayName('Step 1')
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
            $workflowInstance->start(StepKey::fromString('step-1'));
            $this->workflowRepository->save($workflowInstance);

            $stepRun1 = StepRun::create($workflowInstance->id, StepKey::fromString('step-1'));
            $stepRun1->start();
            $stepRun1->fail('ERROR', 'Failed');
            $this->stepRunRepository->save($stepRun1);

            $workflowInstance->fail('ERROR', 'Step 1 failed');
            $this->workflowRepository->save($workflowInstance);

            $retryFromStepRequest = RetryFromStepRequest::create(
                workflowId: $workflowInstance->id,
                retryFromStepKey: StepKey::fromString('step-1'),
                retryMode: RetryMode::RetryOnly,
            );

            $result = $this->retryFromStepService->execute($retryFromStepRequest);

            expect($result->compensationExecuted)->toBeFalse();
        });

        it('workflow advances to retry state', function (): void {
            $singleJobStepDefinition = SingleJobStepBuilder::create('step-1')
                ->displayName('Step 1')
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
            $workflowInstance->start(StepKey::fromString('step-1'));
            $workflowInstance->fail('ERROR', 'Original failure');
            $this->workflowRepository->save($workflowInstance);

            $stepRun1 = StepRun::create($workflowInstance->id, StepKey::fromString('step-1'));
            $stepRun1->start();
            $stepRun1->fail('ERROR', 'Failed');
            $this->stepRunRepository->save($stepRun1);

            $retryFromStepRequest = RetryFromStepRequest::create(
                workflowId: $workflowInstance->id,
                retryFromStepKey: StepKey::fromString('step-1'),
            );

            $result = $this->retryFromStepService->execute($retryFromStepRequest);

            expect($result->workflowInstance->state())->toBe(WorkflowState::Running);
        });

        it('only supersedes latest active step runs not already superseded', function (): void {
            $singleJobStepDefinition = SingleJobStepBuilder::create('step-1')
                ->displayName('Step 1')
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
            $workflowInstance->start(StepKey::fromString('step-1'));
            $this->workflowRepository->save($workflowInstance);

            $stepRun1 = StepRun::create($workflowInstance->id, StepKey::fromString('step-1'));
            $stepRun1->start();
            $stepRun1->fail('ERROR', 'Failed');
            $this->stepRunRepository->save($stepRun1);

            $workflowInstance->fail('ERROR', 'Step 1 failed');
            $this->workflowRepository->save($workflowInstance);

            $retryFromStepRequest = RetryFromStepRequest::create(
                workflowId: $workflowInstance->id,
                retryFromStepKey: StepKey::fromString('step-1'),
            );

            $result = $this->retryFromStepService->execute($retryFromStepRequest);

            expect($result->supersededCount())->toBe(1);
            expect($result->supersededStepRunIds)->toHaveCount(1);
            expect($result->supersededStepRunIds[0]->value)->toBe($stepRun1->id->value);
        });
    });
});
