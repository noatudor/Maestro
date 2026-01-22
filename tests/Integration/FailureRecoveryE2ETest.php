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
use Maestro\Workflow\Domain\Events\StepFailed;
use Maestro\Workflow\Domain\Events\StepRetried;
use Maestro\Workflow\Domain\Events\WorkflowFailed;
use Maestro\Workflow\Domain\Events\WorkflowPaused;
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

describe('Failure and Recovery E2E Tests', function (): void {
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

    describe('FailWorkflow Policy', function (): void {
        it('fails workflow when job fails with FailWorkflow policy', function (): void {
            $workflowDefinition = createFailWorkflowPolicyDefinition();
            $this->workflowDefinitionRegistry->register($workflowDefinition);

            $workflowInstance = $this->workflowManagementService->startWorkflow(
                DefinitionKey::fromString('fail-workflow-policy'),
            );

            $stepRun = $this->stepRunRepository->findLatestByWorkflowIdAndStepKey(
                $workflowInstance->id,
                StepKey::fromString('failing-step'),
            );

            $jobs = $this->jobLedgerRepository->findByStepRunId($stepRun->id);
            $jobs->all()[0]->start('worker');
            $jobs->all()[0]->fail('TestException', 'Job failed intentionally', 'stack trace...');
            $this->jobLedgerRepository->save($jobs->all()[0]);

            $this->advancer->evaluate($workflowInstance->id);

            $finalWorkflow = $this->workflowRepository->findOrFail($workflowInstance->id);
            expect($finalWorkflow->state())->toBe(WorkflowState::Failed);
            expect($finalWorkflow->failureCode())->toBe('STEP_FAILED');
            expect($finalWorkflow->failureMessage())->toContain('failed');

            Event::assertDispatched(StepFailed::class);
            Event::assertDispatched(WorkflowFailed::class);
        });
    });

    describe('PauseWorkflow Policy', function (): void {
        it('pauses workflow when job fails with PauseWorkflow policy', function (): void {
            $workflowDefinition = createPauseWorkflowPolicyDefinition();
            $this->workflowDefinitionRegistry->register($workflowDefinition);

            $workflowInstance = $this->workflowManagementService->startWorkflow(
                DefinitionKey::fromString('pause-workflow-policy'),
            );

            $stepRun = $this->stepRunRepository->findLatestByWorkflowIdAndStepKey(
                $workflowInstance->id,
                StepKey::fromString('pauseable-step'),
            );

            $jobs = $this->jobLedgerRepository->findByStepRunId($stepRun->id);
            $jobs->all()[0]->start('worker');
            $jobs->all()[0]->fail('TestException', 'Job failed - needs attention', 'trace');
            $this->jobLedgerRepository->save($jobs->all()[0]);

            $this->advancer->evaluate($workflowInstance->id);

            $pausedWorkflow = $this->workflowRepository->findOrFail($workflowInstance->id);
            expect($pausedWorkflow->state())->toBe(WorkflowState::Paused);
            expect($pausedWorkflow->pausedReason())->toContain('pauseable-step');

            Event::assertDispatched(WorkflowPaused::class);
        });

        it('can resume paused workflow after manual intervention', function (): void {
            $workflowDefinition = createPauseWorkflowPolicyDefinition();
            $this->workflowDefinitionRegistry->register($workflowDefinition);

            $workflowInstance = $this->workflowManagementService->startWorkflow(
                DefinitionKey::fromString('pause-workflow-policy'),
            );

            $stepRun = $this->stepRunRepository->findLatestByWorkflowIdAndStepKey(
                $workflowInstance->id,
                StepKey::fromString('pauseable-step'),
            );

            $jobs = $this->jobLedgerRepository->findByStepRunId($stepRun->id);
            $jobs->all()[0]->start('worker');
            $jobs->all()[0]->fail('TestException', 'Temporary failure', 'trace');
            $this->jobLedgerRepository->save($jobs->all()[0]);

            $this->advancer->evaluate($workflowInstance->id);

            $pausedWorkflow = $this->workflowRepository->findOrFail($workflowInstance->id);
            expect($pausedWorkflow->state())->toBe(WorkflowState::Paused);

            $this->workflowManagementService->resumeWorkflow($workflowInstance->id);

            $resumedWorkflow = $this->workflowRepository->findOrFail($workflowInstance->id);
            expect($resumedWorkflow->state()->isTerminal())->toBeFalse();
        });
    });

    describe('RetryStep Policy', function (): void {
        it('retries step when job fails with RetryStep policy', function (): void {
            $workflowDefinition = createRetryStepPolicyDefinition();
            $this->workflowDefinitionRegistry->register($workflowDefinition);

            $workflowInstance = $this->workflowManagementService->startWorkflow(
                DefinitionKey::fromString('retry-step-policy'),
            );

            $stepRun1 = $this->stepRunRepository->findLatestByWorkflowIdAndStepKey(
                $workflowInstance->id,
                StepKey::fromString('retryable-step'),
            );

            expect($stepRun1->attempt)->toBe(1);

            $jobs1 = $this->jobLedgerRepository->findByStepRunId($stepRun1->id);
            $jobs1->all()[0]->start('worker');
            $jobs1->all()[0]->fail('TransientException', 'Temporary failure', 'trace');
            $this->jobLedgerRepository->save($jobs1->all()[0]);

            $this->advancer->evaluate($workflowInstance->id);

            $stepRun2 = $this->stepRunRepository->findLatestByWorkflowIdAndStepKey(
                $workflowInstance->id,
                StepKey::fromString('retryable-step'),
            );

            expect($stepRun2->attempt)->toBe(2);
            expect($stepRun2->id->value)->not->toBe($stepRun1->id->value);

            Event::assertDispatched(StepRetried::class);
        });

        it('fails workflow after exhausting retry attempts', function (): void {
            $workflowDefinition = createRetryStepPolicyWithLowRetries();
            $this->workflowDefinitionRegistry->register($workflowDefinition);

            $workflowInstance = $this->workflowManagementService->startWorkflow(
                DefinitionKey::fromString('retry-step-low-retries'),
            );

            for ($attempt = 1; $attempt <= 2; $attempt++) {
                $stepRun = $this->stepRunRepository->findLatestByWorkflowIdAndStepKey(
                    $workflowInstance->id,
                    StepKey::fromString('retryable-step'),
                );

                expect($stepRun->attempt)->toBe($attempt);

                $jobs = $this->jobLedgerRepository->findByStepRunId($stepRun->id);
                $jobs->all()[0]->start('worker');
                $jobs->all()[0]->fail('PermanentException', 'Always fails', 'trace');
                $this->jobLedgerRepository->save($jobs->all()[0]);

                $this->advancer->evaluate($workflowInstance->id);
            }

            $finalWorkflow = $this->workflowRepository->findOrFail($workflowInstance->id);
            expect($finalWorkflow->state())->toBe(WorkflowState::Failed);

            Event::assertDispatched(WorkflowFailed::class);
        });

        it('succeeds workflow when retry eventually succeeds', function (): void {
            $workflowDefinition = createRetryStepPolicyDefinition();
            $this->workflowDefinitionRegistry->register($workflowDefinition);

            $workflowInstance = $this->workflowManagementService->startWorkflow(
                DefinitionKey::fromString('retry-step-policy'),
            );

            $stepRun1 = $this->stepRunRepository->findLatestByWorkflowIdAndStepKey(
                $workflowInstance->id,
                StepKey::fromString('retryable-step'),
            );

            $jobs1 = $this->jobLedgerRepository->findByStepRunId($stepRun1->id);
            $jobs1->all()[0]->start('worker');
            $jobs1->all()[0]->fail('TransientException', 'First failure', 'trace');
            $this->jobLedgerRepository->save($jobs1->all()[0]);

            $this->advancer->evaluate($workflowInstance->id);

            $stepRun2 = $this->stepRunRepository->findLatestByWorkflowIdAndStepKey(
                $workflowInstance->id,
                StepKey::fromString('retryable-step'),
            );

            $jobs2 = $this->jobLedgerRepository->findByStepRunId($stepRun2->id);
            $jobs2->all()[0]->start('worker');
            $jobs2->all()[0]->succeed();
            $this->jobLedgerRepository->save($jobs2->all()[0]);

            $this->advancer->evaluate($workflowInstance->id);

            $finalWorkflow = $this->workflowRepository->findOrFail($workflowInstance->id);
            expect($finalWorkflow->state())->toBe(WorkflowState::Succeeded);
        });
    });

    describe('SkipStep Policy', function (): void {
        it('skips failed step and continues workflow with SkipStep policy', function (): void {
            $workflowDefinition = createSkipStepPolicyDefinition();
            $this->workflowDefinitionRegistry->register($workflowDefinition);

            $workflowInstance = $this->workflowManagementService->startWorkflow(
                DefinitionKey::fromString('skip-step-policy'),
            );

            $stepRun = $this->stepRunRepository->findLatestByWorkflowIdAndStepKey(
                $workflowInstance->id,
                StepKey::fromString('skippable-step'),
            );

            $jobs = $this->jobLedgerRepository->findByStepRunId($stepRun->id);
            $jobs->all()[0]->start('worker');
            $jobs->all()[0]->fail('NonCriticalException', 'Optional step failed', 'trace');
            $this->jobLedgerRepository->save($jobs->all()[0]);

            $this->advancer->evaluate($workflowInstance->id);

            $workflowAfterSkip = $this->workflowRepository->findOrFail($workflowInstance->id);
            expect($workflowAfterSkip->state())->toBe(WorkflowState::Running);

            $skippedStepRun = $this->stepRunRepository->findOrFail($stepRun->id);
            expect($skippedStepRun->status())->toBe(StepState::Failed);
        });
    });

    describe('Workflow Retry', function (): void {
        it('transitions workflow to running state when retried', function (): void {
            $workflowDefinition = createTwoStepFailWorkflowDefinition();
            $this->workflowDefinitionRegistry->register($workflowDefinition);

            $workflowInstance = $this->workflowManagementService->startWorkflow(
                DefinitionKey::fromString('two-step-fail'),
            );

            $step1Run = $this->stepRunRepository->findLatestByWorkflowIdAndStepKey(
                $workflowInstance->id,
                StepKey::fromString('step-1'),
            );
            $step1Jobs = $this->jobLedgerRepository->findByStepRunId($step1Run->id);
            $step1Jobs->all()[0]->start('worker');
            $step1Jobs->all()[0]->succeed();
            $this->jobLedgerRepository->save($step1Jobs->all()[0]);

            $this->advancer->evaluate($workflowInstance->id);

            $step2Run = $this->stepRunRepository->findLatestByWorkflowIdAndStepKey(
                $workflowInstance->id,
                StepKey::fromString('step-2'),
            );
            $step2Jobs = $this->jobLedgerRepository->findByStepRunId($step2Run->id);
            $step2Jobs->all()[0]->start('worker');
            $step2Jobs->all()[0]->fail('FatalException', 'Critical failure', 'trace');
            $this->jobLedgerRepository->save($step2Jobs->all()[0]);

            $this->advancer->evaluate($workflowInstance->id);

            $failedWorkflow = $this->workflowRepository->findOrFail($workflowInstance->id);
            expect($failedWorkflow->state())->toBe(WorkflowState::Failed);

            $failedWorkflow->retry();
            $this->workflowRepository->save($failedWorkflow);

            $retriedWorkflow = $this->workflowRepository->findOrFail($workflowInstance->id);
            expect($retriedWorkflow->state())->toBe(WorkflowState::Running);
        });
    });

    describe('Fan-Out Failure Scenarios', function (): void {
        it('fails workflow when fan-out step fails with All criteria', function (): void {
            $workflowDefinition = createFanOutFailDefinition(['a', 'b', 'c']);
            $this->workflowDefinitionRegistry->register($workflowDefinition);

            $workflowInstance = $this->workflowManagementService->startWorkflow(
                DefinitionKey::fromString('fanout-fail'),
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
            $jobs->all()[1]->fail('PartialFailure', 'One job failed', 'trace');
            $this->jobLedgerRepository->save($jobs->all()[1]);

            $jobs->all()[2]->start('worker');
            $jobs->all()[2]->succeed();
            $this->jobLedgerRepository->save($jobs->all()[2]);

            $this->advancer->evaluate($workflowInstance->id);

            $finalWorkflow = $this->workflowRepository->findOrFail($workflowInstance->id);
            expect($finalWorkflow->state())->toBe(WorkflowState::Failed);
        });
    });
});

function createFailWorkflowPolicyDefinition(): WorkflowDefinition
{
    return WorkflowDefinitionBuilder::create('fail-workflow-policy')
        ->displayName('Fail Workflow Policy Test')
        ->singleJob('failing-step', static fn (SingleJobStepBuilder $singleJobStepBuilder): SingleJobStepBuilder => $singleJobStepBuilder
            ->displayName('Failing Step')
            ->job(TestJob::class)
            ->failWorkflow())
        ->build();
}

function createPauseWorkflowPolicyDefinition(): WorkflowDefinition
{
    return WorkflowDefinitionBuilder::create('pause-workflow-policy')
        ->displayName('Pause Workflow Policy Test')
        ->singleJob('pauseable-step', static fn (SingleJobStepBuilder $singleJobStepBuilder): SingleJobStepBuilder => $singleJobStepBuilder
            ->displayName('Pauseable Step')
            ->job(TestJob::class)
            ->pauseWorkflow())
        ->build();
}

function createRetryStepPolicyDefinition(): WorkflowDefinition
{
    return WorkflowDefinitionBuilder::create('retry-step-policy')
        ->displayName('Retry Step Policy Test')
        ->singleJob('retryable-step', static fn (SingleJobStepBuilder $singleJobStepBuilder): SingleJobStepBuilder => $singleJobStepBuilder
            ->displayName('Retryable Step')
            ->job(TestJob::class)
            ->retryStep()
            ->retry(maxAttempts: 3))
        ->build();
}

function createRetryStepPolicyWithLowRetries(): WorkflowDefinition
{
    return WorkflowDefinitionBuilder::create('retry-step-low-retries')
        ->displayName('Retry Step Low Retries Test')
        ->singleJob('retryable-step', static fn (SingleJobStepBuilder $singleJobStepBuilder): SingleJobStepBuilder => $singleJobStepBuilder
            ->displayName('Retryable Step')
            ->job(TestJob::class)
            ->retryStep()
            ->retry(maxAttempts: 2))
        ->build();
}

function createSkipStepPolicyDefinition(): WorkflowDefinition
{
    return WorkflowDefinitionBuilder::create('skip-step-policy')
        ->displayName('Skip Step Policy Test')
        ->singleJob('skippable-step', static fn (SingleJobStepBuilder $singleJobStepBuilder): SingleJobStepBuilder => $singleJobStepBuilder
            ->displayName('Skippable Step')
            ->job(TestJob::class)
            ->skipStep())
        ->singleJob('final-step', static fn (SingleJobStepBuilder $singleJobStepBuilder): SingleJobStepBuilder => $singleJobStepBuilder
            ->displayName('Final Step')
            ->job(TestJob::class))
        ->build();
}

function createTwoStepFailWorkflowDefinition(): WorkflowDefinition
{
    return WorkflowDefinitionBuilder::create('two-step-fail')
        ->displayName('Two Step Fail Test')
        ->singleJob('step-1', static fn (SingleJobStepBuilder $singleJobStepBuilder): SingleJobStepBuilder => $singleJobStepBuilder
            ->displayName('Step 1')
            ->job(TestJob::class))
        ->singleJob('step-2', static fn (SingleJobStepBuilder $singleJobStepBuilder): SingleJobStepBuilder => $singleJobStepBuilder
            ->displayName('Step 2')
            ->job(TestJob::class)
            ->failWorkflow())
        ->build();
}

function createFanOutFailDefinition(array $items): WorkflowDefinition
{
    return WorkflowDefinitionBuilder::create('fanout-fail')
        ->displayName('Fan-Out Fail Test')
        ->fanOut('fanout-step', static fn (FanOutStepBuilder $fanOutStepBuilder): FanOutStepBuilder => $fanOutStepBuilder
            ->displayName('Fan-Out Step')
            ->job(ProcessItemJob::class)
            ->iterateOver(static fn (): array => $items)
            ->withJobArguments(static fn ($item): array => ['item' => $item])
            ->requireAllSuccess()
            ->failWorkflow())
        ->build();
}
