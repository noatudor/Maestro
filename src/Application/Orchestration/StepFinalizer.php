<?php

declare(strict_types=1);

namespace Maestro\Workflow\Application\Orchestration;

use Carbon\CarbonImmutable;
use Maestro\Workflow\Contracts\FanOutStep;
use Maestro\Workflow\Contracts\JobLedgerRepository;
use Maestro\Workflow\Contracts\StepDefinition;
use Maestro\Workflow\Contracts\StepRunRepository;
use Maestro\Workflow\Definition\Config\NOfMCriteria;
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

        $jobStats = $this->calculateJobStats($stepRun);

        if (! $jobStats->allJobsComplete()) {
            return StepFinalizationResult::notReady($stepRun);
        }

        $success = $this->evaluateSuccess($stepDefinition, $jobStats);
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
                $this->buildFailureMessage($jobStats),
                $jobStats->failed,
                $finishedAt,
            );
        }

        if (! $finalized) {
            return StepFinalizationResult::alreadyFinalized($stepRun);
        }

        $updatedStepRun = $this->stepRunRepository->find($stepRun->id);
        if ($updatedStepRun === null) {
            return StepFinalizationResult::alreadyFinalized($stepRun);
        }

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

        $jobStats = $this->calculateJobStats($stepRun);

        return $jobStats->allJobsComplete();
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
    private function evaluateSuccess(StepDefinition $stepDefinition, StepJobStats $stats): bool
    {
        if ($stats->total === 0) {
            return true;
        }

        if ($stepDefinition instanceof FanOutStep) {
            return $this->evaluateFanOutSuccess($stepDefinition, $stats);
        }

        return $stats->failed === 0;
    }

    private function evaluateFanOutSuccess(FanOutStep $stepDefinition, StepJobStats $stats): bool
    {
        $criteria = $stepDefinition->successCriteria();

        if ($criteria instanceof NOfMCriteria) {
            return $criteria->evaluate($stats->succeeded, $stats->total);
        }

        return $criteria->evaluate($stats->succeeded, $stats->total);
    }

    private function buildFailureMessage(StepJobStats $stats): string
    {
        return sprintf(
            '%d of %d jobs failed',
            $stats->failed,
            $stats->total,
        );
    }
}
