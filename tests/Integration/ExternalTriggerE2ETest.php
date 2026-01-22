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
use Maestro\Workflow\Application\Orchestration\ExternalTriggerHandler;
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
use Maestro\Workflow\Domain\Events\WorkflowSucceeded;
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
use Maestro\Workflow\ValueObjects\TriggerPayload;

describe('External Trigger E2E Tests', function (): void {
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

        $this->externalTriggerHandler = new ExternalTriggerHandler(
            $this->workflowRepository,
            $this->advancer,
        );
    });

    describe('Complete External Trigger Flow', function (): void {
        it('completes workflow: start -> pause -> external trigger -> resume -> complete', function (): void {
            $workflowDefinition = createTwoStepDefinitionForTrigger();
            $this->workflowDefinitionRegistry->register($workflowDefinition);

            $workflowInstance = $this->workflowManagementService->startWorkflow(
                DefinitionKey::fromString('trigger-workflow'),
            );

            $workflow = $this->workflowRepository->findOrFail($workflowInstance->id);
            expect($workflow->state())->toBe(WorkflowState::Running);
            expect($workflow->currentStepKey()->toString())->toBe('first-step');

            $step1Run = $this->stepRunRepository->findLatestByWorkflowIdAndStepKey(
                $workflowInstance->id,
                StepKey::fromString('first-step'),
            );
            $jobs1 = $this->jobLedgerRepository->findByStepRunId($step1Run->id);
            $jobs1->all()[0]->start('worker');
            $jobs1->all()[0]->succeed();
            $this->jobLedgerRepository->save($jobs1->all()[0]);

            $this->advancer->evaluate($workflowInstance->id);

            $workflowBeforePause = $this->workflowRepository->findOrFail($workflowInstance->id);
            expect($workflowBeforePause->currentStepKey()->toString())->toBe('awaiting-approval');

            $this->workflowManagementService->pauseWorkflow(
                $workflowInstance->id,
                'Waiting for external approval',
            );

            $pausedWorkflow = $this->workflowRepository->findOrFail($workflowInstance->id);
            expect($pausedWorkflow->state())->toBe(WorkflowState::Paused);

            $triggerResult = $this->externalTriggerHandler->handleTrigger(
                $workflowInstance->id,
                'approval_received',
                TriggerPayload::fromArray(['approved_by' => 'admin@example.com']),
            );

            expect($triggerResult->isSuccess())->toBeTrue();
            expect($triggerResult->triggerType())->toBe('approval_received');

            $resumedWorkflow = $this->workflowRepository->findOrFail($workflowInstance->id);
            expect($resumedWorkflow->state())->toBe(WorkflowState::Running);

            $step2Run = $this->stepRunRepository->findLatestByWorkflowIdAndStepKey(
                $workflowInstance->id,
                StepKey::fromString('awaiting-approval'),
            );
            $jobs2 = $this->jobLedgerRepository->findByStepRunId($step2Run->id);
            $jobs2->all()[0]->start('worker');
            $jobs2->all()[0]->succeed();
            $this->jobLedgerRepository->save($jobs2->all()[0]);

            $this->advancer->evaluate($workflowInstance->id);

            $finalWorkflow = $this->workflowRepository->findOrFail($workflowInstance->id);
            expect($finalWorkflow->state())->toBe(WorkflowState::Succeeded);

            Event::assertDispatched(WorkflowSucceeded::class);
        });
    });

    describe('Trigger on Terminal Workflow', function (): void {
        it('returns terminal status when triggering completed workflow', function (): void {
            $workflowDefinition = createSingleStepDefinitionForTrigger();
            $this->workflowDefinitionRegistry->register($workflowDefinition);

            $workflowInstance = $this->workflowManagementService->startWorkflow(
                DefinitionKey::fromString('single-step-trigger'),
            );

            $stepRun = $this->stepRunRepository->findLatestByWorkflowIdAndStepKey(
                $workflowInstance->id,
                StepKey::fromString('only-step'),
            );
            $jobs = $this->jobLedgerRepository->findByStepRunId($stepRun->id);
            $jobs->all()[0]->start('worker');
            $jobs->all()[0]->succeed();
            $this->jobLedgerRepository->save($jobs->all()[0]);

            $this->advancer->evaluate($workflowInstance->id);

            $completedWorkflow = $this->workflowRepository->findOrFail($workflowInstance->id);
            expect($completedWorkflow->state())->toBe(WorkflowState::Succeeded);

            $triggerResult = $this->externalTriggerHandler->handleTrigger(
                $workflowInstance->id,
                'late_trigger',
            );

            expect($triggerResult->isSuccess())->toBeFalse();
            expect($triggerResult->isTerminal())->toBeTrue();
            expect($triggerResult->failureReason())->toContain('terminal state');
        });

        it('returns terminal status when triggering cancelled workflow', function (): void {
            $workflowDefinition = createSingleStepDefinitionForTrigger();
            $this->workflowDefinitionRegistry->register($workflowDefinition);

            $workflowInstance = $this->workflowManagementService->startWorkflow(
                DefinitionKey::fromString('single-step-trigger'),
            );

            $this->workflowManagementService->cancelWorkflow($workflowInstance->id);

            $triggerResult = $this->externalTriggerHandler->handleTrigger(
                $workflowInstance->id,
                'after_cancel',
            );

            expect($triggerResult->isSuccess())->toBeFalse();
            expect($triggerResult->workflow()->state())->toBe(WorkflowState::Cancelled);
        });
    });

    describe('Trigger on Running Workflow', function (): void {
        it('advances running workflow without changing state', function (): void {
            $workflowDefinition = createTwoStepDefinitionForTrigger();
            $this->workflowDefinitionRegistry->register($workflowDefinition);

            $workflowInstance = $this->workflowManagementService->startWorkflow(
                DefinitionKey::fromString('trigger-workflow'),
            );

            $triggerResult = $this->externalTriggerHandler->handleTrigger(
                $workflowInstance->id,
                'early_trigger',
            );

            expect($triggerResult->isSuccess())->toBeTrue();
            expect($triggerResult->workflow()->state())->toBe(WorkflowState::Running);
        });
    });

    describe('Resume and Advance', function (): void {
        it('resumes paused workflow and advances to next step', function (): void {
            $workflowDefinition = createThreeStepDefinitionForTrigger();
            $this->workflowDefinitionRegistry->register($workflowDefinition);

            $workflowInstance = $this->workflowManagementService->startWorkflow(
                DefinitionKey::fromString('three-step-trigger'),
            );

            $step1Run = $this->stepRunRepository->findLatestByWorkflowIdAndStepKey(
                $workflowInstance->id,
                StepKey::fromString('step-1'),
            );
            $jobs1 = $this->jobLedgerRepository->findByStepRunId($step1Run->id);
            $jobs1->all()[0]->start('worker');
            $jobs1->all()[0]->succeed();
            $this->jobLedgerRepository->save($jobs1->all()[0]);

            $this->advancer->evaluate($workflowInstance->id);

            $this->workflowManagementService->pauseWorkflow($workflowInstance->id, 'Pause for review');

            $pausedWorkflow = $this->workflowRepository->findOrFail($workflowInstance->id);
            expect($pausedWorkflow->state())->toBe(WorkflowState::Paused);
            expect($pausedWorkflow->currentStepKey()->toString())->toBe('step-2');

            $resumedWorkflow = $this->externalTriggerHandler->resumeAndAdvance($workflowInstance->id);

            expect($resumedWorkflow->state())->toBe(WorkflowState::Running);
        });
    });

    describe('Trigger Evaluation', function (): void {
        it('triggers evaluation without state change for non-paused workflow', function (): void {
            $workflowDefinition = createSingleStepDefinitionForTrigger();
            $this->workflowDefinitionRegistry->register($workflowDefinition);

            $workflowInstance = $this->workflowManagementService->startWorkflow(
                DefinitionKey::fromString('single-step-trigger'),
            );

            $beforeTrigger = $this->workflowRepository->findOrFail($workflowInstance->id);
            expect($beforeTrigger->state())->toBe(WorkflowState::Running);

            $afterTrigger = $this->externalTriggerHandler->triggerEvaluation($workflowInstance->id);

            expect($afterTrigger->state())->toBe(WorkflowState::Running);
        });

        it('completes workflow when trigger evaluation finds completed jobs', function (): void {
            $workflowDefinition = createSingleStepDefinitionForTrigger();
            $this->workflowDefinitionRegistry->register($workflowDefinition);

            $workflowInstance = $this->workflowManagementService->startWorkflow(
                DefinitionKey::fromString('single-step-trigger'),
            );

            $stepRun = $this->stepRunRepository->findLatestByWorkflowIdAndStepKey(
                $workflowInstance->id,
                StepKey::fromString('only-step'),
            );
            $jobs = $this->jobLedgerRepository->findByStepRunId($stepRun->id);
            $jobs->all()[0]->start('worker');
            $jobs->all()[0]->succeed();
            $this->jobLedgerRepository->save($jobs->all()[0]);

            $afterTrigger = $this->externalTriggerHandler->triggerEvaluation($workflowInstance->id);

            expect($afterTrigger->state())->toBe(WorkflowState::Succeeded);
        });
    });

    describe('Trigger Payload Handling', function (): void {
        it('includes trigger payload in successful result', function (): void {
            $workflowDefinition = createSingleStepDefinitionForTrigger();
            $this->workflowDefinitionRegistry->register($workflowDefinition);

            $workflowInstance = $this->workflowManagementService->startWorkflow(
                DefinitionKey::fromString('single-step-trigger'),
            );

            $this->workflowManagementService->pauseWorkflow($workflowInstance->id, 'Waiting');

            $triggerPayload = TriggerPayload::fromArray([
                'source' => 'webhook',
                'approved' => true,
                'timestamp' => '2024-01-15T10:30:00Z',
            ]);

            $triggerResult = $this->externalTriggerHandler->handleTrigger(
                $workflowInstance->id,
                'webhook_callback',
                $triggerPayload,
            );

            expect($triggerResult->isSuccess())->toBeTrue();
            expect($triggerResult->payload())->not->toBeNull();
            expect($triggerResult->payload()->toArray())->toBe([
                'source' => 'webhook',
                'approved' => true,
                'timestamp' => '2024-01-15T10:30:00Z',
            ]);
        });
    });
});

function createSingleStepDefinitionForTrigger(): WorkflowDefinition
{
    return WorkflowDefinitionBuilder::create('single-step-trigger')
        ->displayName('Single Step Trigger Workflow')
        ->singleJob('only-step', static fn (SingleJobStepBuilder $singleJobStepBuilder): SingleJobStepBuilder => $singleJobStepBuilder
            ->displayName('Only Step')
            ->job(TestJob::class))
        ->build();
}

function createTwoStepDefinitionForTrigger(): WorkflowDefinition
{
    return WorkflowDefinitionBuilder::create('trigger-workflow')
        ->displayName('Trigger Workflow')
        ->singleJob('first-step', static fn (SingleJobStepBuilder $singleJobStepBuilder): SingleJobStepBuilder => $singleJobStepBuilder
            ->displayName('First Step')
            ->job(TestJob::class))
        ->singleJob('awaiting-approval', static fn (SingleJobStepBuilder $singleJobStepBuilder): SingleJobStepBuilder => $singleJobStepBuilder
            ->displayName('Awaiting Approval')
            ->job(TestJob::class))
        ->build();
}

function createThreeStepDefinitionForTrigger(): WorkflowDefinition
{
    return WorkflowDefinitionBuilder::create('three-step-trigger')
        ->displayName('Three Step Trigger Workflow')
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
