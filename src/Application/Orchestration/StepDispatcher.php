<?php

declare(strict_types=1);

namespace Maestro\Workflow\Application\Orchestration;

use Closure;
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
        private StepDependencyChecker $stepDependencyChecker,
        private StepOutputStoreFactory $stepOutputStoreFactory,
        private WorkflowContextProviderFactory $workflowContextProviderFactory,
        private WorkflowDefinitionRegistry $workflowDefinitionRegistry,
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
    public function dispatchStep(WorkflowInstance $workflowInstance, StepDefinition $stepDefinition): StepRun
    {
        $this->validateDependencies($workflowInstance, $stepDefinition);

        $existingStepRun = $this->stepRunRepository->findLatestByWorkflowIdAndStepKey(
            $workflowInstance->id,
            $stepDefinition->key(),
        );

        $attempt = $existingStepRun instanceof StepRun ? $existingStepRun->attempt : 0;

        $stepRun = StepRun::create(
            workflowId: $workflowInstance->id,
            stepKey: $stepDefinition->key(),
            attempt: $attempt + 1,
        );

        $stepRun->start();

        if ($stepDefinition instanceof FanOutStep) {
            $this->dispatchFanOutJobs($workflowInstance, $stepRun, $stepDefinition);
        } elseif ($stepDefinition instanceof SingleJobStep) {
            $this->dispatchSingleJob($workflowInstance, $stepRun, $stepDefinition);
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
    public function retryStep(WorkflowInstance $workflowInstance, StepDefinition $stepDefinition): StepRun
    {
        return $this->dispatchStep($workflowInstance, $stepDefinition);
    }

    /**
     * @throws StepDependencyException
     */
    private function validateDependencies(WorkflowInstance $workflowInstance, StepDefinition $stepDefinition): void
    {
        if (! $this->stepDependencyChecker->areDependenciesMet($workflowInstance->id, $stepDefinition)) {
            $missing = $this->stepDependencyChecker->getMissingDependencies($workflowInstance->id, $stepDefinition);

            throw StepDependencyException::missingOutputs(
                $stepDefinition->key(),
                $missing,
            );
        }
    }

    private function dispatchSingleJob(
        WorkflowInstance $workflowInstance,
        StepRun $stepRun,
        SingleJobStep $singleJobStep,
    ): void {
        /** @var class-string<OrchestratedJob> $jobClass */
        $jobClass = $singleJobStep->jobClass();
        $queueConfiguration = $singleJobStep->queueConfiguration();

        $orchestratedJob = $this->jobDispatchService->createJob(
            $jobClass,
            $workflowInstance->id,
            $stepRun->id,
        );

        $stepRun->setTotalJobCount(1);

        $this->jobDispatchService->dispatch($orchestratedJob, $queueConfiguration);
    }

    /**
     * @throws DefinitionNotFoundException
     */
    private function dispatchFanOutJobs(
        WorkflowInstance $workflowInstance,
        StepRun $stepRun,
        FanOutStep $fanOutStep,
    ): void {
        $workflowDefinition = $this->workflowDefinitionRegistry->get(
            $workflowInstance->definitionKey,
            $workflowInstance->definitionVersion,
        );

        $workflowContextProvider = $this->workflowContextProviderFactory->forWorkflow($workflowInstance->id, $workflowDefinition);
        $stepOutputStore = $this->stepOutputStoreFactory->forWorkflow($workflowInstance->id);

        $iteratorFactory = $fanOutStep->itemIteratorFactory();
        $items = $iteratorFactory($workflowContextProvider->get(), $stepOutputStore);

        /** @var class-string<OrchestratedJob> $jobClass */
        $jobClass = $fanOutStep->jobClass();
        $queueConfiguration = $fanOutStep->queueConfiguration();
        $argumentsFactory = $fanOutStep->jobArgumentsFactory();

        $jobs = [];
        $itemCount = 0;

        foreach ($items as $item) {
            $arguments = [];
            if ($argumentsFactory instanceof Closure) {
                $arguments = $argumentsFactory($item, $workflowContextProvider->get(), $stepOutputStore);
            } else {
                $arguments = ['item' => $item];
            }

            $job = $this->createFanOutJob($jobClass, $workflowInstance, $stepRun, $arguments);
            $jobs[] = $job;
            $itemCount++;
        }

        $stepRun->setTotalJobCount($itemCount);

        if ($itemCount === 0) {
            return;
        }

        $this->jobDispatchService->dispatchMany($jobs, $queueConfiguration);
    }

    /**
     * @param class-string<OrchestratedJob> $jobClass
     * @param array<string, mixed> $arguments
     */
    private function createFanOutJob(
        string $jobClass,
        WorkflowInstance $workflowInstance,
        StepRun $stepRun,
        array $arguments,
    ): OrchestratedJob {
        $jobUuid = $this->jobDispatchService->generateJobUuid();

        return new $jobClass(
            $workflowInstance->id,
            $stepRun->id,
            $jobUuid,
            ...$arguments,
        );
    }
}
