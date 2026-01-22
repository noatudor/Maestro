<?php

declare(strict_types=1);

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Maestro\Workflow\Application\Branching\ConditionEvaluator;
use Maestro\Workflow\Application\Context\WorkflowContextProviderFactory;
use Maestro\Workflow\Application\Dependency\StepDependencyChecker;
use Maestro\Workflow\Application\Job\JobDispatchService;
use Maestro\Workflow\Application\Orchestration\CompensationExecutor;
use Maestro\Workflow\Application\Orchestration\FailurePolicyHandler;
use Maestro\Workflow\Application\Orchestration\FailureResolutionHandler;
use Maestro\Workflow\Application\Orchestration\RetryFromStepService;
use Maestro\Workflow\Application\Orchestration\StepDispatcher;
use Maestro\Workflow\Application\Orchestration\StepFinalizer;
use Maestro\Workflow\Application\Orchestration\WorkflowAdvancer;
use Maestro\Workflow\Application\Orchestration\WorkflowManagementService;
use Maestro\Workflow\Application\Output\StepOutputStoreFactory;
use Maestro\Workflow\Definition\Builders\FanOutStepBuilder;
use Maestro\Workflow\Definition\Builders\SingleJobStepBuilder;
use Maestro\Workflow\Definition\Builders\WorkflowDefinitionBuilder;
use Maestro\Workflow\Definition\WorkflowDefinition;
use Maestro\Workflow\Definition\WorkflowDefinitionRegistry;
use Maestro\Workflow\Domain\Events\StepSucceeded;
use Maestro\Workflow\Domain\Events\WorkflowSucceeded;
use Maestro\Workflow\Enums\StepState;
use Maestro\Workflow\Enums\WorkflowState;
use Maestro\Workflow\Infrastructure\Persistence\Hydrators\JobLedgerHydrator;
use Maestro\Workflow\Infrastructure\Persistence\Hydrators\StepRunHydrator;
use Maestro\Workflow\Infrastructure\Persistence\Hydrators\WorkflowHydrator;
use Maestro\Workflow\Infrastructure\Persistence\Repositories\EloquentJobLedgerRepository;
use Maestro\Workflow\Infrastructure\Persistence\Repositories\EloquentStepOutputRepository;
use Maestro\Workflow\Infrastructure\Persistence\Repositories\EloquentStepRunRepository;
use Maestro\Workflow\Infrastructure\Persistence\Repositories\EloquentWorkflowRepository;
use Maestro\Workflow\Infrastructure\Serialization\PhpOutputSerializer;
use Maestro\Workflow\Tests\Fakes\InMemoryCompensationRunRepository;
use Maestro\Workflow\Tests\Fakes\InMemoryResolutionDecisionRepository;
use Maestro\Workflow\Tests\Fixtures\Jobs\ProcessItemJob;
use Maestro\Workflow\Tests\Fixtures\Jobs\TestJob;
use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\StepKey;

describe('Fan-Out and Fan-In E2E Tests', function (): void {
    beforeEach(function (): void {
        Event::fake();

        $this->workflowRepository = new EloquentWorkflowRepository(
            new WorkflowHydrator(),
            DB::connection(),
        );
        $this->stepRunRepository = new EloquentStepRunRepository(new StepRunHydrator());
        $this->jobLedgerRepository = new EloquentJobLedgerRepository(new JobLedgerHydrator());
        $this->stepOutputRepository = new EloquentStepOutputRepository(
            new PhpOutputSerializer(),
            DB::connection(),
        );

        $this->workflowDefinitionRegistry = new WorkflowDefinitionRegistry();

        $mock = Mockery::mock(Container::class);
        $mock->shouldReceive('make')->andReturnUsing(static fn (string $class): object => new $class());

        $stepDependencyChecker = new StepDependencyChecker($this->stepOutputRepository);
        $stepOutputStoreFactory = new StepOutputStoreFactory($this->stepOutputRepository);
        $workflowContextProviderFactory = new WorkflowContextProviderFactory($mock);
        $conditionEvaluator = new ConditionEvaluator(app());

        $dispatcherMock = Mockery::mock(Dispatcher::class);
        $dispatcherMock->shouldReceive('dispatch');

        $jobDispatchService = new JobDispatchService(
            $dispatcherMock,
            $this->jobLedgerRepository,
            app('events'),
        );

        $stepDispatcher = new StepDispatcher(
            $this->stepRunRepository,
            $jobDispatchService,
            $stepDependencyChecker,
            $stepOutputStoreFactory,
            $workflowContextProviderFactory,
            $this->workflowDefinitionRegistry,
            $conditionEvaluator,
            app('events'),
        );

        $stepFinalizer = new StepFinalizer(
            $this->stepRunRepository,
            $this->jobLedgerRepository,
            app('events'),
        );

        $failurePolicyHandler = new FailurePolicyHandler(
            $this->workflowRepository,
            $stepDispatcher,
            app('events'),
        );

        $this->advancer = new WorkflowAdvancer(
            $this->workflowRepository,
            $this->stepRunRepository,
            $this->workflowDefinitionRegistry,
            $stepFinalizer,
            $stepDispatcher,
            $failurePolicyHandler,
            $conditionEvaluator,
            $stepOutputStoreFactory,
            app('events'),
        );

        $resolutionDecisionRepository = new InMemoryResolutionDecisionRepository();
        $compensationRunRepository = new InMemoryCompensationRunRepository();

        $compensationExecutor = new CompensationExecutor(
            $this->workflowRepository,
            $compensationRunRepository,
            $this->workflowDefinitionRegistry,
            $jobDispatchService,
            app('events'),
        );

        $retryFromStepService = new RetryFromStepService(
            $this->workflowRepository,
            $this->stepRunRepository,
            $this->stepOutputRepository,
            $this->workflowDefinitionRegistry,
            $stepDispatcher,
            $compensationExecutor,
            app('events'),
        );

        $failureResolutionHandler = new FailureResolutionHandler(
            $this->workflowRepository,
            $resolutionDecisionRepository,
            $this->workflowDefinitionRegistry,
            $this->advancer,
            $retryFromStepService,
            $compensationExecutor,
            app('events'),
        );

        $this->workflowManagementService = new WorkflowManagementService(
            $this->workflowRepository,
            $this->workflowDefinitionRegistry,
            $this->advancer,
            $failureResolutionHandler,
            app('events'),
        );
    });

    describe('Basic Fan-Out Scenarios', function (): void {
        it('executes a fan-out step with multiple items', function (): void {
            $items = ['item-1', 'item-2', 'item-3'];
            $workflowDefinition = createFanOutDefinition($items);
            $this->workflowDefinitionRegistry->register($workflowDefinition);

            $workflowInstance = $this->workflowManagementService->startWorkflow(
                DefinitionKey::fromString('fanout-workflow'),
            );

            $workflow = $this->workflowRepository->findOrFail($workflowInstance->id);
            expect($workflow->state())->toBe(WorkflowState::Running);

            $stepRun = $this->stepRunRepository->findLatestByWorkflowIdAndStepKey(
                $workflowInstance->id,
                StepKey::fromString('fanout-step'),
            );

            expect($stepRun)->not->toBeNull();
            expect($stepRun->totalJobCount())->toBe(3);

            $jobs = $this->jobLedgerRepository->findByStepRunId($stepRun->id);
            expect($jobs)->toHaveCount(3);

            foreach ($jobs as $job) {
                $job->start('test-worker');
                $job->succeed();
                $this->jobLedgerRepository->save($job);
            }

            $this->advancer->evaluate($workflowInstance->id);

            $finalWorkflow = $this->workflowRepository->findOrFail($workflowInstance->id);
            expect($finalWorkflow->state())->toBe(WorkflowState::Succeeded);

            $finalStepRun = $this->stepRunRepository->findOrFail($stepRun->id);
            expect($finalStepRun->status())->toBe(StepState::Succeeded);

            Event::assertDispatched(StepSucceeded::class);
            Event::assertDispatched(WorkflowSucceeded::class);
        });

        it('handles empty fan-out with zero items', function (): void {
            $items = [];
            $workflowDefinition = createFanOutDefinition($items);
            $this->workflowDefinitionRegistry->register($workflowDefinition);

            $workflowInstance = $this->workflowManagementService->startWorkflow(
                DefinitionKey::fromString('fanout-workflow'),
            );

            $stepRun = $this->stepRunRepository->findLatestByWorkflowIdAndStepKey(
                $workflowInstance->id,
                StepKey::fromString('fanout-step'),
            );

            expect($stepRun->totalJobCount())->toBe(0);

            $this->advancer->evaluate($workflowInstance->id);

            $finalWorkflow = $this->workflowRepository->findOrFail($workflowInstance->id);
            expect($finalWorkflow->state())->toBe(WorkflowState::Succeeded);
        });
    });

    describe('Fan-In Detection', function (): void {
        it('waits for all parallel jobs to complete before advancing', function (): void {
            $items = ['a', 'b', 'c', 'd', 'e'];
            $workflowDefinition = createFanOutDefinition($items);
            $this->workflowDefinitionRegistry->register($workflowDefinition);

            $workflowInstance = $this->workflowManagementService->startWorkflow(
                DefinitionKey::fromString('fanout-workflow'),
            );

            $stepRun = $this->stepRunRepository->findLatestByWorkflowIdAndStepKey(
                $workflowInstance->id,
                StepKey::fromString('fanout-step'),
            );

            $jobs = $this->jobLedgerRepository->findByStepRunId($stepRun->id);

            $jobs->all()[0]->start('worker-1');
            $jobs->all()[0]->succeed();
            $this->jobLedgerRepository->save($jobs->all()[0]);

            $this->advancer->evaluate($workflowInstance->id);

            $workflow = $this->workflowRepository->findOrFail($workflowInstance->id);
            expect($workflow->state())->toBe(WorkflowState::Running);

            $jobs->all()[1]->start('worker-2');
            $jobs->all()[1]->succeed();
            $this->jobLedgerRepository->save($jobs->all()[1]);

            $jobs->all()[2]->start('worker-3');
            $jobs->all()[2]->succeed();
            $this->jobLedgerRepository->save($jobs->all()[2]);

            $this->advancer->evaluate($workflowInstance->id);

            $workflow = $this->workflowRepository->findOrFail($workflowInstance->id);
            expect($workflow->state())->toBe(WorkflowState::Running);

            $jobs->all()[3]->start('worker-4');
            $jobs->all()[3]->succeed();
            $this->jobLedgerRepository->save($jobs->all()[3]);

            $jobs->all()[4]->start('worker-5');
            $jobs->all()[4]->succeed();
            $this->jobLedgerRepository->save($jobs->all()[4]);

            $this->advancer->evaluate($workflowInstance->id);

            $finalWorkflow = $this->workflowRepository->findOrFail($workflowInstance->id);
            expect($finalWorkflow->state())->toBe(WorkflowState::Succeeded);
        });
    });

    describe('Success Criteria', function (): void {
        it('succeeds fan-out step when all jobs succeed with All criteria', function (): void {
            $items = ['x', 'y', 'z'];
            $workflowDefinition = createFanOutWithAllCriteria($items);
            $this->workflowDefinitionRegistry->register($workflowDefinition);

            $workflowInstance = $this->workflowManagementService->startWorkflow(
                DefinitionKey::fromString('fanout-all-criteria'),
            );

            $stepRun = $this->stepRunRepository->findLatestByWorkflowIdAndStepKey(
                $workflowInstance->id,
                StepKey::fromString('fanout-step'),
            );

            $jobs = $this->jobLedgerRepository->findByStepRunId($stepRun->id);

            foreach ($jobs as $job) {
                $job->start('worker');
                $job->succeed();
                $this->jobLedgerRepository->save($job);
            }

            $this->advancer->evaluate($workflowInstance->id);

            $finalWorkflow = $this->workflowRepository->findOrFail($workflowInstance->id);
            expect($finalWorkflow->state())->toBe(WorkflowState::Succeeded);
        });

        it('succeeds fan-out step when majority succeed with Majority criteria', function (): void {
            $items = ['a', 'b', 'c', 'd', 'e'];
            $workflowDefinition = createFanOutWithMajorityCriteria($items);
            $this->workflowDefinitionRegistry->register($workflowDefinition);

            $workflowInstance = $this->workflowManagementService->startWorkflow(
                DefinitionKey::fromString('fanout-majority-criteria'),
            );

            $stepRun = $this->stepRunRepository->findLatestByWorkflowIdAndStepKey(
                $workflowInstance->id,
                StepKey::fromString('fanout-step'),
            );

            $jobs = $this->jobLedgerRepository->findByStepRunId($stepRun->id);

            $jobs->all()[0]->start('worker');
            $jobs->all()[0]->succeed();
            $this->jobLedgerRepository->save($jobs->all()[0]);

            $jobs->all()[1]->start('worker');
            $jobs->all()[1]->succeed();
            $this->jobLedgerRepository->save($jobs->all()[1]);

            $jobs->all()[2]->start('worker');
            $jobs->all()[2]->succeed();
            $this->jobLedgerRepository->save($jobs->all()[2]);

            $jobs->all()[3]->start('worker');
            $jobs->all()[3]->fail('TestException', 'Test failure', 'trace');
            $this->jobLedgerRepository->save($jobs->all()[3]);

            $jobs->all()[4]->start('worker');
            $jobs->all()[4]->fail('TestException', 'Test failure', 'trace');
            $this->jobLedgerRepository->save($jobs->all()[4]);

            $this->advancer->evaluate($workflowInstance->id);

            $finalWorkflow = $this->workflowRepository->findOrFail($workflowInstance->id);
            expect($finalWorkflow->state())->toBe(WorkflowState::Succeeded);

            $finalStepRun = $this->stepRunRepository->findOrFail($stepRun->id);
            expect($finalStepRun->status())->toBe(StepState::Succeeded);
        });

        it('succeeds fan-out step when any job succeeds with BestEffort criteria', function (): void {
            $items = ['1', '2', '3'];
            $workflowDefinition = createFanOutWithBestEffortCriteria($items);
            $this->workflowDefinitionRegistry->register($workflowDefinition);

            $workflowInstance = $this->workflowManagementService->startWorkflow(
                DefinitionKey::fromString('fanout-besteffort-criteria'),
            );

            $stepRun = $this->stepRunRepository->findLatestByWorkflowIdAndStepKey(
                $workflowInstance->id,
                StepKey::fromString('fanout-step'),
            );

            $jobs = $this->jobLedgerRepository->findByStepRunId($stepRun->id);

            $jobs->all()[0]->start('worker');
            $jobs->all()[0]->fail('Exception', 'Failed', 'trace');
            $this->jobLedgerRepository->save($jobs->all()[0]);

            $jobs->all()[1]->start('worker');
            $jobs->all()[1]->succeed();
            $this->jobLedgerRepository->save($jobs->all()[1]);

            $jobs->all()[2]->start('worker');
            $jobs->all()[2]->fail('Exception', 'Failed', 'trace');
            $this->jobLedgerRepository->save($jobs->all()[2]);

            $this->advancer->evaluate($workflowInstance->id);

            $finalWorkflow = $this->workflowRepository->findOrFail($workflowInstance->id);
            expect($finalWorkflow->state())->toBe(WorkflowState::Succeeded);
        });
    });

    describe('Mixed Workflow with Fan-Out', function (): void {
        it('executes workflow with single-job step followed by fan-out step', function (): void {
            $workflowDefinition = createMixedWorkflowDefinition(['item-a', 'item-b']);
            $this->workflowDefinitionRegistry->register($workflowDefinition);

            $workflowInstance = $this->workflowManagementService->startWorkflow(
                DefinitionKey::fromString('mixed-workflow'),
            );

            $step1Run = $this->stepRunRepository->findLatestByWorkflowIdAndStepKey(
                $workflowInstance->id,
                StepKey::fromString('prepare'),
            );

            $step1Jobs = $this->jobLedgerRepository->findByStepRunId($step1Run->id);
            $step1Jobs->all()[0]->start('worker');
            $step1Jobs->all()[0]->succeed();
            $this->jobLedgerRepository->save($step1Jobs->all()[0]);

            $this->advancer->evaluate($workflowInstance->id);

            $workflowAfterStep1 = $this->workflowRepository->findOrFail($workflowInstance->id);
            expect($workflowAfterStep1->currentStepKey()->toString())->toBe('process-items');

            $step2Run = $this->stepRunRepository->findLatestByWorkflowIdAndStepKey(
                $workflowInstance->id,
                StepKey::fromString('process-items'),
            );

            expect($step2Run->totalJobCount())->toBe(2);

            $step2Jobs = $this->jobLedgerRepository->findByStepRunId($step2Run->id);
            foreach ($step2Jobs as $step2Job) {
                $step2Job->start('worker');
                $step2Job->succeed();
                $this->jobLedgerRepository->save($step2Job);
            }

            $this->advancer->evaluate($workflowInstance->id);

            $workflowAfterStep2 = $this->workflowRepository->findOrFail($workflowInstance->id);
            expect($workflowAfterStep2->currentStepKey()->toString())->toBe('finalize');

            $step3Run = $this->stepRunRepository->findLatestByWorkflowIdAndStepKey(
                $workflowInstance->id,
                StepKey::fromString('finalize'),
            );

            $step3Jobs = $this->jobLedgerRepository->findByStepRunId($step3Run->id);
            $step3Jobs->all()[0]->start('worker');
            $step3Jobs->all()[0]->succeed();
            $this->jobLedgerRepository->save($step3Jobs->all()[0]);

            $this->advancer->evaluate($workflowInstance->id);

            $finalWorkflow = $this->workflowRepository->findOrFail($workflowInstance->id);
            expect($finalWorkflow->state())->toBe(WorkflowState::Succeeded);
        });
    });

    describe('Large Fan-Out', function (): void {
        it('handles fan-out with many items', function (): void {
            $items = range(1, 50);
            $workflowDefinition = createFanOutDefinition($items);
            $this->workflowDefinitionRegistry->register($workflowDefinition);

            $workflowInstance = $this->workflowManagementService->startWorkflow(
                DefinitionKey::fromString('fanout-workflow'),
            );

            $stepRun = $this->stepRunRepository->findLatestByWorkflowIdAndStepKey(
                $workflowInstance->id,
                StepKey::fromString('fanout-step'),
            );

            expect($stepRun->totalJobCount())->toBe(50);

            $jobs = $this->jobLedgerRepository->findByStepRunId($stepRun->id);
            expect($jobs)->toHaveCount(50);

            foreach ($jobs as $job) {
                $job->start('worker');
                $job->succeed();
                $this->jobLedgerRepository->save($job);
            }

            $this->advancer->evaluate($workflowInstance->id);

            $finalWorkflow = $this->workflowRepository->findOrFail($workflowInstance->id);
            expect($finalWorkflow->state())->toBe(WorkflowState::Succeeded);
        });
    });
});

function createFanOutDefinition(array $items): WorkflowDefinition
{
    return WorkflowDefinitionBuilder::create('fanout-workflow')
        ->displayName('Fan-Out Workflow')
        ->fanOut('fanout-step', static fn (FanOutStepBuilder $fanOutStepBuilder): FanOutStepBuilder => $fanOutStepBuilder
            ->displayName('Fan-Out Step')
            ->job(ProcessItemJob::class)
            ->iterateOver(static fn (): array => $items)
            ->withJobArguments(static fn ($item): array => ['item' => $item])
            ->requireAllSuccess())
        ->build();
}

function createFanOutWithAllCriteria(array $items): WorkflowDefinition
{
    return WorkflowDefinitionBuilder::create('fanout-all-criteria')
        ->displayName('Fan-Out All Criteria Workflow')
        ->fanOut('fanout-step', static fn (FanOutStepBuilder $fanOutStepBuilder): FanOutStepBuilder => $fanOutStepBuilder
            ->displayName('Fan-Out Step')
            ->job(ProcessItemJob::class)
            ->iterateOver(static fn (): array => $items)
            ->withJobArguments(static fn ($item): array => ['item' => $item])
            ->requireAllSuccess())
        ->build();
}

function createFanOutWithMajorityCriteria(array $items): WorkflowDefinition
{
    return WorkflowDefinitionBuilder::create('fanout-majority-criteria')
        ->displayName('Fan-Out Majority Criteria Workflow')
        ->fanOut('fanout-step', static fn (FanOutStepBuilder $fanOutStepBuilder): FanOutStepBuilder => $fanOutStepBuilder
            ->displayName('Fan-Out Step')
            ->job(ProcessItemJob::class)
            ->iterateOver(static fn (): array => $items)
            ->withJobArguments(static fn ($item): array => ['item' => $item])
            ->requireMajority()
            ->continueWithPartial())
        ->build();
}

function createFanOutWithBestEffortCriteria(array $items): WorkflowDefinition
{
    return WorkflowDefinitionBuilder::create('fanout-besteffort-criteria')
        ->displayName('Fan-Out Best Effort Criteria Workflow')
        ->fanOut('fanout-step', static fn (FanOutStepBuilder $fanOutStepBuilder): FanOutStepBuilder => $fanOutStepBuilder
            ->displayName('Fan-Out Step')
            ->job(ProcessItemJob::class)
            ->iterateOver(static fn (): array => $items)
            ->withJobArguments(static fn ($item): array => ['item' => $item])
            ->requireAny()
            ->continueWithPartial())
        ->build();
}

function createMixedWorkflowDefinition(array $items): WorkflowDefinition
{
    return WorkflowDefinitionBuilder::create('mixed-workflow')
        ->displayName('Mixed Workflow')
        ->singleJob('prepare', static fn (SingleJobStepBuilder $singleJobStepBuilder): SingleJobStepBuilder => $singleJobStepBuilder
            ->displayName('Prepare')
            ->job(TestJob::class))
        ->fanOut('process-items', static fn (FanOutStepBuilder $fanOutStepBuilder): FanOutStepBuilder => $fanOutStepBuilder
            ->displayName('Process Items')
            ->job(ProcessItemJob::class)
            ->iterateOver(static fn (): array => $items)
            ->withJobArguments(static fn ($item): array => ['item' => $item])
            ->requireAllSuccess())
        ->singleJob('finalize', static fn (SingleJobStepBuilder $singleJobStepBuilder): SingleJobStepBuilder => $singleJobStepBuilder
            ->displayName('Finalize')
            ->job(TestJob::class))
        ->build();
}
