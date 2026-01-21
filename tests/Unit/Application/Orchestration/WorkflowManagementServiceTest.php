<?php

declare(strict_types=1);

use Illuminate\Contracts\Container\Container;
use Maestro\Workflow\Application\Context\WorkflowContextProviderFactory;
use Maestro\Workflow\Application\Dependency\StepDependencyChecker;
use Maestro\Workflow\Application\Job\DefaultIdempotencyKeyGenerator;
use Maestro\Workflow\Application\Job\JobDispatchService;
use Maestro\Workflow\Application\Orchestration\FailurePolicyHandler;
use Maestro\Workflow\Application\Orchestration\StepDispatcher;
use Maestro\Workflow\Application\Orchestration\StepFinalizer;
use Maestro\Workflow\Application\Orchestration\WorkflowAdvancer;
use Maestro\Workflow\Application\Orchestration\WorkflowManagementService;
use Maestro\Workflow\Application\Output\StepOutputStoreFactory;
use Maestro\Workflow\Definition\Builders\SingleJobStepBuilder;
use Maestro\Workflow\Definition\Builders\WorkflowDefinitionBuilder;
use Maestro\Workflow\Definition\WorkflowDefinitionRegistry;
use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\Enums\WorkflowState;
use Maestro\Workflow\Exceptions\InvalidStateTransitionException;
use Maestro\Workflow\Exceptions\WorkflowAlreadyCancelledException;
use Maestro\Workflow\Exceptions\WorkflowNotFoundException;
use Maestro\Workflow\Tests\Fakes\InMemoryJobLedgerRepository;
use Maestro\Workflow\Tests\Fakes\InMemoryStepOutputRepository;
use Maestro\Workflow\Tests\Fakes\InMemoryStepRunRepository;
use Maestro\Workflow\Tests\Fakes\InMemoryWorkflowRepository;
use Maestro\Workflow\Tests\Fixtures\Jobs\TestJob;
use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\WorkflowId;

describe('WorkflowManagementService', function (): void {
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

        $this->service = new WorkflowManagementService(
            $this->workflowRepository,
            $this->workflowDefinitionRegistry,
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

    describe('startWorkflow', function (): void {
        it('creates and starts a new workflow', function (): void {
            $workflowInstance = $this->service->startWorkflow(
                DefinitionKey::fromString('test-workflow'),
            );

            expect($workflowInstance->state())->toBe(WorkflowState::Running);
            expect($workflowInstance->definitionKey->toString())->toBe('test-workflow');
        });

        it('uses provided workflow ID', function (): void {
            $workflowId = WorkflowId::generate();

            $workflowInstance = $this->service->startWorkflow(
                DefinitionKey::fromString('test-workflow'),
                $workflowId,
            );

            expect($workflowInstance->id->value)->toBe($workflowId->value);
        });

        it('saves workflow to repository', function (): void {
            $workflowInstance = $this->service->startWorkflow(
                DefinitionKey::fromString('test-workflow'),
            );

            $savedWorkflow = $this->workflowRepository->find($workflowInstance->id);
            expect($savedWorkflow)->not->toBeNull();
        });
    });

    describe('pauseWorkflow', function (): void {
        it('pauses a running workflow', function (): void {
            $workflowInstance = $this->service->startWorkflow(
                DefinitionKey::fromString('test-workflow'),
            );

            $pausedWorkflow = $this->service->pauseWorkflow($workflowInstance->id, 'Test pause');

            expect($pausedWorkflow->state())->toBe(WorkflowState::Paused);
            expect($pausedWorkflow->pauseReason())->toBe('Test pause');
        });

        it('throws for non-existent workflow', function (): void {
            expect(fn () => $this->service->pauseWorkflow(WorkflowId::generate()))
                ->toThrow(WorkflowNotFoundException::class);
        });

        it('throws for paused workflow', function (): void {
            $workflowInstance = $this->service->startWorkflow(
                DefinitionKey::fromString('test-workflow'),
            );
            $this->service->pauseWorkflow($workflowInstance->id);

            expect(fn () => $this->service->pauseWorkflow($workflowInstance->id))
                ->toThrow(InvalidStateTransitionException::class);
        });
    });

    describe('resumeWorkflow', function (): void {
        it('resumes a paused workflow', function (): void {
            $workflowInstance = $this->service->startWorkflow(
                DefinitionKey::fromString('test-workflow'),
            );
            $this->service->pauseWorkflow($workflowInstance->id);

            $resumedWorkflow = $this->service->resumeWorkflow($workflowInstance->id);

            expect($resumedWorkflow->state())->toBe(WorkflowState::Running);
        });

        it('throws for running workflow', function (): void {
            $workflowInstance = $this->service->startWorkflow(
                DefinitionKey::fromString('test-workflow'),
            );

            expect(fn () => $this->service->resumeWorkflow($workflowInstance->id))
                ->toThrow(InvalidStateTransitionException::class);
        });
    });

    describe('cancelWorkflow', function (): void {
        it('cancels a running workflow', function (): void {
            $workflowInstance = $this->service->startWorkflow(
                DefinitionKey::fromString('test-workflow'),
            );

            $cancelledWorkflow = $this->service->cancelWorkflow($workflowInstance->id);

            expect($cancelledWorkflow->state())->toBe(WorkflowState::Cancelled);
        });

        it('cancels a paused workflow', function (): void {
            $workflowInstance = $this->service->startWorkflow(
                DefinitionKey::fromString('test-workflow'),
            );
            $this->service->pauseWorkflow($workflowInstance->id);

            $cancelledWorkflow = $this->service->cancelWorkflow($workflowInstance->id);

            expect($cancelledWorkflow->state())->toBe(WorkflowState::Cancelled);
        });

        it('throws for already cancelled workflow', function (): void {
            $workflowInstance = $this->service->startWorkflow(
                DefinitionKey::fromString('test-workflow'),
            );
            $this->service->cancelWorkflow($workflowInstance->id);

            expect(fn () => $this->service->cancelWorkflow($workflowInstance->id))
                ->toThrow(WorkflowAlreadyCancelledException::class);
        });
    });

    describe('retryWorkflow', function (): void {
        it('retries a failed workflow', function (): void {
            $workflowInstance = WorkflowInstance::create(
                DefinitionKey::fromString('test-workflow'),
                $this->workflowDefinitionRegistry->getLatest(DefinitionKey::fromString('test-workflow'))->version(),
            );
            $workflowInstance->start(StepKey::fromString('step-1'));
            $workflowInstance->fail('ERROR', 'Failed');
            $this->workflowRepository->save($workflowInstance);

            $retriedWorkflow = $this->service->retryWorkflow($workflowInstance->id);

            expect($retriedWorkflow->state())->toBe(WorkflowState::Running);
        });

        it('throws for running workflow', function (): void {
            $workflowInstance = $this->service->startWorkflow(
                DefinitionKey::fromString('test-workflow'),
            );

            expect(fn () => $this->service->retryWorkflow($workflowInstance->id))
                ->toThrow(InvalidStateTransitionException::class);
        });
    });

    describe('getWorkflowStatus', function (): void {
        it('returns workflow instance', function (): void {
            $workflowInstance = $this->service->startWorkflow(
                DefinitionKey::fromString('test-workflow'),
            );

            $status = $this->service->getWorkflowStatus($workflowInstance->id);

            expect($status->id->value)->toBe($workflowInstance->id->value);
        });

        it('throws for non-existent workflow', function (): void {
            expect(fn () => $this->service->getWorkflowStatus(WorkflowId::generate()))
                ->toThrow(WorkflowNotFoundException::class);
        });
    });

    describe('workflowExists', function (): void {
        it('returns true for existing workflow', function (): void {
            $workflowInstance = $this->service->startWorkflow(
                DefinitionKey::fromString('test-workflow'),
            );

            expect($this->service->workflowExists($workflowInstance->id))->toBeTrue();
        });

        it('returns false for non-existent workflow', function (): void {
            expect($this->service->workflowExists(WorkflowId::generate()))->toBeFalse();
        });
    });
});
