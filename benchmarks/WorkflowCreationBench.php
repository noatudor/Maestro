<?php

declare(strict_types=1);

namespace Maestro\Workflow\Benchmarks;

use Maestro\Workflow\Definition\Builders\SingleJobStepBuilder;
use Maestro\Workflow\Definition\Builders\WorkflowDefinitionBuilder;
use Maestro\Workflow\Definition\StepCollection;
use Maestro\Workflow\Definition\WorkflowDefinition;
use Maestro\Workflow\Definition\WorkflowDefinitionRegistry;
use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\Tests\Fakes\InMemoryWorkflowRepository;
use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\DefinitionVersion;
use PhpBench\Attributes as Bench;

/**
 * Benchmarks for workflow creation operations.
 *
 * Target: < 5ms for workflow creation
 */
#[Bench\BeforeMethods(['setUp'])]
final class WorkflowCreationBench
{
    private InMemoryWorkflowRepository $workflowRepository;

    private WorkflowDefinitionRegistry $workflowDefinitionRegistry;

    private WorkflowDefinition $simpleDefinition;

    private WorkflowDefinition $complexDefinition;

    public function setUp(): void
    {
        $this->workflowRepository = new InMemoryWorkflowRepository();
        $this->workflowDefinitionRegistry = new WorkflowDefinitionRegistry();

        $this->simpleDefinition = $this->createSimpleWorkflowDefinition();
        $this->complexDefinition = $this->createComplexWorkflowDefinition();

        $this->workflowDefinitionRegistry->register($this->simpleDefinition);
        $this->workflowDefinitionRegistry->register($this->complexDefinition);
    }

    /**
     * Benchmark creating a workflow instance.
     */
    #[Bench\Revs(1000)]
    #[Bench\Iterations(10)]
    #[Bench\Assert('mode(variant.time.avg) < 5ms')]
    public function benchCreateWorkflowInstance(): void
    {
        WorkflowInstance::create(
            definitionKey: $this->simpleDefinition->key(),
            definitionVersion: $this->simpleDefinition->version(),
        );
    }

    /**
     * Benchmark creating and saving a workflow instance.
     */
    #[Bench\Revs(1000)]
    #[Bench\Iterations(10)]
    #[Bench\Assert('mode(variant.time.avg) < 5ms')]
    public function benchCreateAndSaveWorkflowInstance(): void
    {
        $workflowInstance = WorkflowInstance::create(
            definitionKey: $this->simpleDefinition->key(),
            definitionVersion: $this->simpleDefinition->version(),
        );

        $this->workflowRepository->save($workflowInstance);
    }

    /**
     * Benchmark creating a simple workflow definition.
     */
    #[Bench\Revs(500)]
    #[Bench\Iterations(10)]
    #[Bench\Assert('mode(variant.time.avg) < 5ms')]
    public function benchBuildSimpleWorkflowDefinition(): void
    {
        WorkflowDefinitionBuilder::create('bench-workflow-'.uniqid())
            ->displayName('Benchmark Workflow')
            ->singleJob('step-1', fn (SingleJobStepBuilder $step) => $step
                ->displayName('Step 1')
                ->job(DummyJob::class))
            ->build();
    }

    /**
     * Benchmark creating a complex workflow definition with multiple steps.
     */
    #[Bench\Revs(200)]
    #[Bench\Iterations(10)]
    #[Bench\Assert('mode(variant.time.avg) < 10ms')]
    public function benchBuildComplexWorkflowDefinition(): void
    {
        $builder = WorkflowDefinitionBuilder::create('bench-complex-'.uniqid())
            ->displayName('Complex Benchmark Workflow');

        for ($i = 1; $i <= 10; $i++) {
            $builder->singleJob("step-{$i}", fn (SingleJobStepBuilder $step) => $step
                ->displayName("Step {$i}")
                ->job(DummyJob::class));
        }

        $builder->build();
    }

    /**
     * Benchmark registering a workflow definition.
     */
    #[Bench\Revs(500)]
    #[Bench\Iterations(10)]
    #[Bench\Assert('mode(variant.time.avg) < 2ms')]
    public function benchRegisterWorkflowDefinition(): void
    {
        $definition = WorkflowDefinition::create(
            DefinitionKey::fromString('bench-register-'.uniqid()),
            DefinitionVersion::fromString('1.0.0'),
            'Benchmark Definition',
            StepCollection::empty(),
        );

        $registry = new WorkflowDefinitionRegistry();
        $registry->register($definition);
    }

    /**
     * Benchmark retrieving a workflow definition from registry.
     */
    #[Bench\Revs(1000)]
    #[Bench\Iterations(10)]
    #[Bench\Assert('mode(variant.time.avg) < 1ms')]
    public function benchGetWorkflowDefinition(): void
    {
        $this->workflowDefinitionRegistry->get(
            $this->simpleDefinition->key(),
            $this->simpleDefinition->version(),
        );
    }

    private function createSimpleWorkflowDefinition(): WorkflowDefinition
    {
        return WorkflowDefinitionBuilder::create('simple-workflow')
            ->displayName('Simple Workflow')
            ->singleJob('step-1', fn (SingleJobStepBuilder $step) => $step
                ->displayName('Step 1')
                ->job(DummyJob::class))
            ->build();
    }

    private function createComplexWorkflowDefinition(): WorkflowDefinition
    {
        $builder = WorkflowDefinitionBuilder::create('complex-workflow')
            ->displayName('Complex Workflow');

        for ($i = 1; $i <= 10; $i++) {
            $builder->singleJob("step-{$i}", fn (SingleJobStepBuilder $step) => $step
                ->displayName("Step {$i}")
                ->job(DummyJob::class));
        }

        return $builder->build();
    }
}

/**
 * Dummy job class for benchmarks.
 */
final class DummyJob
{
    public function handle(): void {}
}
