<?php

declare(strict_types=1);

namespace Maestro\Workflow\Application\Orchestration;

use Maestro\Workflow\Contracts\FanOutStep;
use Maestro\Workflow\Contracts\JobLedgerRepository;
use Maestro\Workflow\Contracts\StepDefinition;
use Maestro\Workflow\Contracts\StepRunRepository;
use Maestro\Workflow\Definition\Config\NOfMCriteria;
use Maestro\Workflow\Domain\StepRun;
use Maestro\Workflow\Enums\JobState;
use Maestro\Workflow\Enums\SuccessCriteria;

/**
 * Handles step finalization when all jobs are complete.
 *
 * Checks if all jobs for a step run have completed, evaluates success criteria,
 * and transitions the step to its terminal state.
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
     * Returns a result indicating whether finalization occurred and the updated step run.
     *
     * @throws \Maestro\Workflow\Exceptions\InvalidStateTransitionException
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

        if ($success) {
            $stepRun->succeed();
        } else {
            $stepRun->fail(
                'STEP_FAILED',
                $this->buildFailureMessage($jobStats),
            );
        }

        $this->stepRunRepository->save($stepRun);

        return StepFinalizationResult::finalized($stepRun);
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
