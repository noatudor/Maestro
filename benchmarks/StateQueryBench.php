<?php

declare(strict_types=1);

namespace Maestro\Workflow\Benchmarks;

use Maestro\Workflow\Domain\StepRun;
use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\Enums\StepState;
use Maestro\Workflow\Enums\WorkflowState;
use Maestro\Workflow\Tests\Fakes\InMemoryStepRunRepository;
use Maestro\Workflow\Tests\Fakes\InMemoryWorkflowRepository;
use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\DefinitionVersion;
use Maestro\Workflow\ValueObjects\StepKey;
use PhpBench\Attributes as Bench;

/**
 * Benchmarks for state query operations.
 *
 * Target: < 1ms for indexed lookups
 */
#[Bench\BeforeMethods(['setUp'])]
final class StateQueryBench
{
    private InMemoryWorkflowRepository $workflowRepository;

    private InMemoryStepRunRepository $stepRunRepository;

    private WorkflowInstance $workflowInstance;

    private StepRun $stepRun;

    public function setUp(): void
    {
        $this->workflowRepository = new InMemoryWorkflowRepository();
        $this->stepRunRepository = new InMemoryStepRunRepository();

        $this->workflowInstance = WorkflowInstance::create(
            DefinitionKey::fromString('bench-workflow'),
            DefinitionVersion::fromString('1.0.0'),
        );
        $this->workflowInstance->start(StepKey::fromString('step-1'));
        $this->workflowRepository->save($this->workflowInstance);

        $this->stepRun = StepRun::create(
            $this->workflowInstance->id,
            StepKey::fromString('step-1'),
            totalJobCount: 5,
        );
        $this->stepRun->start();
        $this->stepRunRepository->save($this->stepRun);

        for ($i = 0; $i < 100; $i++) {
            $workflow = WorkflowInstance::create(
                DefinitionKey::fromString('bench-workflow'),
                DefinitionVersion::fromString('1.0.0'),
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
        }
    }

    /**
     * Benchmark finding workflow by ID.
     */
    #[Bench\Revs(1000)]
    #[Bench\Iterations(10)]
    #[Bench\Assert('mode(variant.time.avg) < 1ms')]
    public function benchFindWorkflowById(): void
    {
        $this->workflowRepository->find($this->workflowInstance->id);
    }

    /**
     * Benchmark checking workflow exists.
     */
    #[Bench\Revs(1000)]
    #[Bench\Iterations(10)]
    #[Bench\Assert('mode(variant.time.avg) < 1ms')]
    public function benchWorkflowExists(): void
    {
        $this->workflowRepository->exists($this->workflowInstance->id);
    }

    /**
     * Benchmark finding step run by ID.
     */
    #[Bench\Revs(1000)]
    #[Bench\Iterations(10)]
    #[Bench\Assert('mode(variant.time.avg) < 1ms')]
    public function benchFindStepRunById(): void
    {
        $this->stepRunRepository->find($this->stepRun->id);
    }

    /**
     * Benchmark finding step run by workflow ID and step key.
     */
    #[Bench\Revs(1000)]
    #[Bench\Iterations(10)]
    #[Bench\Assert('mode(variant.time.avg) < 1ms')]
    public function benchFindStepRunByWorkflowAndKey(): void
    {
        $this->stepRunRepository->findLatestByWorkflowIdAndStepKey(
            $this->workflowInstance->id,
            StepKey::fromString('step-1'),
        );
    }

    /**
     * Benchmark finding all step runs for a workflow.
     */
    #[Bench\Revs(500)]
    #[Bench\Iterations(10)]
    #[Bench\Assert('mode(variant.time.avg) < 1ms')]
    public function benchFindStepRunsByWorkflowId(): void
    {
        $this->stepRunRepository->findByWorkflowId($this->workflowInstance->id);
    }

    /**
     * Benchmark workflow state checks.
     */
    #[Bench\Revs(10000)]
    #[Bench\Iterations(10)]
    #[Bench\Assert('mode(variant.time.avg) < 100μs')]
    public function benchWorkflowStateChecks(): void
    {
        $this->workflowInstance->state();
        $this->workflowInstance->isTerminal();
        $this->workflowInstance->isPaused();
        $this->workflowInstance->isPending();
    }

    /**
     * Benchmark step run state checks.
     */
    #[Bench\Revs(10000)]
    #[Bench\Iterations(10)]
    #[Bench\Assert('mode(variant.time.avg) < 100μs')]
    public function benchStepRunStateChecks(): void
    {
        $this->stepRun->status();
        $this->stepRun->isRunning();
        $this->stepRun->isSucceeded();
        $this->stepRun->isFailed();
    }

    /**
     * Benchmark workflow state comparison.
     */
    #[Bench\Revs(10000)]
    #[Bench\Iterations(10)]
    #[Bench\Assert('mode(variant.time.avg) < 100μs')]
    public function benchWorkflowStateComparison(): void
    {
        $this->workflowInstance->state() === WorkflowState::Running;
        $this->workflowInstance->state() === WorkflowState::Succeeded;
        $this->workflowInstance->state() === WorkflowState::Failed;
    }

    /**
     * Benchmark step state comparison.
     */
    #[Bench\Revs(10000)]
    #[Bench\Iterations(10)]
    #[Bench\Assert('mode(variant.time.avg) < 100μs')]
    public function benchStepStateComparison(): void
    {
        $this->stepRun->status() === StepState::Running;
        $this->stepRun->status() === StepState::Succeeded;
        $this->stepRun->status() === StepState::Failed;
    }
}
