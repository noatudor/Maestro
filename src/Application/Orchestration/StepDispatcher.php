<?php

declare(strict_types=1);

namespace Maestro\Workflow\Application\Orchestration;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Maestro\Workflow\Application\Branching\ConditionEvaluator;
use Maestro\Workflow\Application\Context\WorkflowContextProviderFactory;
use Maestro\Workflow\Application\Dependency\StepDependencyChecker;
use Maestro\Workflow\Application\Job\JobDispatchService;
use Maestro\Workflow\Application\Job\OrchestratedJob;
use Maestro\Workflow\Application\Output\StepOutputStoreFactory;
use Maestro\Workflow\Contracts\FanOutStep;
use Maestro\Workflow\Contracts\PollingStep;
use Maestro\Workflow\Contracts\SingleJobStep;
use Maestro\Workflow\Contracts\StepDefinition;
use Maestro\Workflow\Contracts\StepRunRepository;
use Maestro\Workflow\Definition\WorkflowDefinitionRegistry;
use Maestro\Workflow\Domain\Events\StepRetried;
use Maestro\Workflow\Domain\Events\StepSkipped;
use Maestro\Workflow\Domain\Events\StepStarted;
use Maestro\Workflow\Domain\StepRun;
use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\Enums\SkipReason;
use Maestro\Workflow\Exceptions\ConditionEvaluationException;
use Maestro\Workflow\Exceptions\DefinitionNotFoundException;
use Maestro\Workflow\Exceptions\InvalidStateTransitionException;
use Maestro\Workflow\Exceptions\StepDependencyException;
use Maestro\Workflow\ValueObjects\StepDispatchResult;

/**
 * Handles dispatching jobs for workflow steps.
 *
 * Creates step run records and dispatches jobs based on step type
 * (single job or fan-out). Also evaluates step conditions and may
 * skip steps if conditions are not met.
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
        private ConditionEvaluator $conditionEvaluator,
        private Dispatcher $eventDispatcher,
        private ?PollingStepDispatcher $pollingStepDispatcher = null,
    ) {}

    /**
     * Dispatch a step for execution.
     *
     * Creates a step run record and dispatches the appropriate job(s).
     * If the step has a condition that evaluates to false, the step is skipped.
     *
     * @throws StepDependencyException
     * @throws InvalidStateTransitionException
     * @throws DefinitionNotFoundException
     * @throws ConditionEvaluationException
     */
    public function dispatchStep(WorkflowInstance $workflowInstance, StepDefinition $stepDefinition): StepRun
    {
        $stepDispatchResult = $this->dispatchStepWithResult($workflowInstance, $stepDefinition);

        return $stepDispatchResult->stepRun();
    }

    /**
     * Dispatch a step and return a result indicating if it was dispatched or skipped.
     *
     * @throws StepDependencyException
     * @throws InvalidStateTransitionException
     * @throws DefinitionNotFoundException
     * @throws ConditionEvaluationException
     */
    public function dispatchStepWithResult(
        WorkflowInstance $workflowInstance,
        StepDefinition $stepDefinition,
    ): StepDispatchResult {
        $this->validateDependencies($workflowInstance, $stepDefinition);

        $existingStepRun = $this->stepRunRepository->findLatestByWorkflowIdAndStepKey(
            $workflowInstance->id,
            $stepDefinition->key(),
        );

        $attempt = $existingStepRun instanceof StepRun ? $existingStepRun->attempt : 0;

        $conditionClass = $stepDefinition->conditionClass();
        if ($conditionClass !== null) {
            $stepOutputStore = $this->stepOutputStoreFactory->forWorkflow($workflowInstance->id);
            $conditionResult = $this->conditionEvaluator->evaluateStepCondition(
                $conditionClass,
                $stepOutputStore,
            );

            if ($conditionResult->shouldSkip()) {
                $skipReason = $conditionResult->skipReason() ?? SkipReason::ConditionFalse;

                return $this->skipStep(
                    $workflowInstance,
                    $stepDefinition,
                    $attempt + 1,
                    $skipReason,
                    $conditionResult->skipMessage(),
                );
            }
        }

        if ($stepDefinition instanceof PollingStep && $this->pollingStepDispatcher instanceof PollingStepDispatcher) {
            return $this->pollingStepDispatcher->dispatchPollingStep($workflowInstance, $stepDefinition);
        }

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

        $this->eventDispatcher->dispatch(new StepStarted(
            workflowId: $workflowInstance->id,
            stepRunId: $stepRun->id,
            stepKey: $stepRun->stepKey,
            attempt: $stepRun->attempt,
            occurredAt: CarbonImmutable::now(),
        ));

        return StepDispatchResult::dispatched($stepRun);
    }

    /**
     * Skip a step due to a condition or branch not being active.
     *
     * @throws InvalidStateTransitionException
     */
    public function skipStep(
        WorkflowInstance $workflowInstance,
        StepDefinition $stepDefinition,
        int $attempt,
        SkipReason $skipReason,
        ?string $message = null,
    ): StepDispatchResult {
        $stepRun = StepRun::create(
            workflowId: $workflowInstance->id,
            stepKey: $stepDefinition->key(),
            attempt: $attempt,
        );

        $stepRun->skip($skipReason, $message);

        $this->stepRunRepository->save($stepRun);

        $this->eventDispatcher->dispatch(new StepSkipped(
            workflowId: $workflowInstance->id,
            stepRunId: $stepRun->id,
            stepKey: $stepRun->stepKey,
            reason: $skipReason,
            message: $message,
            occurredAt: CarbonImmutable::now(),
        ));

        return StepDispatchResult::skipped($stepRun);
    }

    /**
     * Retry a failed step by creating a new step run.
     *
     * @throws StepDependencyException
     * @throws InvalidStateTransitionException
     * @throws DefinitionNotFoundException
     * @throws ConditionEvaluationException
     */
    public function retryStep(WorkflowInstance $workflowInstance, StepDefinition $stepDefinition): StepRun
    {
        $previousStepRun = $this->stepRunRepository->findLatestByWorkflowIdAndStepKey(
            $workflowInstance->id,
            $stepDefinition->key(),
        );

        $newStepRun = $this->dispatchStep($workflowInstance, $stepDefinition);

        if ($previousStepRun instanceof StepRun) {
            $this->eventDispatcher->dispatch(new StepRetried(
                workflowId: $workflowInstance->id,
                stepRunId: $newStepRun->id,
                stepKey: $newStepRun->stepKey,
                attempt: $newStepRun->attempt,
                previousStepRunId: $previousStepRun->id,
                previousAttempt: $previousStepRun->attempt,
                occurredAt: CarbonImmutable::now(),
            ));
        }

        return $newStepRun;
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
