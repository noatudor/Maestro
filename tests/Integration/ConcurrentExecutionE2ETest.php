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

describe('Concurrent Execution E2E Tests', function (): void {
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

    describe('Multiple Independent Workflows', function (): void {
        it('runs multiple workflows concurrently without interference', function (): void {
            $workflowDefinition = createConcurrentTestDefinition();
            $this->workflowDefinitionRegistry->register($workflowDefinition);

            $workflows = [];
            for ($i = 0; $i < 5; $i++) {
                $workflows[] = $this->workflowManagementService->startWorkflow(
                    DefinitionKey::fromString('concurrent-workflow'),
                );
            }

            expect($workflows)->toHaveCount(5);

            foreach ($workflows as $wfInstance) {
                $workflow = $this->workflowRepository->findOrFail($wfInstance->id);
                expect($workflow->state())->toBe(WorkflowState::Running);

                $stepRun = $this->stepRunRepository->findLatestByWorkflowIdAndStepKey(
                    $wfInstance->id,
                    StepKey::fromString('step-1'),
                );
                expect($stepRun)->not->toBeNull();
            }

            foreach ($workflows as $workflow) {
                $stepRun = $this->stepRunRepository->findLatestByWorkflowIdAndStepKey(
                    $workflow->id,
                    StepKey::fromString('step-1'),
                );

                $jobs = $this->jobLedgerRepository->findByStepRunId($stepRun->id);
                $jobs->all()[0]->start('worker-'.$workflow->id->value);
                $jobs->all()[0]->succeed();
                $this->jobLedgerRepository->save($jobs->all()[0]);

                $this->advancer->evaluate($workflow->id);
            }

            foreach ($workflows as $workflow) {
                $finalWorkflow = $this->workflowRepository->findOrFail($workflow->id);
                expect($finalWorkflow->state())->toBe(WorkflowState::Succeeded);
            }
        });

        it('handles mixed workflow states correctly', function (): void {
            $workflowDefinition = createConcurrentTestDefinition();
            $this->workflowDefinitionRegistry->register($workflowDefinition);

            $workflow1 = $this->workflowManagementService->startWorkflow(
                DefinitionKey::fromString('concurrent-workflow'),
            );
            $workflow2 = $this->workflowManagementService->startWorkflow(
                DefinitionKey::fromString('concurrent-workflow'),
            );
            $workflow3 = $this->workflowManagementService->startWorkflow(
                DefinitionKey::fromString('concurrent-workflow'),
            );

            $step1Run = $this->stepRunRepository->findLatestByWorkflowIdAndStepKey(
                $workflow1->id,
                StepKey::fromString('step-1'),
            );
            $jobs1 = $this->jobLedgerRepository->findByStepRunId($step1Run->id);
            $jobs1->all()[0]->start('worker');
            $jobs1->all()[0]->succeed();
            $this->jobLedgerRepository->save($jobs1->all()[0]);
            $this->advancer->evaluate($workflow1->id);

            $this->workflowManagementService->pauseWorkflow($workflow2->id, 'Paused for testing');

            $this->workflowManagementService->cancelWorkflow($workflow3->id);

            $final1 = $this->workflowRepository->findOrFail($workflow1->id);
            $final2 = $this->workflowRepository->findOrFail($workflow2->id);
            $final3 = $this->workflowRepository->findOrFail($workflow3->id);

            expect($final1->state())->toBe(WorkflowState::Succeeded);
            expect($final2->state())->toBe(WorkflowState::Paused);
            expect($final3->state())->toBe(WorkflowState::Cancelled);
        });
    });

    describe('Concurrent Fan-In Detection', function (): void {
        it('handles concurrent job completions in fan-out step', function (): void {
            $items = range(1, 10);
            $workflowDefinition = createConcurrentFanOutDefinition($items);
            $this->workflowDefinitionRegistry->register($workflowDefinition);

            $workflowInstance = $this->workflowManagementService->startWorkflow(
                DefinitionKey::fromString('concurrent-fanout'),
            );

            $stepRun = $this->stepRunRepository->findLatestByWorkflowIdAndStepKey(
                $workflowInstance->id,
                StepKey::fromString('fanout-step'),
            );

            $jobs = $this->jobLedgerRepository->findByStepRunId($stepRun->id);
            expect($jobs)->toHaveCount(10);

            $completedCount = 0;
            foreach ($jobs as $index => $job) {
                $job->start('worker-'.$index);
                $job->succeed();
                $this->jobLedgerRepository->save($job);
                $completedCount++;

                $this->advancer->evaluate($workflowInstance->id);

                $currentStepRun = $this->stepRunRepository->findOrFail($stepRun->id);

                if ($completedCount < 10) {
                    expect($currentStepRun->status())->toBe(StepState::Running);
                }
            }

            $finalWorkflow = $this->workflowRepository->findOrFail($workflowInstance->id);
            expect($finalWorkflow->state())->toBe(WorkflowState::Succeeded);
        });

        it('correctly detects fan-in when jobs complete out of order', function (): void {
            $items = ['a', 'b', 'c', 'd', 'e'];
            $workflowDefinition = createConcurrentFanOutDefinition($items);
            $this->workflowDefinitionRegistry->register($workflowDefinition);

            $workflowInstance = $this->workflowManagementService->startWorkflow(
                DefinitionKey::fromString('concurrent-fanout'),
            );

            $stepRun = $this->stepRunRepository->findLatestByWorkflowIdAndStepKey(
                $workflowInstance->id,
                StepKey::fromString('fanout-step'),
            );

            $jobs = $this->jobLedgerRepository->findByStepRunId($stepRun->id);

            $completionOrder = [4, 1, 3, 0, 2];

            foreach ($completionOrder as $index => $jobIndex) {
                $jobs->all()[$jobIndex]->start('worker-'.$jobIndex);
                $jobs->all()[$jobIndex]->succeed();
                $this->jobLedgerRepository->save($jobs->all()[$jobIndex]);

                $this->advancer->evaluate($workflowInstance->id);

                $currentWorkflow = $this->workflowRepository->findOrFail($workflowInstance->id);

                if ($index < 4) {
                    expect($currentWorkflow->state())->toBe(WorkflowState::Running);
                } else {
                    expect($currentWorkflow->state())->toBe(WorkflowState::Succeeded);
                }
            }
        });
    });

    describe('Idempotent Evaluation', function (): void {
        it('multiple evaluations produce same result for completed workflow', function (): void {
            $workflowDefinition = createConcurrentTestDefinition();
            $this->workflowDefinitionRegistry->register($workflowDefinition);

            $workflowInstance = $this->workflowManagementService->startWorkflow(
                DefinitionKey::fromString('concurrent-workflow'),
            );

            $stepRun = $this->stepRunRepository->findLatestByWorkflowIdAndStepKey(
                $workflowInstance->id,
                StepKey::fromString('step-1'),
            );

            $jobs = $this->jobLedgerRepository->findByStepRunId($stepRun->id);
            $jobs->all()[0]->start('worker');
            $jobs->all()[0]->succeed();
            $this->jobLedgerRepository->save($jobs->all()[0]);

            $this->advancer->evaluate($workflowInstance->id);
            $afterFirst = $this->workflowRepository->findOrFail($workflowInstance->id);

            $this->advancer->evaluate($workflowInstance->id);
            $afterSecond = $this->workflowRepository->findOrFail($workflowInstance->id);

            $this->advancer->evaluate($workflowInstance->id);
            $afterThird = $this->workflowRepository->findOrFail($workflowInstance->id);

            expect($afterFirst->state())->toBe(WorkflowState::Succeeded);
            expect($afterSecond->state())->toBe(WorkflowState::Succeeded);
            expect($afterThird->state())->toBe(WorkflowState::Succeeded);

            $stepRuns = $this->stepRunRepository->findByWorkflowId($workflowInstance->id);
            expect($stepRuns)->toHaveCount(1);
        });

        it('does not create duplicate step runs on concurrent evaluations', function (): void {
            $workflowDefinition = createTwoStepConcurrentDefinition();
            $this->workflowDefinitionRegistry->register($workflowDefinition);

            $workflowInstance = $this->workflowManagementService->startWorkflow(
                DefinitionKey::fromString('two-step-concurrent'),
            );

            $step1Run = $this->stepRunRepository->findLatestByWorkflowIdAndStepKey(
                $workflowInstance->id,
                StepKey::fromString('step-1'),
            );

            $jobs = $this->jobLedgerRepository->findByStepRunId($step1Run->id);
            $jobs->all()[0]->start('worker');
            $jobs->all()[0]->succeed();
            $this->jobLedgerRepository->save($jobs->all()[0]);

            $this->advancer->evaluate($workflowInstance->id);

            $step2Run = $this->stepRunRepository->findLatestByWorkflowIdAndStepKey(
                $workflowInstance->id,
                StepKey::fromString('step-2'),
            );

            expect($step2Run)->not->toBeNull();

            $this->advancer->evaluate($workflowInstance->id);
            $this->advancer->evaluate($workflowInstance->id);

            $allStepRuns = $this->stepRunRepository->findByWorkflowId($workflowInstance->id);
            $step2Runs = array_filter(
                iterator_to_array($allStepRuns),
                static fn ($run): bool => $run->stepKey->toString() === 'step-2',
            );

            expect($step2Runs)->toHaveCount(1);
        });
    });

    describe('State Consistency', function (): void {
        it('maintains workflow state consistency under concurrent operations', function (): void {
            $workflowDefinition = createConcurrentTestDefinition();
            $this->workflowDefinitionRegistry->register($workflowDefinition);

            $workflowInstance = $this->workflowManagementService->startWorkflow(
                DefinitionKey::fromString('concurrent-workflow'),
            );

            $initialWorkflow = $this->workflowRepository->findOrFail($workflowInstance->id);
            expect($initialWorkflow->state())->toBe(WorkflowState::Running);
            expect($initialWorkflow->currentStepKey()->toString())->toBe('step-1');

            for ($i = 0; $i < 5; $i++) {
                $workflow = $this->workflowRepository->findOrFail($workflowInstance->id);

                if ($workflow->state() === WorkflowState::Running) {
                    expect($workflow->currentStepKey())->not->toBeNull();
                }
            }

            $stepRun = $this->stepRunRepository->findLatestByWorkflowIdAndStepKey(
                $workflowInstance->id,
                StepKey::fromString('step-1'),
            );

            $jobs = $this->jobLedgerRepository->findByStepRunId($stepRun->id);
            $jobs->all()[0]->start('worker');
            $jobs->all()[0]->succeed();
            $this->jobLedgerRepository->save($jobs->all()[0]);

            $this->advancer->evaluate($workflowInstance->id);

            $finalWorkflow = $this->workflowRepository->findOrFail($workflowInstance->id);
            expect($finalWorkflow->state())->toBe(WorkflowState::Succeeded);
        });

        it('handles rapid state transitions correctly', function (): void {
            $workflowDefinition = createThreeStepConcurrentDefinition();
            $this->workflowDefinitionRegistry->register($workflowDefinition);

            $workflowInstance = $this->workflowManagementService->startWorkflow(
                DefinitionKey::fromString('three-step-concurrent'),
            );

            $steps = ['step-1', 'step-2', 'step-3'];

            foreach ($steps as $step) {
                $stepRun = $this->stepRunRepository->findLatestByWorkflowIdAndStepKey(
                    $workflowInstance->id,
                    StepKey::fromString($step),
                );

                $jobs = $this->jobLedgerRepository->findByStepRunId($stepRun->id);
                $jobs->all()[0]->start('worker');
                $jobs->all()[0]->succeed();
                $this->jobLedgerRepository->save($jobs->all()[0]);

                $this->advancer->evaluate($workflowInstance->id);
            }

            $finalWorkflow = $this->workflowRepository->findOrFail($workflowInstance->id);
            expect($finalWorkflow->state())->toBe(WorkflowState::Succeeded);

            $allStepRuns = $this->stepRunRepository->findByWorkflowId($workflowInstance->id);
            expect(iterator_to_array($allStepRuns))->toHaveCount(3);

            foreach ($allStepRuns as $allStepRun) {
                expect($allStepRun->status())->toBe(StepState::Succeeded);
            }
        });
    });
});

function createConcurrentTestDefinition(): WorkflowDefinition
{
    return WorkflowDefinitionBuilder::create('concurrent-workflow')
        ->displayName('Concurrent Test Workflow')
        ->singleJob('step-1', static fn (SingleJobStepBuilder $singleJobStepBuilder): SingleJobStepBuilder => $singleJobStepBuilder
            ->displayName('Step 1')
            ->job(TestJob::class))
        ->build();
}

function createTwoStepConcurrentDefinition(): WorkflowDefinition
{
    return WorkflowDefinitionBuilder::create('two-step-concurrent')
        ->displayName('Two Step Concurrent Workflow')
        ->singleJob('step-1', static fn (SingleJobStepBuilder $singleJobStepBuilder): SingleJobStepBuilder => $singleJobStepBuilder
            ->displayName('Step 1')
            ->job(TestJob::class))
        ->singleJob('step-2', static fn (SingleJobStepBuilder $singleJobStepBuilder): SingleJobStepBuilder => $singleJobStepBuilder
            ->displayName('Step 2')
            ->job(TestJob::class))
        ->build();
}

function createThreeStepConcurrentDefinition(): WorkflowDefinition
{
    return WorkflowDefinitionBuilder::create('three-step-concurrent')
        ->displayName('Three Step Concurrent Workflow')
        ->singleJob('step-1', static fn (SingleJobStepBuilder $singleJobStepBuilder): SingleJobStepBuilder => $singleJobStepBuilder
            ->displayName('Step 1')
            ->job(TestJob::class))
        ->singleJob('step-2', static fn (SingleJobStepBuilder $singleJobStepBuilder): SingleJobStepBuilder => $singleJobStepBuilder
            ->displayName('Step 2')
            ->job(TestJob::class))
        ->singleJob('step-3', static fn (SingleJobStepBuilder $singleJobStepBuilder): SingleJobStepBuilder => $singleJobStepBuilder
            ->displayName('Step 3')
            ->job(TestJob::class))
        ->build();
}

function createConcurrentFanOutDefinition(array $items): WorkflowDefinition
{
    return WorkflowDefinitionBuilder::create('concurrent-fanout')
        ->displayName('Concurrent Fan-Out Workflow')
        ->fanOut('fanout-step', static fn (FanOutStepBuilder $fanOutStepBuilder): FanOutStepBuilder => $fanOutStepBuilder
            ->displayName('Fan-Out Step')
            ->job(ProcessItemJob::class)
            ->iterateOver(static fn (): array => $items)
            ->withJobArguments(static fn ($item): array => ['item' => $item])
            ->requireAllSuccess())
        ->build();
}
