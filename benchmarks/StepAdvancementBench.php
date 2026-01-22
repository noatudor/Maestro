<?php

declare(strict_types=1);

namespace Maestro\Workflow\Benchmarks;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Maestro\Workflow\Application\Context\WorkflowContextProviderFactory;
use Maestro\Workflow\Application\Dependency\StepDependencyChecker;
use Maestro\Workflow\Application\Job\JobDispatchService;
use Maestro\Workflow\Application\Orchestration\FailurePolicyHandler;
use Maestro\Workflow\Application\Orchestration\StepDispatcher;
use Maestro\Workflow\Application\Orchestration\StepFinalizer;
use Maestro\Workflow\Application\Orchestration\WorkflowAdvancer;
use Maestro\Workflow\Application\Output\StepOutputStoreFactory;
use Maestro\Workflow\Definition\Builders\SingleJobStepBuilder;
use Maestro\Workflow\Definition\Builders\WorkflowDefinitionBuilder;
use Maestro\Workflow\Definition\WorkflowDefinition;
use Maestro\Workflow\Definition\WorkflowDefinitionRegistry;
use Maestro\Workflow\Domain\JobRecord;
use Maestro\Workflow\Domain\StepRun;
use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\Tests\Fakes\InMemoryJobLedgerRepository;
use Maestro\Workflow\Tests\Fakes\InMemoryStepOutputRepository;
use Maestro\Workflow\Tests\Fakes\InMemoryStepRunRepository;
use Maestro\Workflow\Tests\Fakes\InMemoryWorkflowRepository;
use Maestro\Workflow\ValueObjects\StepKey;
use Mockery;
use PhpBench\Attributes as Bench;

/**
 * Benchmarks for step advancement operations.
 *
 * Target: < 10ms for step advancement
 */
#[Bench\BeforeMethods(['setUp'])]
#[Bench\AfterMethods(['tearDown'])]
final class StepAdvancementBench
{
    private InMemoryWorkflowRepository $workflowRepository;

    private InMemoryStepRunRepository $stepRunRepository;

    private InMemoryJobLedgerRepository $jobLedgerRepository;

    private InMemoryStepOutputRepository $stepOutputRepository;

    private WorkflowDefinitionRegistry $workflowDefinitionRegistry;

    private WorkflowAdvancer $advancer;

    private WorkflowDefinition $definition;

    private WorkflowInstance $workflowInstance;

    public function setUp(): void
    {
        $this->workflowRepository = new InMemoryWorkflowRepository();
        $this->stepRunRepository = new InMemoryStepRunRepository();
        $this->jobLedgerRepository = new InMemoryJobLedgerRepository();
        $this->stepOutputRepository = new InMemoryStepOutputRepository();
        $this->workflowDefinitionRegistry = new WorkflowDefinitionRegistry();

        $containerMock = Mockery::mock(Container::class);
        $containerMock->shouldReceive('make')->andReturnUsing(static fn (string $class): object => new $class());

        $dispatcherMock = Mockery::mock(Dispatcher::class);
        $dispatcherMock->shouldReceive('dispatch');

        $eventDispatcherMock = Mockery::mock(EventDispatcher::class);
        $eventDispatcherMock->shouldReceive('dispatch');

        $stepDependencyChecker = new StepDependencyChecker($this->stepOutputRepository);
        $stepOutputStoreFactory = new StepOutputStoreFactory($this->stepOutputRepository);
        $workflowContextProviderFactory = new WorkflowContextProviderFactory($containerMock);

        $jobDispatchService = new JobDispatchService(
            $dispatcherMock,
            $this->jobLedgerRepository,
            $eventDispatcherMock,
        );

        $stepDispatcher = new StepDispatcher(
            $this->stepRunRepository,
            $jobDispatchService,
            $stepDependencyChecker,
            $stepOutputStoreFactory,
            $workflowContextProviderFactory,
            $this->workflowDefinitionRegistry,
            $eventDispatcherMock,
        );

        $stepFinalizer = new StepFinalizer(
            $this->stepRunRepository,
            $this->jobLedgerRepository,
            $eventDispatcherMock,
        );

        $failurePolicyHandler = new FailurePolicyHandler(
            $this->workflowRepository,
            $stepDispatcher,
            $eventDispatcherMock,
        );

        $this->advancer = new WorkflowAdvancer(
            $this->workflowRepository,
            $this->stepRunRepository,
            $this->workflowDefinitionRegistry,
            $stepFinalizer,
            $stepDispatcher,
            $failurePolicyHandler,
            $eventDispatcherMock,
        );

        $this->definition = $this->createMultiStepWorkflowDefinition();
        $this->workflowDefinitionRegistry->register($this->definition);

        $this->workflowInstance = WorkflowInstance::create(
            $this->definition->key(),
            $this->definition->version(),
        );
        $this->workflowRepository->save($this->workflowInstance);
    }

    public function tearDown(): void
    {
        Mockery::close();
    }

    /**
     * Benchmark starting a pending workflow.
     */
    #[Bench\Revs(500)]
    #[Bench\Iterations(10)]
    #[Bench\Assert('mode(variant.time.avg) < 10ms')]
    public function benchStartPendingWorkflow(): void
    {
        $workflow = WorkflowInstance::create(
            $this->definition->key(),
            $this->definition->version(),
        );
        $this->workflowRepository->save($workflow);

        $this->advancer->evaluate($workflow->id);
    }

    /**
     * Benchmark advancing workflow to next step.
     */
    #[Bench\Revs(500)]
    #[Bench\Iterations(10)]
    #[Bench\Assert('mode(variant.time.avg) < 10ms')]
    public function benchAdvanceToNextStep(): void
    {
        $workflow = WorkflowInstance::create(
            $this->definition->key(),
            $this->definition->version(),
        );
        $workflow->start(StepKey::fromString('step-1'));
        $this->workflowRepository->save($workflow);

        $stepRun = StepRun::create(
            $workflow->id,
            StepKey::fromString('step-1'),
            totalJobCount: 1,
        );
        $stepRun->start();
        $stepRun->succeed();
        $this->stepRunRepository->save($stepRun);

        $this->advancer->evaluate($workflow->id);
    }

    /**
     * Benchmark step finalization when all jobs complete.
     */
    #[Bench\Revs(500)]
    #[Bench\Iterations(10)]
    #[Bench\Assert('mode(variant.time.avg) < 10ms')]
    public function benchStepFinalizationWithJobs(): void
    {
        $workflow = WorkflowInstance::create(
            $this->definition->key(),
            $this->definition->version(),
        );
        $workflow->start(StepKey::fromString('step-1'));
        $this->workflowRepository->save($workflow);

        $stepRun = StepRun::create(
            $workflow->id,
            StepKey::fromString('step-1'),
            totalJobCount: 1,
        );
        $stepRun->start();
        $this->stepRunRepository->save($stepRun);

        $jobRecord = JobRecord::create(
            $workflow->id,
            $stepRun->id,
            'job-uuid-'.uniqid(),
            DummyJob::class,
            'default',
        );
        $jobRecord->start('worker');
        $jobRecord->succeed();
        $this->jobLedgerRepository->save($jobRecord);

        $this->advancer->evaluate($workflow->id);
    }

    /**
     * Benchmark evaluating a terminal workflow (should be fast no-op).
     */
    #[Bench\Revs(1000)]
    #[Bench\Iterations(10)]
    #[Bench\Assert('mode(variant.time.avg) < 1ms')]
    public function benchEvaluateTerminalWorkflow(): void
    {
        $workflow = WorkflowInstance::create(
            $this->definition->key(),
            $this->definition->version(),
        );
        $workflow->start(StepKey::fromString('step-1'));
        $workflow->succeed();
        $this->workflowRepository->save($workflow);

        $this->advancer->evaluate($workflow->id);
    }

    /**
     * Benchmark evaluating a paused workflow (should be fast no-op).
     */
    #[Bench\Revs(1000)]
    #[Bench\Iterations(10)]
    #[Bench\Assert('mode(variant.time.avg) < 1ms')]
    public function benchEvaluatePausedWorkflow(): void
    {
        $workflow = WorkflowInstance::create(
            $this->definition->key(),
            $this->definition->version(),
        );
        $workflow->start(StepKey::fromString('step-1'));
        $workflow->pause('Test pause');
        $this->workflowRepository->save($workflow);

        $this->advancer->evaluate($workflow->id);
    }

    private function createMultiStepWorkflowDefinition(): WorkflowDefinition
    {
        $builder = WorkflowDefinitionBuilder::create('multi-step-workflow')
            ->displayName('Multi-Step Workflow');

        for ($i = 1; $i <= 5; $i++) {
            $builder->singleJob("step-{$i}", fn (SingleJobStepBuilder $step) => $step
                ->displayName("Step {$i}")
                ->job(DummyJob::class));
        }

        return $builder->build();
    }
}
