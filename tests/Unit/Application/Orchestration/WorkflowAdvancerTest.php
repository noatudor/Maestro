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
use Maestro\Workflow\Application\Output\StepOutputStoreFactory;
use Maestro\Workflow\Definition\Builders\SingleJobStepBuilder;
use Maestro\Workflow\Definition\Builders\WorkflowDefinitionBuilder;
use Maestro\Workflow\Definition\StepCollection;
use Maestro\Workflow\Definition\WorkflowDefinition;
use Maestro\Workflow\Definition\WorkflowDefinitionRegistry;
use Maestro\Workflow\Domain\JobRecord;
use Maestro\Workflow\Domain\StepRun;
use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\Enums\WorkflowState;
use Maestro\Workflow\Tests\Fakes\InMemoryJobLedgerRepository;
use Maestro\Workflow\Tests\Fakes\InMemoryStepOutputRepository;
use Maestro\Workflow\Tests\Fakes\InMemoryStepRunRepository;
use Maestro\Workflow\Tests\Fakes\InMemoryWorkflowRepository;
use Maestro\Workflow\Tests\Fixtures\Jobs\TestJob;
use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\DefinitionVersion;
use Maestro\Workflow\ValueObjects\StepKey;

describe('WorkflowAdvancer', function (): void {
    beforeEach(function (): void {
        $this->workflowRepository = new InMemoryWorkflowRepository();
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

        $workflowContextProviderFactory = new WorkflowContextProviderFactory(
            $mock,
        );

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

        $this->advancer = new WorkflowAdvancer(
            $this->workflowRepository,
            $this->stepRunRepository,
            $this->workflowDefinitionRegistry,
            $stepFinalizer,
            $stepDispatcher,
            $failurePolicyHandler,
        );
    });

    describe('evaluate', function (): void {
        it('does nothing for terminal workflow', function (): void {
            $workflowDefinition = createTestWorkflowDefinition();
            $this->workflowDefinitionRegistry->register($workflowDefinition);

            $workflowInstance = WorkflowInstance::create(
                $workflowDefinition->key(),
                $workflowDefinition->version(),
            );
            $workflowInstance->start(StepKey::fromString('step-1'));
            $workflowInstance->succeed();
            $this->workflowRepository->save($workflowInstance);

            $this->advancer->evaluate($workflowInstance->id);

            $updatedWorkflow = $this->workflowRepository->find($workflowInstance->id);
            expect($updatedWorkflow->state())->toBe(WorkflowState::Succeeded);
        });

        it('does nothing for paused workflow', function (): void {
            $workflowDefinition = createTestWorkflowDefinition();
            $this->workflowDefinitionRegistry->register($workflowDefinition);

            $workflowInstance = WorkflowInstance::create(
                $workflowDefinition->key(),
                $workflowDefinition->version(),
            );
            $workflowInstance->start(StepKey::fromString('step-1'));
            $workflowInstance->pause('User paused');
            $this->workflowRepository->save($workflowInstance);

            $this->advancer->evaluate($workflowInstance->id);

            $updatedWorkflow = $this->workflowRepository->find($workflowInstance->id);
            expect($updatedWorkflow->state())->toBe(WorkflowState::Paused);
        });

        it('starts pending workflow and dispatches first step', function (): void {
            $workflowDefinition = createTestWorkflowDefinition();
            $this->workflowDefinitionRegistry->register($workflowDefinition);

            $workflowInstance = WorkflowInstance::create(
                $workflowDefinition->key(),
                $workflowDefinition->version(),
            );
            $this->workflowRepository->save($workflowInstance);

            $this->advancer->evaluate($workflowInstance->id);

            $updatedWorkflow = $this->workflowRepository->find($workflowInstance->id);
            expect($updatedWorkflow->state())->toBe(WorkflowState::Running);
            expect($updatedWorkflow->currentStepKey()->toString())->toBe('step-1');
        });

        it('succeeds empty workflow immediately', function (): void {
            $workflowDefinition = createEmptyWorkflowDefinition();
            $this->workflowDefinitionRegistry->register($workflowDefinition);

            $workflowInstance = WorkflowInstance::create(
                $workflowDefinition->key(),
                $workflowDefinition->version(),
            );
            $this->workflowRepository->save($workflowInstance);

            $this->advancer->evaluate($workflowInstance->id);

            $updatedWorkflow = $this->workflowRepository->find($workflowInstance->id);
            expect($updatedWorkflow->state())->toBe(WorkflowState::Succeeded);
        });

        it('advances to next step when current step succeeds', function (): void {
            $workflowDefinition = createTwoStepWorkflowDefinition();
            $this->workflowDefinitionRegistry->register($workflowDefinition);

            $workflowInstance = WorkflowInstance::create(
                $workflowDefinition->key(),
                $workflowDefinition->version(),
            );
            $workflowInstance->start(StepKey::fromString('step-1'));
            $this->workflowRepository->save($workflowInstance);

            $stepRun = StepRun::create(
                $workflowInstance->id,
                StepKey::fromString('step-1'),
                totalJobCount: 1,
            );
            $stepRun->start();
            $stepRun->succeed();
            $this->stepRunRepository->save($stepRun);

            $this->advancer->evaluate($workflowInstance->id);

            $updatedWorkflow = $this->workflowRepository->find($workflowInstance->id);
            expect($updatedWorkflow->currentStepKey()->toString())->toBe('step-2');
        });

        it('succeeds workflow when last step completes', function (): void {
            $workflowDefinition = createTestWorkflowDefinition();
            $this->workflowDefinitionRegistry->register($workflowDefinition);

            $workflowInstance = WorkflowInstance::create(
                $workflowDefinition->key(),
                $workflowDefinition->version(),
            );
            $workflowInstance->start(StepKey::fromString('step-1'));
            $this->workflowRepository->save($workflowInstance);

            $stepRun = StepRun::create(
                $workflowInstance->id,
                StepKey::fromString('step-1'),
                totalJobCount: 1,
            );
            $stepRun->start();
            $stepRun->succeed();
            $this->stepRunRepository->save($stepRun);

            $this->advancer->evaluate($workflowInstance->id);

            $updatedWorkflow = $this->workflowRepository->find($workflowInstance->id);
            expect($updatedWorkflow->state())->toBe(WorkflowState::Succeeded);
        });

        it('handles step finalization when step is running', function (): void {
            $workflowDefinition = createTestWorkflowDefinition();
            $this->workflowDefinitionRegistry->register($workflowDefinition);

            $workflowInstance = WorkflowInstance::create(
                $workflowDefinition->key(),
                $workflowDefinition->version(),
            );
            $workflowInstance->start(StepKey::fromString('step-1'));
            $this->workflowRepository->save($workflowInstance);

            $stepRun = StepRun::create(
                $workflowInstance->id,
                StepKey::fromString('step-1'),
                totalJobCount: 1,
            );
            $stepRun->start();
            $this->stepRunRepository->save($stepRun);

            $jobRecord = JobRecord::create(
                $workflowInstance->id,
                $stepRun->id,
                'job-uuid-1',
                TestJob::class,
                'default',
            );
            $jobRecord->start('worker-1');
            $jobRecord->succeed();
            $this->jobLedgerRepository->save($jobRecord);

            $this->advancer->evaluate($workflowInstance->id);

            $updatedWorkflow = $this->workflowRepository->find($workflowInstance->id);
            expect($updatedWorkflow->state())->toBe(WorkflowState::Succeeded);
        });
    });

    describe('evaluateWithApplicationLock', function (): void {
        it('evaluates workflow with application-level lock', function (): void {
            $workflowDefinition = createTestWorkflowDefinition();
            $this->workflowDefinitionRegistry->register($workflowDefinition);

            $workflowInstance = WorkflowInstance::create(
                $workflowDefinition->key(),
                $workflowDefinition->version(),
            );
            $this->workflowRepository->save($workflowInstance);

            $this->advancer->evaluateWithApplicationLock($workflowInstance->id);

            $updatedWorkflow = $this->workflowRepository->find($workflowInstance->id);
            expect($updatedWorkflow->state())->toBe(WorkflowState::Running);
        });

        it('skips evaluation when lock cannot be acquired', function (): void {
            $workflowDefinition = createTestWorkflowDefinition();
            $this->workflowDefinitionRegistry->register($workflowDefinition);

            $workflowInstance = WorkflowInstance::create(
                $workflowDefinition->key(),
                $workflowDefinition->version(),
            );
            $workflowInstance->acquireLock('existing-lock');
            $this->workflowRepository->save($workflowInstance);

            $this->advancer->evaluateWithApplicationLock($workflowInstance->id);

            $updatedWorkflow = $this->workflowRepository->find($workflowInstance->id);
            expect($updatedWorkflow->state())->toBe(WorkflowState::Pending);
        });
    });
});

function createTestWorkflowDefinition(): WorkflowDefinition
{
    return WorkflowDefinitionBuilder::create('test-workflow')
        ->displayName('Test Workflow')
        ->addStep(
            SingleJobStepBuilder::create('step-1')
                ->displayName('Step 1')
                ->job(TestJob::class)
                ->build(),
        )
        ->build();
}

function createTwoStepWorkflowDefinition(): WorkflowDefinition
{
    return WorkflowDefinitionBuilder::create('test-workflow')
        ->displayName('Test Workflow')
        ->addStep(
            SingleJobStepBuilder::create('step-1')
                ->displayName('Step 1')
                ->job(TestJob::class)
                ->build(),
        )
        ->addStep(
            SingleJobStepBuilder::create('step-2')
                ->displayName('Step 2')
                ->job(TestJob::class)
                ->build(),
        )
        ->build();
}

function createEmptyWorkflowDefinition(): WorkflowDefinition
{
    return WorkflowDefinition::create(
        DefinitionKey::fromString('empty-workflow'),
        DefinitionVersion::fromString('1.0.0'),
        'Empty Workflow',
        StepCollection::empty(),
    );
}
