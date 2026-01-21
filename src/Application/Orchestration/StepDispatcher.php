<?php

declare(strict_types=1);

namespace Maestro\Workflow\Application\Orchestration;

use Maestro\Workflow\Application\Context\WorkflowContextProviderFactory;
use Maestro\Workflow\Application\Dependency\StepDependencyChecker;
use Maestro\Workflow\Application\Job\JobDispatchService;
use Maestro\Workflow\Application\Job\OrchestratedJob;
use Maestro\Workflow\Application\Output\StepOutputStoreFactory;
use Maestro\Workflow\Contracts\FanOutStep;
use Maestro\Workflow\Contracts\SingleJobStep;
use Maestro\Workflow\Contracts\StepDefinition;
use Maestro\Workflow\Contracts\StepRunRepository;
use Maestro\Workflow\Definition\WorkflowDefinitionRegistry;
use Maestro\Workflow\Domain\StepRun;
use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\Exceptions\DefinitionNotFoundException;
use Maestro\Workflow\Exceptions\InvalidStateTransitionException;
use Maestro\Workflow\Exceptions\StepDependencyException;

/**
 * Handles dispatching jobs for workflow steps.
 *
 * Creates step run records and dispatches jobs based on step type
 * (single job or fan-out).
 */
final readonly class StepDispatcher
{
    public function __construct(
        private StepRunRepository $stepRunRepository,
        private JobDispatchService $jobDispatchService,
        private StepDependencyChecker $dependencyChecker,
        private StepOutputStoreFactory $outputStoreFactory,
        private WorkflowContextProviderFactory $contextProviderFactory,
        private WorkflowDefinitionRegistry $definitionRegistry,
    ) {}

    /**
     * Dispatch a step for execution.
     *
     * Creates a step run record and dispatches the appropriate job(s).
     *
     * @throws StepDependencyException
     * @throws InvalidStateTransitionException
     * @throws DefinitionNotFoundException
     */
    public function dispatchStep(WorkflowInstance $workflow, StepDefinition $stepDefinition): StepRun
    {
        $this->validateDependencies($workflow, $stepDefinition);

        $existingStepRun = $this->stepRunRepository->findLatestByWorkflowIdAndStepKey(
            $workflow->id,
            $stepDefinition->key(),
        );

        $attempt = $existingStepRun !== null ? $existingStepRun->attempt : 0;

        $stepRun = StepRun::create(
            workflowId: $workflow->id,
            stepKey: $stepDefinition->key(),
            attempt: $attempt + 1,
        );

        $stepRun->start();

        if ($stepDefinition instanceof FanOutStep) {
            $this->dispatchFanOutJobs($workflow, $stepRun, $stepDefinition);
        } elseif ($stepDefinition instanceof SingleJobStep) {
            $this->dispatchSingleJob($workflow, $stepRun, $stepDefinition);
        }

        $this->stepRunRepository->save($stepRun);

        return $stepRun;
    }

    /**
     * Retry a failed step by creating a new step run.
     *
     * @throws StepDependencyException
     * @throws InvalidStateTransitionException
     * @throws DefinitionNotFoundException
     */
    public function retryStep(WorkflowInstance $workflow, StepDefinition $stepDefinition): StepRun
    {
        return $this->dispatchStep($workflow, $stepDefinition);
    }

    /**
     * @throws StepDependencyException
     */
    private function validateDependencies(WorkflowInstance $workflow, StepDefinition $stepDefinition): void
    {
        if (! $this->dependencyChecker->areDependenciesMet($workflow->id, $stepDefinition)) {
            $missing = $this->dependencyChecker->getMissingDependencies($workflow->id, $stepDefinition);

            throw StepDependencyException::missingOutputs(
                $stepDefinition->key(),
                $missing,
            );
        }
    }

    private function dispatchSingleJob(
        WorkflowInstance $workflow,
        StepRun $stepRun,
        SingleJobStep $stepDefinition,
    ): void {
        /** @var class-string<OrchestratedJob> $jobClass */
        $jobClass = $stepDefinition->jobClass();
        $queueConfig = $stepDefinition->queueConfiguration();

        $job = $this->jobDispatchService->createJob(
            $jobClass,
            $workflow->id,
            $stepRun->id,
        );

        $stepRun->setTotalJobCount(1);

        $this->jobDispatchService->dispatch($job, $queueConfig);
    }

    /**
     * @throws DefinitionNotFoundException
     */
    private function dispatchFanOutJobs(
        WorkflowInstance $workflow,
        StepRun $stepRun,
        FanOutStep $stepDefinition,
    ): void {
        $definition = $this->definitionRegistry->get(
            $workflow->definitionKey,
            $workflow->definitionVersion,
        );

        $contextProvider = $this->contextProviderFactory->forWorkflow($workflow->id, $definition);
        $outputStore = $this->outputStoreFactory->forWorkflow($workflow->id);

        $iteratorFactory = $stepDefinition->itemIteratorFactory();
        $items = $iteratorFactory($contextProvider->get(), $outputStore);

        /** @var class-string<OrchestratedJob> $jobClass */
        $jobClass = $stepDefinition->jobClass();
        $queueConfig = $stepDefinition->queueConfiguration();
        $argumentsFactory = $stepDefinition->jobArgumentsFactory();

        $jobs = [];
        $itemCount = 0;

        foreach ($items as $item) {
            $arguments = [];
            if ($argumentsFactory !== null) {
                $arguments = $argumentsFactory($item, $contextProvider->get(), $outputStore);
            } else {
                $arguments = ['item' => $item];
            }

            $job = $this->createFanOutJob($jobClass, $workflow, $stepRun, $arguments);
            $jobs[] = $job;
            $itemCount++;
        }

        $stepRun->setTotalJobCount($itemCount);

        if ($itemCount === 0) {
            return;
        }

        $this->jobDispatchService->dispatchMany($jobs, $queueConfig);
    }

    /**
     * @param class-string<OrchestratedJob> $jobClass
     * @param array<string, mixed> $arguments
     */
    private function createFanOutJob(
        string $jobClass,
        WorkflowInstance $workflow,
        StepRun $stepRun,
        array $arguments,
    ): OrchestratedJob {
        $jobUuid = $this->jobDispatchService->generateJobUuid();

        /** @var OrchestratedJob */
        return new $jobClass(
            $workflow->id,
            $stepRun->id,
            $jobUuid,
            ...$arguments,
        );
    }
}
