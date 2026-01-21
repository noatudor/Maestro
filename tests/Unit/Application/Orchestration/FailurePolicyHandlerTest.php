<?php

declare(strict_types=1);

use Illuminate\Contracts\Container\Container;
use Maestro\Workflow\Application\Context\WorkflowContextProviderFactory;
use Maestro\Workflow\Application\Dependency\StepDependencyChecker;
use Maestro\Workflow\Application\Job\DefaultIdempotencyKeyGenerator;
use Maestro\Workflow\Application\Job\JobDispatchService;
use Maestro\Workflow\Application\Orchestration\FailurePolicyHandler;
use Maestro\Workflow\Application\Orchestration\StepDispatcher;
use Maestro\Workflow\Application\Output\StepOutputStoreFactory;
use Maestro\Workflow\Definition\Builders\SingleJobStepBuilder;
use Maestro\Workflow\Definition\Builders\WorkflowDefinitionBuilder;
use Maestro\Workflow\Definition\WorkflowDefinitionRegistry;
use Maestro\Workflow\Domain\StepRun;
use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\Enums\WorkflowState;
use Maestro\Workflow\Tests\Fakes\InMemoryJobLedgerRepository;
use Maestro\Workflow\Tests\Fakes\InMemoryStepOutputRepository;
use Maestro\Workflow\Tests\Fakes\InMemoryStepRunRepository;
use Maestro\Workflow\Tests\Fakes\InMemoryWorkflowRepository;
use Maestro\Workflow\Tests\Fixtures\Jobs\TestJob;
use Maestro\Workflow\ValueObjects\StepKey;

describe('FailurePolicyHandler', function (): void {
    beforeEach(function (): void {
        $this->workflowRepository = new InMemoryWorkflowRepository();
        $this->stepRunRepository = new InMemoryStepRunRepository();
        $this->jobLedgerRepository = new InMemoryJobLedgerRepository();
        $this->stepOutputRepository = new InMemoryStepOutputRepository();
        $this->workflowDefinitionRegistry = new WorkflowDefinitionRegistry();

        $mock = Mockery::mock(Container::class);
        $mock->shouldReceive('make')->andReturnUsing(static fn (string $class): object => new $class());

        $stepDependencyChecker = new StepDependencyChecker($this->stepOutputRepository);
        $stepOutputStoreFactory = new StepOutputStoreFactory($this->stepOutputRepository);
        $workflowContextProviderFactory = new WorkflowContextProviderFactory($mock);
        $jobDispatchService = new JobDispatchService(
            $this->jobLedgerRepository,
            new DefaultIdempotencyKeyGenerator(),
        );

        $stepDispatcher = new StepDispatcher(
            $this->stepRunRepository,
            $jobDispatchService,
            $stepDependencyChecker,
            $stepOutputStoreFactory,
            $workflowContextProviderFactory,
            $this->workflowDefinitionRegistry,
        );

        $this->handler = new FailurePolicyHandler(
            $this->workflowRepository,
            $stepDispatcher,
        );
    });

    describe('FailWorkflow policy', function (): void {
        it('fails workflow when step fails with FailWorkflow policy', function (): void {
            $singleJobStepDefinition = SingleJobStepBuilder::create('test-step')
                ->displayName('Test Step')
                ->job(TestJob::class)
                ->failWorkflow()
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
            $this->workflowRepository->save($workflowInstance);

            $stepRun = StepRun::create(
                $workflowInstance->id,
                StepKey::fromString('test-step'),
            );
            $stepRun->start();
            $stepRun->fail('ERROR_CODE', 'Step failed');
            $this->stepRunRepository->save($stepRun);

            $this->handler->handle($workflowInstance, $stepRun, $singleJobStepDefinition);

            $updatedWorkflow = $this->workflowRepository->find($workflowInstance->id);
            expect($updatedWorkflow->state())->toBe(WorkflowState::Failed);
            expect($updatedWorkflow->failureMessage())->toBe('Step failed');
        });
    });

    describe('PauseWorkflow policy', function (): void {
        it('pauses workflow when step fails with PauseWorkflow policy', function (): void {
            $singleJobStepDefinition = SingleJobStepBuilder::create('test-step')
                ->displayName('Test Step')
                ->job(TestJob::class)
                ->pauseWorkflow()
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
            $this->workflowRepository->save($workflowInstance);

            $stepRun = StepRun::create(
                $workflowInstance->id,
                StepKey::fromString('test-step'),
            );
            $stepRun->start();
            $stepRun->fail('ERROR_CODE', 'Step failed');
            $this->stepRunRepository->save($stepRun);

            $this->handler->handle($workflowInstance, $stepRun, $singleJobStepDefinition);

            $updatedWorkflow = $this->workflowRepository->find($workflowInstance->id);
            expect($updatedWorkflow->state())->toBe(WorkflowState::Paused);
            expect($updatedWorkflow->pauseReason())->toContain('Step "test-step" failed');
        });
    });

    describe('RetryStep policy', function (): void {
        it('retries step when under max attempts', function (): void {
            $singleJobStepDefinition = SingleJobStepBuilder::create('test-step')
                ->displayName('Test Step')
                ->job(TestJob::class)
                ->retryStep()
                ->retry(maxAttempts: 3)
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
            $this->workflowRepository->save($workflowInstance);

            $stepRun = StepRun::create(
                $workflowInstance->id,
                StepKey::fromString('test-step'),
                attempt: 1,
            );
            $stepRun->start();
            $stepRun->fail('ERROR_CODE', 'Step failed');
            $this->stepRunRepository->save($stepRun);

            $this->handler->handle($workflowInstance, $stepRun, $singleJobStepDefinition);

            $updatedWorkflow = $this->workflowRepository->find($workflowInstance->id);
            expect($updatedWorkflow->state())->toBe(WorkflowState::Running);

            $newStepRun = $this->stepRunRepository->findLatestByWorkflowIdAndStepKey(
                $workflowInstance->id,
                StepKey::fromString('test-step'),
            );
            expect($newStepRun->attempt)->toBe(2);
        });

        it('fails workflow when max attempts reached', function (): void {
            $singleJobStepDefinition = SingleJobStepBuilder::create('test-step')
                ->displayName('Test Step')
                ->job(TestJob::class)
                ->retryStep()
                ->retry(maxAttempts: 3)
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
            $this->workflowRepository->save($workflowInstance);

            $stepRun = StepRun::create(
                $workflowInstance->id,
                StepKey::fromString('test-step'),
                attempt: 3,
            );
            $stepRun->start();
            $stepRun->fail('ERROR_CODE', 'Step failed');
            $this->stepRunRepository->save($stepRun);

            $this->handler->handle($workflowInstance, $stepRun, $singleJobStepDefinition);

            $updatedWorkflow = $this->workflowRepository->find($workflowInstance->id);
            expect($updatedWorkflow->state())->toBe(WorkflowState::Failed);
        });
    });

    describe('SkipStep policy', function (): void {
        it('saves workflow when step fails with SkipStep policy', function (): void {
            $singleJobStepDefinition = SingleJobStepBuilder::create('test-step')
                ->displayName('Test Step')
                ->job(TestJob::class)
                ->skipStep()
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
            $this->workflowRepository->save($workflowInstance);

            $stepRun = StepRun::create(
                $workflowInstance->id,
                StepKey::fromString('test-step'),
            );
            $stepRun->start();
            $stepRun->fail('ERROR_CODE', 'Step failed');
            $this->stepRunRepository->save($stepRun);

            $this->handler->handle($workflowInstance, $stepRun, $singleJobStepDefinition);

            $updatedWorkflow = $this->workflowRepository->find($workflowInstance->id);
            expect($updatedWorkflow->state())->toBe(WorkflowState::Running);
        });
    });

    describe('ContinueWithPartial policy', function (): void {
        it('saves workflow when step fails with ContinueWithPartial policy', function (): void {
            $stepDefinition = SingleJobStepBuilder::create('test-step')
                ->displayName('Test Step')
                ->job(TestJob::class)
                ->continueWithPartial()
                ->build();

            $workflowDefinition = WorkflowDefinitionBuilder::create('test-workflow')
                ->addStep($stepDefinition)
                ->build();
            $this->workflowDefinitionRegistry->register($workflowDefinition);

            $workflowInstance = WorkflowInstance::create(
                $workflowDefinition->key(),
                $workflowDefinition->version(),
            );
            $workflowInstance->start(StepKey::fromString('test-step'));
            $this->workflowRepository->save($workflowInstance);

            $stepRun = StepRun::create(
                $workflowInstance->id,
                StepKey::fromString('test-step'),
            );
            $stepRun->start();
            $stepRun->fail('ERROR_CODE', 'Step failed');
            $this->stepRunRepository->save($stepRun);

            $this->handler->handle($workflowInstance, $stepRun, $stepDefinition);

            $updatedWorkflow = $this->workflowRepository->find($workflowInstance->id);
            expect($updatedWorkflow->state())->toBe(WorkflowState::Running);
        });
    });
});
