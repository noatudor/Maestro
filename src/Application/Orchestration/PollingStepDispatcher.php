<?php

declare(strict_types=1);

namespace Maestro\Workflow\Application\Orchestration;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Maestro\Workflow\Application\Branching\ConditionEvaluator;
use Maestro\Workflow\Application\Job\JobDispatchService;
use Maestro\Workflow\Application\Job\PollingJob;
use Maestro\Workflow\Application\Output\StepOutputStoreFactory;
use Maestro\Workflow\Contracts\PollAttemptRepository;
use Maestro\Workflow\Contracts\PollingStep;
use Maestro\Workflow\Contracts\StepRunRepository;
use Maestro\Workflow\Definition\Config\PollingConfiguration;
use Maestro\Workflow\Domain\Events\StepStarted;
use Maestro\Workflow\Domain\PollAttempt;
use Maestro\Workflow\Domain\StepRun;
use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\Enums\SkipReason;
use Maestro\Workflow\Exceptions\ConditionEvaluationException;
use Maestro\Workflow\Exceptions\InvalidStateTransitionException;
use Maestro\Workflow\ValueObjects\StepDispatchResult;

/**
 * Handles dispatching polling steps.
 *
 * Creates step runs in polling state and dispatches initial poll jobs.
 * Manages scheduling of subsequent poll attempts.
 */
final readonly class PollingStepDispatcher
{
    public function __construct(
        private StepRunRepository $stepRunRepository,
        private PollAttemptRepository $pollAttemptRepository,
        private JobDispatchService $jobDispatchService,
        private StepOutputStoreFactory $stepOutputStoreFactory,
        private ConditionEvaluator $conditionEvaluator,
        private Dispatcher $eventDispatcher,
    ) {}

    /**
     * Dispatch a polling step.
     *
     * Creates a step run in polling state and dispatches the initial poll job.
     *
     * @throws InvalidStateTransitionException
     * @throws ConditionEvaluationException
     */
    public function dispatchPollingStep(
        WorkflowInstance $workflowInstance,
        PollingStep $pollingStep,
    ): StepDispatchResult {
        $existingStepRun = $this->stepRunRepository->findLatestByWorkflowIdAndStepKey(
            $workflowInstance->id,
            $pollingStep->key(),
        );

        $attempt = $existingStepRun instanceof StepRun ? $existingStepRun->attempt : 0;

        $conditionClass = $pollingStep->conditionClass();
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
                    $pollingStep,
                    $attempt + 1,
                    $skipReason,
                    $conditionResult->skipMessage(),
                );
            }
        }

        $stepRun = StepRun::create(
            workflowId: $workflowInstance->id,
            stepKey: $pollingStep->key(),
            attempt: $attempt + 1,
        );

        $stepRun->startPolling();
        $stepRun->setTotalJobCount(1);

        $pollingStep->pollingConfiguration();
        $nextPollAt = CarbonImmutable::now();
        $stepRun->scheduleNextPoll($nextPollAt);

        $this->stepRunRepository->save($stepRun);

        $this->dispatchPollJob($workflowInstance, $stepRun, $pollingStep);

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
     * Dispatch a poll job for an existing polling step run.
     */
    public function dispatchPollJob(
        WorkflowInstance $workflowInstance,
        StepRun $stepRun,
        PollingStep $pollingStep,
    ): void {
        /** @var class-string<PollingJob> $jobClass */
        $jobClass = $pollingStep->jobClass();
        $queueConfiguration = $pollingStep->queueConfiguration();

        $previousAttempts = $this->pollAttemptRepository->findByStepRun($stepRun->id);
        $currentAttemptNumber = count($previousAttempts) + 1;

        $pollingJob = $this->createPollingJob(
            $jobClass,
            $workflowInstance,
            $stepRun,
            $previousAttempts,
            $currentAttemptNumber,
        );

        $this->jobDispatchService->dispatch($pollingJob, $queueConfiguration);
    }

    /**
     * Schedule the next poll for a step run.
     *
     * Calculates the interval based on configuration and sets next_poll_at.
     */
    public function scheduleNextPoll(
        StepRun $stepRun,
        PollingConfiguration $pollingConfiguration,
        ?int $overrideIntervalSeconds = null,
    ): void {
        $interval = $pollingConfiguration->calculateIntervalForAttempt(
            $stepRun->pollAttemptCount() + 1,
            $overrideIntervalSeconds,
        );

        $nextPollAt = CarbonImmutable::now()->addSeconds($interval);
        $stepRun->scheduleNextPoll($nextPollAt);
        $this->stepRunRepository->save($stepRun);
    }

    /**
     * Skip a polling step due to a condition not being met.
     *
     * @throws InvalidStateTransitionException
     */
    private function skipStep(
        WorkflowInstance $workflowInstance,
        PollingStep $pollingStep,
        int $attempt,
        SkipReason $skipReason,
        ?string $message = null,
    ): StepDispatchResult {
        $stepRun = StepRun::create(
            workflowId: $workflowInstance->id,
            stepKey: $pollingStep->key(),
            attempt: $attempt,
        );

        $stepRun->skip($skipReason, $message);

        $this->stepRunRepository->save($stepRun);

        return StepDispatchResult::skipped($stepRun);
    }

    /**
     * @param class-string<PollingJob> $jobClass
     * @param list<PollAttempt> $previousAttempts
     */
    private function createPollingJob(
        string $jobClass,
        WorkflowInstance $workflowInstance,
        StepRun $stepRun,
        array $previousAttempts,
        int $currentAttemptNumber,
    ): PollingJob {
        $jobUuid = $this->jobDispatchService->generateJobUuid();

        $pollingJob = new $jobClass(
            $workflowInstance->id,
            $stepRun->id,
            $jobUuid,
        );

        $pollingJob->setPreviousAttempts($previousAttempts);
        $pollingJob->setCurrentAttemptNumber($currentAttemptNumber);

        return $pollingJob;
    }
}
