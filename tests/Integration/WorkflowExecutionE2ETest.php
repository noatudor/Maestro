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
use Maestro\Workflow\Definition\Builders\SingleJobStepBuilder;
use Maestro\Workflow\Definition\Builders\WorkflowDefinitionBuilder;
use Maestro\Workflow\Definition\WorkflowDefinition;
use Maestro\Workflow\Definition\WorkflowDefinitionRegistry;
use Maestro\Workflow\Domain\Events\WorkflowCreated;
use Maestro\Workflow\Domain\Events\WorkflowStarted;
use Maestro\Workflow\Domain\Events\WorkflowSucceeded;
use Maestro\Workflow\Enums\JobState;
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
use Maestro\Workflow\Tests\Fixtures\Jobs\TestJob;
use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\StepKey;

describe('End-to-End Workflow Execution', function (): void {
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

    describe('Single Step Workflow', function (): void {
        it('executes a complete single-step workflow from start to success', function (): void {
            $workflowDefinition = createSingleStepDefinition();
            $this->workflowDefinitionRegistry->register($workflowDefinition);

            $workflowInstance = $this->workflowManagementService->startWorkflow(
                DefinitionKey::fromString('single-step-workflow'),
            );

            $workflow = $this->workflowRepository->findOrFail($workflowInstance->id);
            expect($workflow->state())->toBe(WorkflowState::Running);

            $stepRun = $this->stepRunRepository->findLatestByWorkflowIdAndStepKey(
                $workflowInstance->id,
                $workflowDefinition->getFirstStep()->key(),
            );

            expect($stepRun)->not->toBeNull();
            expect($stepRun->status())->toBe(StepState::Running);

            $jobs = $this->jobLedgerRepository->findByStepRunId($stepRun->id);
            expect($jobs)->toHaveCount(1);

            $job = $jobs->all()[0];
            $job->start('test-worker');
            $job->succeed();
            $this->jobLedgerRepository->save($job);

            $this->advancer->evaluate($workflowInstance->id);

            $updatedWorkflow = $this->workflowRepository->findOrFail($workflowInstance->id);
            expect($updatedWorkflow->state())->toBe(WorkflowState::Succeeded);

            $updatedStepRun = $this->stepRunRepository->findOrFail($stepRun->id);
            expect($updatedStepRun->status())->toBe(StepState::Succeeded);

            Event::assertDispatched(WorkflowCreated::class);
            Event::assertDispatched(WorkflowStarted::class);
            Event::assertDispatched(WorkflowSucceeded::class);
        });
    });

    describe('Multi-Step Workflow', function (): void {
        it('executes a two-step workflow sequentially', function (): void {
            $workflowDefinition = createTwoStepDefinition();
            $this->workflowDefinitionRegistry->register($workflowDefinition);

            $workflowInstance = $this->workflowManagementService->startWorkflow(
                DefinitionKey::fromString('two-step-workflow'),
            );

            $workflow = $this->workflowRepository->findOrFail($workflowInstance->id);
            expect($workflow->state())->toBe(WorkflowState::Running);
            expect($workflow->currentStepKey()->toString())->toBe('step-1');

            $stepRun1 = $this->stepRunRepository->findLatestByWorkflowIdAndStepKey(
                $workflowInstance->id,
                $workflowDefinition->getFirstStep()->key(),
            );

            $jobs1 = $this->jobLedgerRepository->findByStepRunId($stepRun1->id);
            $job1 = $jobs1->all()[0];
            $job1->start('test-worker');
            $job1->succeed();
            $this->jobLedgerRepository->save($job1);

            $this->advancer->evaluate($workflowInstance->id);

            $workflowAfterStep1 = $this->workflowRepository->findOrFail($workflowInstance->id);
            expect($workflowAfterStep1->state())->toBe(WorkflowState::Running);
            expect($workflowAfterStep1->currentStepKey()->toString())->toBe('step-2');

            $stepRun2 = $this->stepRunRepository->findLatestByWorkflowIdAndStepKey(
                $workflowInstance->id,
                $workflowDefinition->getNextStep($workflowDefinition->getFirstStep()->key())->key(),
            );

            $jobs2 = $this->jobLedgerRepository->findByStepRunId($stepRun2->id);
            $job2 = $jobs2->all()[0];
            $job2->start('test-worker');
            $job2->succeed();
            $this->jobLedgerRepository->save($job2);

            $this->advancer->evaluate($workflowInstance->id);

            $finalWorkflow = $this->workflowRepository->findOrFail($workflowInstance->id);
            expect($finalWorkflow->state())->toBe(WorkflowState::Succeeded);
        });

        it('executes a three-step workflow to completion', function (): void {
            $workflowDefinition = createThreeStepDefinition();
            $this->workflowDefinitionRegistry->register($workflowDefinition);

            $workflowInstance = $this->workflowManagementService->startWorkflow(
                DefinitionKey::fromString('three-step-workflow'),
            );

            $steps = ['step-1', 'step-2', 'step-3'];

            foreach ($steps as $index => $stepKey) {
                $stepRun = $this->stepRunRepository->findLatestByWorkflowIdAndStepKey(
                    $workflowInstance->id,
                    StepKey::fromString($stepKey),
                );

                $jobs = $this->jobLedgerRepository->findByStepRunId($stepRun->id);
                $job = $jobs->all()[0];
                $job->start('test-worker');
                $job->succeed();
                $this->jobLedgerRepository->save($job);

                $this->advancer->evaluate($workflowInstance->id);

                $currentWorkflow = $this->workflowRepository->findOrFail($workflowInstance->id);

                if ($index < count($steps) - 1) {
                    expect($currentWorkflow->state())->toBe(WorkflowState::Running);
                    expect($currentWorkflow->currentStepKey()->toString())->toBe($steps[$index + 1]);
                } else {
                    expect($currentWorkflow->state())->toBe(WorkflowState::Succeeded);
                }
            }
        });
    });

    describe('Workflow Lifecycle Management', function (): void {
        it('pauses and resumes a workflow correctly', function (): void {
            $workflowDefinition = createTwoStepDefinition();
            $this->workflowDefinitionRegistry->register($workflowDefinition);

            $workflowInstance = $this->workflowManagementService->startWorkflow(
                DefinitionKey::fromString('two-step-workflow'),
            );

            $stepRun1 = $this->stepRunRepository->findLatestByWorkflowIdAndStepKey(
                $workflowInstance->id,
                $workflowDefinition->getFirstStep()->key(),
            );
            $jobs1 = $this->jobLedgerRepository->findByStepRunId($stepRun1->id);
            $job1 = $jobs1->all()[0];
            $job1->start('test-worker');
            $job1->succeed();
            $this->jobLedgerRepository->save($job1);

            $this->advancer->evaluate($workflowInstance->id);

            $workflowAfterStep1 = $this->workflowRepository->findOrFail($workflowInstance->id);
            $this->workflowManagementService->pauseWorkflow($workflowAfterStep1->id, 'Manual pause for testing');

            $pausedWorkflow = $this->workflowRepository->findOrFail($workflowInstance->id);
            expect($pausedWorkflow->state())->toBe(WorkflowState::Paused);

            $this->workflowManagementService->resumeWorkflow($workflowInstance->id);

            $resumedWorkflow = $this->workflowRepository->findOrFail($workflowInstance->id);
            expect($resumedWorkflow->state())->toBe(WorkflowState::Running);
        });

        it('cancels a running workflow', function (): void {
            $workflowDefinition = createSingleStepDefinition();
            $this->workflowDefinitionRegistry->register($workflowDefinition);

            $workflowInstance = $this->workflowManagementService->startWorkflow(
                DefinitionKey::fromString('single-step-workflow'),
            );

            $workflow = $this->workflowRepository->findOrFail($workflowInstance->id);
            expect($workflow->state())->toBe(WorkflowState::Running);

            $this->workflowManagementService->cancelWorkflow($workflowInstance->id);

            $cancelledWorkflow = $this->workflowRepository->findOrFail($workflowInstance->id);
            expect($cancelledWorkflow->state())->toBe(WorkflowState::Cancelled);
        });
    });

    describe('Edge Cases', function (): void {
        it('handles workflow with a single step that succeeds immediately', function (): void {
            $workflowDefinition = createSingleStepDefinition();
            $this->workflowDefinitionRegistry->register($workflowDefinition);

            $workflowInstance = $this->workflowManagementService->startWorkflow(
                DefinitionKey::fromString('single-step-workflow'),
            );

            $stepRun = $this->stepRunRepository->findLatestByWorkflowIdAndStepKey(
                $workflowInstance->id,
                $workflowDefinition->getFirstStep()->key(),
            );

            $jobs = $this->jobLedgerRepository->findByStepRunId($stepRun->id);
            $job = $jobs->all()[0];

            $job->start('worker');
            $job->succeed();
            $this->jobLedgerRepository->save($job);

            $this->advancer->evaluate($workflowInstance->id);
            $this->advancer->evaluate($workflowInstance->id);
            $this->advancer->evaluate($workflowInstance->id);

            $finalWorkflow = $this->workflowRepository->findOrFail($workflowInstance->id);
            expect($finalWorkflow->state())->toBe(WorkflowState::Succeeded);

            $stepRuns = $this->stepRunRepository->findByWorkflowId($workflowInstance->id);
            expect($stepRuns)->toHaveCount(1);
        });

        it('verifies job state transitions are properly recorded', function (): void {
            $workflowDefinition = createSingleStepDefinition();
            $this->workflowDefinitionRegistry->register($workflowDefinition);

            $workflowInstance = $this->workflowManagementService->startWorkflow(
                DefinitionKey::fromString('single-step-workflow'),
            );

            $stepRun = $this->stepRunRepository->findLatestByWorkflowIdAndStepKey(
                $workflowInstance->id,
                $workflowDefinition->getFirstStep()->key(),
            );

            $jobs = $this->jobLedgerRepository->findByStepRunId($stepRun->id);
            $job = $jobs->all()[0];

            expect($job->status())->toBe(JobState::Dispatched);

            $job->start('worker-1');
            $this->jobLedgerRepository->save($job);

            $reloadedJob = $this->jobLedgerRepository->findByJobUuid($job->jobUuid);
            expect($reloadedJob->status())->toBe(JobState::Running);
            expect($reloadedJob->workerId())->toBe('worker-1');

            $reloadedJob->succeed();
            $this->jobLedgerRepository->save($reloadedJob);

            $finalJob = $this->jobLedgerRepository->findByJobUuid($job->jobUuid);
            expect($finalJob->status())->toBe(JobState::Succeeded);
        });
    });
});

function createSingleStepDefinition(): WorkflowDefinition
{
    return WorkflowDefinitionBuilder::create('single-step-workflow')
        ->displayName('Single Step Workflow')
        ->singleJob('step-1', static fn (SingleJobStepBuilder $singleJobStepBuilder): SingleJobStepBuilder => $singleJobStepBuilder
            ->displayName('Step 1')
            ->job(TestJob::class))
        ->build();
}

function createTwoStepDefinition(): WorkflowDefinition
{
    return WorkflowDefinitionBuilder::create('two-step-workflow')
        ->displayName('Two Step Workflow')
        ->singleJob('step-1', static fn (SingleJobStepBuilder $singleJobStepBuilder): SingleJobStepBuilder => $singleJobStepBuilder
            ->displayName('Step 1')
            ->job(TestJob::class))
        ->singleJob('step-2', static fn (SingleJobStepBuilder $singleJobStepBuilder): SingleJobStepBuilder => $singleJobStepBuilder
            ->displayName('Step 2')
            ->job(TestJob::class))
        ->build();
}

function createThreeStepDefinition(): WorkflowDefinition
{
    return WorkflowDefinitionBuilder::create('three-step-workflow')
        ->displayName('Three Step Workflow')
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
