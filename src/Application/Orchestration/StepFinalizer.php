<?php

declare(strict_types=1);

namespace Maestro\Workflow\Application\Orchestration;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Maestro\Workflow\Contracts\FanOutStep;
use Maestro\Workflow\Contracts\JobLedgerRepository;
use Maestro\Workflow\Contracts\StepDefinition;
use Maestro\Workflow\Contracts\StepRunRepository;
use Maestro\Workflow\Domain\Events\StepFailed;
use Maestro\Workflow\Domain\Events\StepSucceeded;
use Maestro\Workflow\Domain\StepRun;
use Maestro\Workflow\Enums\JobState;

/**
 * Handles step finalization when all jobs are complete.
 *
 * Checks if all jobs for a step run have completed, evaluates success criteria,
 * and transitions the step to its terminal state using atomic operations.
 */
final readonly class StepFinalizer
{
    public function __construct(
        private StepRunRepository $stepRunRepository,
        private JobLedgerRepository $jobLedgerRepository,
        private Dispatcher $eventDispatcher,
    ) {}

    /**
     * Try to finalize a step if all jobs are complete.
     *
     * Uses atomic database operations to prevent race conditions in fan-in scenarios.
     * Only one worker will successfully finalize the step; others will receive
     * a result indicating they did not win the race.
     *
     * @return StepFinalizationResult Result indicating whether this worker finalized the step
     */
    public function tryFinalize(StepRun $stepRun, StepDefinition $stepDefinition): StepFinalizationResult
    {
        if (! $stepRun->isRunning()) {
            return StepFinalizationResult::notReady($stepRun);
        }

        $stepJobStats = $this->calculateJobStats($stepRun);

        if (! $stepJobStats->allJobsComplete()) {
            return StepFinalizationResult::notReady($stepRun);
        }

        $success = $this->evaluateSuccess($stepDefinition, $stepJobStats);
        $finishedAt = CarbonImmutable::now();

        if ($success) {
            $finalized = $this->stepRunRepository->finalizeAsSucceeded(
                $stepRun->id,
                $finishedAt,
            );
        } else {
            $finalized = $this->stepRunRepository->finalizeAsFailed(
                $stepRun->id,
                'STEP_FAILED',
                $this->buildFailureMessage($stepJobStats),
                $stepJobStats->failed,
                $finishedAt,
            );
        }

        if (! $finalized) {
            return StepFinalizationResult::alreadyFinalized($stepRun);
        }

        $updatedStepRun = $this->stepRunRepository->find($stepRun->id);
        if (! $updatedStepRun instanceof StepRun) {
            return StepFinalizationResult::alreadyFinalized($stepRun);
        }

        $this->dispatchFinalizationEvent($updatedStepRun, $stepJobStats, $success);

        return StepFinalizationResult::finalized($updatedStepRun);
    }

    /**
     * Check if a step is ready for finalization.
     */
    public function isReadyForFinalization(StepRun $stepRun): bool
    {
        if (! $stepRun->isRunning()) {
            return false;
        }

        $stepJobStats = $this->calculateJobStats($stepRun);

        return $stepJobStats->allJobsComplete();
    }

    /**
     * Calculate job statistics for a step run.
     */
    public function calculateJobStats(StepRun $stepRun): StepJobStats
    {
        $totalJobs = $stepRun->totalJobCount();

        if ($totalJobs === 0) {
            $totalJobs = $this->jobLedgerRepository->countByStepRunId($stepRun->id);
        }

        $succeededJobs = $this->jobLedgerRepository->countByStepRunIdAndState(
            $stepRun->id,
            JobState::Succeeded,
        );

        $failedJobs = $this->jobLedgerRepository->countByStepRunIdAndState(
            $stepRun->id,
            JobState::Failed,
        );

        $runningJobs = $this->jobLedgerRepository->countByStepRunIdAndState(
            $stepRun->id,
            JobState::Running,
        );

        $dispatchedJobs = $this->jobLedgerRepository->countByStepRunIdAndState(
            $stepRun->id,
            JobState::Dispatched,
        );

        return StepJobStats::create(
            total: $totalJobs,
            succeeded: $succeededJobs,
            failed: $failedJobs,
            running: $runningJobs,
            dispatched: $dispatchedJobs,
        );
    }

    /**
     * Evaluate success based on the step definition's criteria.
     */
    private function evaluateSuccess(StepDefinition $stepDefinition, StepJobStats $stepJobStats): bool
    {
        if ($stepJobStats->total === 0) {
            return true;
        }

        if ($stepDefinition instanceof FanOutStep) {
            return $this->evaluateFanOutSuccess($stepDefinition, $stepJobStats);
        }

        return $stepJobStats->failed === 0;
    }

    private function evaluateFanOutSuccess(FanOutStep $fanOutStep, StepJobStats $stepJobStats): bool
    {
        $criteria = $fanOutStep->successCriteria();

        return $criteria->evaluate($stepJobStats->succeeded, $stepJobStats->total);
    }

    private function buildFailureMessage(StepJobStats $stepJobStats): string
    {
        return sprintf(
            '%d of %d jobs failed',
            $stepJobStats->failed,
            $stepJobStats->total,
        );
    }

    private function dispatchFinalizationEvent(
        StepRun $stepRun,
        StepJobStats $stepJobStats,
        bool $success,
    ): void {
        if ($success) {
            $this->eventDispatcher->dispatch(new StepSucceeded(
                workflowId: $stepRun->workflowId,
                stepRunId: $stepRun->id,
                stepKey: $stepRun->stepKey,
                attempt: $stepRun->attempt,
                totalJobCount: $stepJobStats->total,
                durationMs: $stepRun->duration(),
                occurredAt: CarbonImmutable::now(),
            ));

            return;
        }

        $this->eventDispatcher->dispatch(new StepFailed(
            workflowId: $stepRun->workflowId,
            stepRunId: $stepRun->id,
            stepKey: $stepRun->stepKey,
            attempt: $stepRun->attempt,
            failedJobCount: $stepJobStats->failed,
            totalJobCount: $stepJobStats->total,
            failureCode: $stepRun->failureCode(),
            failureMessage: $stepRun->failureMessage(),
            durationMs: $stepRun->duration(),
            occurredAt: CarbonImmutable::now(),
        ));
    }
}
