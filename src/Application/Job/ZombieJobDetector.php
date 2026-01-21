<?php

declare(strict_types=1);

namespace Maestro\Workflow\Application\Job;

use Carbon\CarbonImmutable;
use Maestro\Workflow\Contracts\JobLedgerRepository;
use Maestro\Workflow\Domain\JobRecord;
use Maestro\Workflow\ValueObjects\WorkflowId;

/**
 * Result of zombie job detection and handling.
 */
final readonly class ZombieJobDetectionResult
{
    /**
     * @param list<JobRecord> $detectedJobs
     * @param list<WorkflowId> $affectedWorkflowIds
     */
    public function __construct(
        public array $detectedJobs,
        public array $affectedWorkflowIds,
        public int $markedFailedCount,
    ) {}

    public static function empty(): self
    {
        return new self([], [], 0);
    }

    public function hasZombies(): bool
    {
        return $this->markedFailedCount > 0;
    }
}

/**
 * Detects and handles zombie jobs.
 *
 * A zombie job is one marked as RUNNING but whose worker has died
 * without properly reporting completion or failure.
 */
final readonly class ZombieJobDetector
{
    private const DEFAULT_TIMEOUT_MINUTES = 30;

    private const ZOMBIE_FAILURE_MESSAGE = 'Job exceeded maximum runtime and was marked as failed by zombie detection.';

    private const STALE_FAILURE_MESSAGE = 'Job was dispatched but never started within the expected timeframe.';

    public function __construct(
        private JobLedgerRepository $jobLedger,
    ) {}

    /**
     * Detect and handle zombie jobs.
     *
     * @param int $timeoutMinutes Jobs running longer than this are considered zombies
     *
     * @throws \Maestro\Workflow\Exceptions\InvalidStateTransitionException
     */
    public function detect(int $timeoutMinutes = self::DEFAULT_TIMEOUT_MINUTES): ZombieJobDetectionResult
    {
        $threshold = CarbonImmutable::now()->subMinutes($timeoutMinutes);

        $zombieJobs = $this->jobLedger->findZombieJobs($threshold);

        if ($zombieJobs->isEmpty()) {
            return ZombieJobDetectionResult::empty();
        }

        $detectedJobs = [];
        $affectedWorkflowIds = [];
        $markedFailedCount = 0;

        foreach ($zombieJobs as $jobRecord) {
            $detectedJobs[] = $jobRecord;

            $workflowId = $jobRecord->workflowId;
            $workflowIdValue = $workflowId->value;
            if (! isset($affectedWorkflowIds[$workflowIdValue])) {
                $affectedWorkflowIds[$workflowIdValue] = $workflowId;
            }

            $jobRecord->fail(
                failureClass: 'Maestro\\Workflow\\Exceptions\\ZombieJobException',
                failureMessage: self::ZOMBIE_FAILURE_MESSAGE,
                failureTrace: sprintf(
                    'Job started at %s, exceeded timeout of %d minutes.',
                    $jobRecord->startedAt()?->toIso8601String() ?? 'unknown',
                    $timeoutMinutes,
                ),
            );

            $this->jobLedger->save($jobRecord);
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
     * @throws \Maestro\Workflow\Exceptions\InvalidStateTransitionException
     */
    public function detectStaleDispatched(int $timeoutMinutes = self::DEFAULT_TIMEOUT_MINUTES): ZombieJobDetectionResult
    {
        $threshold = CarbonImmutable::now()->subMinutes($timeoutMinutes);

        $staleJobs = $this->jobLedger->findStaleDispatchedJobs($threshold);

        if ($staleJobs->isEmpty()) {
            return ZombieJobDetectionResult::empty();
        }

        $detectedJobs = [];
        $affectedWorkflowIds = [];
        $markedFailedCount = 0;

        foreach ($staleJobs as $jobRecord) {
            $detectedJobs[] = $jobRecord;

            $workflowId = $jobRecord->workflowId;
            $workflowIdValue = $workflowId->value;
            if (! isset($affectedWorkflowIds[$workflowIdValue])) {
                $affectedWorkflowIds[$workflowIdValue] = $workflowId;
            }

            $jobRecord->fail(
                failureClass: 'Maestro\\Workflow\\Exceptions\\StaleJobException',
                failureMessage: self::STALE_FAILURE_MESSAGE,
                failureTrace: sprintf(
                    'Job dispatched at %s, exceeded dispatch timeout of %d minutes.',
                    $jobRecord->dispatchedAt->toIso8601String(),
                    $timeoutMinutes,
                ),
            );

            $this->jobLedger->save($jobRecord);
            $markedFailedCount++;
        }

        return new ZombieJobDetectionResult(
            detectedJobs: $detectedJobs,
            affectedWorkflowIds: array_values($affectedWorkflowIds),
            markedFailedCount: $markedFailedCount,
        );
    }
}
