<?php

declare(strict_types=1);

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Container\Container;
use Maestro\Workflow\Application\Context\WorkflowContextProviderFactory;
use Maestro\Workflow\Application\Dependency\StepDependencyChecker;
use Maestro\Workflow\Application\Job\JobDispatchService;
use Maestro\Workflow\Application\Orchestration\ExternalTriggerHandler;
use Maestro\Workflow\Application\Orchestration\FailurePolicyHandler;
use Maestro\Workflow\Application\Orchestration\StepDispatcher;
use Maestro\Workflow\Application\Orchestration\StepFinalizer;
use Maestro\Workflow\Application\Orchestration\WorkflowAdvancer;
use Maestro\Workflow\Application\Output\StepOutputStoreFactory;
use Maestro\Workflow\Definition\Builders\SingleJobStepBuilder;
use Maestro\Workflow\Definition\Builders\WorkflowDefinitionBuilder;
use Maestro\Workflow\Definition\WorkflowDefinitionRegistry;
use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\Enums\WorkflowState;
use Maestro\Workflow\Exceptions\WorkflowNotFoundException;
use Maestro\Workflow\Tests\Fakes\InMemoryJobLedgerRepository;
use Maestro\Workflow\Tests\Fakes\InMemoryStepOutputRepository;
use Maestro\Workflow\Tests\Fakes\InMemoryStepRunRepository;
use Maestro\Workflow\Tests\Fakes\InMemoryWorkflowRepository;
use Maestro\Workflow\Tests\Fixtures\Jobs\TestJob;
use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\WorkflowId;

describe('ExternalTriggerHandler', function (): void {
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
        $dispatcherMock = Mockery::mock(Dispatcher::class);
        $dispatcherMock->shouldReceive('dispatch');
        $jobDispatchService = new JobDispatchService(
            $dispatcherMock,
            $this->jobLedgerRepository,
        );

        $stepDispatcher = new StepDispatcher(
            $this->stepRunRepository,
            $jobDispatchService,
            $stepDependencyChecker,
            $stepOutputStoreFactory,
            $workflowContextProviderFactory,
            $this->workflowDefinitionRegistry,
        );

        $stepFinalizer = new StepFinalizer(
            $this->stepRunRepository,
            $this->jobLedgerRepository,
        );

        $failurePolicyHandler = new FailurePolicyHandler(
            $this->workflowRepository,
            $stepDispatcher,
        );

        $workflowAdvancer = new WorkflowAdvancer(
            $this->workflowRepository,
            $this->stepRunRepository,
            $this->workflowDefinitionRegistry,
            $stepFinalizer,
            $stepDispatcher,
            $failurePolicyHandler,
        );

        $this->handler = new ExternalTriggerHandler(
            $this->workflowRepository,
            $workflowAdvancer,
        );

        $workflowDefinition = WorkflowDefinitionBuilder::create('test-workflow')
            ->displayName('Test Workflow')
            ->addStep(
                SingleJobStepBuilder::create('step-1')
                    ->displayName('Step 1')
                    ->job(TestJob::class)
                    ->build(),
            )
            ->build();
        $this->workflowDefinitionRegistry->register($workflowDefinition);
    });

    describe('handleTrigger', function (): void {
        it('returns terminal result for succeeded workflow', function (): void {
            $workflowInstance = WorkflowInstance::create(
                DefinitionKey::fromString('test-workflow'),
                $this->workflowDefinitionRegistry->getLatest(DefinitionKey::fromString('test-workflow'))->version(),
            );
            $workflowInstance->start(StepKey::fromString('step-1'));
            $workflowInstance->succeed();
            $this->workflowRepository->save($workflowInstance);

            $result = $this->handler->handleTrigger($workflowInstance->id, 'webhook');

            expect($result->isTerminal())->toBeTrue();
            expect($result->workflow()->state())->toBe(WorkflowState::Succeeded);
        });

        it('returns terminal result for cancelled workflow', function (): void {
            $workflowInstance = WorkflowInstance::create(
                DefinitionKey::fromString('test-workflow'),
                $this->workflowDefinitionRegistry->getLatest(DefinitionKey::fromString('test-workflow'))->version(),
            );
            $workflowInstance->start(StepKey::fromString('step-1'));
            $workflowInstance->cancel();
            $this->workflowRepository->save($workflowInstance);

            $result = $this->handler->handleTrigger($workflowInstance->id, 'webhook');

            expect($result->isTerminal())->toBeTrue();
        });

        it('resumes paused workflow and advances', function (): void {
            $workflowInstance = WorkflowInstance::create(
                DefinitionKey::fromString('test-workflow'),
                $this->workflowDefinitionRegistry->getLatest(DefinitionKey::fromString('test-workflow'))->version(),
            );
            $workflowInstance->start(StepKey::fromString('step-1'));
            $workflowInstance->pause('Waiting for approval');
            $this->workflowRepository->save($workflowInstance);

            $result = $this->handler->handleTrigger($workflowInstance->id, 'approval');

            expect($result->isSuccess())->toBeTrue();
            expect($result->workflow()->state())->toBe(WorkflowState::Running);
            expect($result->triggerType())->toBe('approval');
        });

        it('advances running workflow', function (): void {
            $workflowInstance = WorkflowInstance::create(
                DefinitionKey::fromString('test-workflow'),
                $this->workflowDefinitionRegistry->getLatest(DefinitionKey::fromString('test-workflow'))->version(),
            );
            $workflowInstance->start(StepKey::fromString('step-1'));
            $this->workflowRepository->save($workflowInstance);

            $result = $this->handler->handleTrigger($workflowInstance->id, 'timer');

            expect($result->isSuccess())->toBeTrue();
        });

        it('throws for non-existent workflow', function (): void {
            expect(fn () => $this->handler->handleTrigger(WorkflowId::generate(), 'webhook'))
                ->toThrow(WorkflowNotFoundException::class);
        });
    });

    describe('resumeAndAdvance', function (): void {
        it('resumes paused workflow', function (): void {
            $workflowInstance = WorkflowInstance::create(
                DefinitionKey::fromString('test-workflow'),
                $this->workflowDefinitionRegistry->getLatest(DefinitionKey::fromString('test-workflow'))->version(),
            );
            $workflowInstance->start(StepKey::fromString('step-1'));
            $workflowInstance->pause('Waiting');
            $this->workflowRepository->save($workflowInstance);

            $result = $this->handler->resumeAndAdvance($workflowInstance->id);

            expect($result->state())->toBe(WorkflowState::Running);
        });

        it('advances running workflow without state change', function (): void {
            $workflowInstance = WorkflowInstance::create(
                DefinitionKey::fromString('test-workflow'),
                $this->workflowDefinitionRegistry->getLatest(DefinitionKey::fromString('test-workflow'))->version(),
            );
            $workflowInstance->start(StepKey::fromString('step-1'));
            $this->workflowRepository->save($workflowInstance);

            $result = $this->handler->resumeAndAdvance($workflowInstance->id);

            expect($result->state())->toBe(WorkflowState::Running);
        });
    });

    describe('triggerEvaluation', function (): void {
        it('evaluates workflow without state change', function (): void {
            $workflowInstance = WorkflowInstance::create(
                DefinitionKey::fromString('test-workflow'),
                $this->workflowDefinitionRegistry->getLatest(DefinitionKey::fromString('test-workflow'))->version(),
            );
            $workflowInstance->start(StepKey::fromString('step-1'));
            $this->workflowRepository->save($workflowInstance);

            $result = $this->handler->triggerEvaluation($workflowInstance->id);

            expect($result)->toBeInstanceOf(WorkflowInstance::class);
        });

        it('throws for non-existent workflow', function (): void {
            expect(fn () => $this->handler->triggerEvaluation(WorkflowId::generate()))
                ->toThrow(WorkflowNotFoundException::class);
        });
    });
});
