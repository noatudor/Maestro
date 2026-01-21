<?php

declare(strict_types=1);

namespace Maestro\Workflow\Application\Job;

use Carbon\CarbonImmutable;
use Maestro\Workflow\Contracts\JobLedgerRepository;
use Maestro\Workflow\Exceptions\InvalidStateTransitionException;

/**
 * Detects and handles zombie jobs.
 *
 * A zombie job is one marked as RUNNING but whose worker has died
 * without properly reporting completion or failure.
 */
final readonly class ZombieJobDetector
{
    private const int DEFAULT_TIMEOUT_MINUTES = 30;

    private const string ZOMBIE_FAILURE_MESSAGE = 'Job exceeded maximum runtime and was marked as failed by zombie detection.';

    private const string STALE_FAILURE_MESSAGE = 'Job was dispatched but never started within the expected timeframe.';

    public function __construct(
        private JobLedgerRepository $jobLedgerRepository,
    ) {}

    /**
     * Detect and handle zombie jobs.
     *
     * @param int $timeoutMinutes Jobs running longer than this are considered zombies
     *
     * @throws InvalidStateTransitionException
     */
    public function detect(int $timeoutMinutes = self::DEFAULT_TIMEOUT_MINUTES): ZombieJobDetectionResult
    {
        $threshold = CarbonImmutable::now()->subMinutes($timeoutMinutes);

        $jobRecordCollection = $this->jobLedgerRepository->findZombieJobs($threshold);

        if ($jobRecordCollection->isEmpty()) {
            return ZombieJobDetectionResult::empty();
        }

        $detectedJobs = [];
        $affectedWorkflowIds = [];
        $markedFailedCount = 0;

        foreach ($jobRecordCollection as $zombieJob) {
            $detectedJobs[] = $zombieJob;

            $workflowId = $zombieJob->workflowId;
            $workflowIdValue = $workflowId->value;
            if (! isset($affectedWorkflowIds[$workflowIdValue])) {
                $affectedWorkflowIds[$workflowIdValue] = $workflowId;
            }

            $zombieJob->fail(
                failureClass: 'Maestro\\Workflow\\Exceptions\\ZombieJobException',
                failureMessage: self::ZOMBIE_FAILURE_MESSAGE,
                failureTrace: sprintf(
                    'Job started at %s, exceeded timeout of %d minutes.',
                    $zombieJob->startedAt()?->toIso8601String() ?? 'unknown',
                    $timeoutMinutes,
                ),
            );

            $this->jobLedgerRepository->save($zombieJob);
            $markedFailedCount++;
        }

        return new ZombieJobDetectionResult(
            detectedJobs: $detectedJobs,
            affectedWorkflowIds: array_values($affectedWorkflowIds),
            markedFailedCount: $markedFailedCount,
        );
    }

    /**
     * Detect stale dispatched jobs (dispatched but never started).
     *
     * These may indicate queue issues or jobs that were lost.
     *
     * @param int $timeoutMinutes Jobs dispatched longer than this without starting
     *
     * @throws InvalidStateTransitionException
     */
    public function detectStaleDispatched(int $timeoutMinutes = self::DEFAULT_TIMEOUT_MINUTES): ZombieJobDetectionResult
    {
        $threshold = CarbonImmutable::now()->subMinutes($timeoutMinutes);

        $jobRecordCollection = $this->jobLedgerRepository->findStaleDispatchedJobs($threshold);

        if ($jobRecordCollection->isEmpty()) {
            return ZombieJobDetectionResult::empty();
        }

        $detectedJobs = [];
        $affectedWorkflowIds = [];
        $markedFailedCount = 0;

        foreach ($jobRecordCollection as $staleJob) {
            $detectedJobs[] = $staleJob;

            $workflowId = $staleJob->workflowId;
            $workflowIdValue = $workflowId->value;
            if (! isset($affectedWorkflowIds[$workflowIdValue])) {
                $affectedWorkflowIds[$workflowIdValue] = $workflowId;
            }

            $staleJob->fail(
                failureClass: 'Maestro\\Workflow\\Exceptions\\StaleJobException',
                failureMessage: self::STALE_FAILURE_MESSAGE,
                failureTrace: sprintf(
                    'Job dispatched at %s, exceeded dispatch timeout of %d minutes.',
                    $staleJob->dispatchedAt->toIso8601String(),
                    $timeoutMinutes,
                ),
            );

            $this->jobLedgerRepository->save($staleJob);
            $markedFailedCount++;
        }

        return new ZombieJobDetectionResult(
            detectedJobs: $detectedJobs,
            affectedWorkflowIds: array_values($affectedWorkflowIds),
            markedFailedCount: $markedFailedCount,
        );
    }
}
