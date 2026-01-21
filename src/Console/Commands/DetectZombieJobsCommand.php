<?php

declare(strict_types=1);

namespace Maestro\Workflow\Console\Commands;

use Illuminate\Console\Command;
use Maestro\Workflow\Application\Job\ZombieJobDetector;
use Maestro\Workflow\Domain\JobRecord;
use Maestro\Workflow\Exceptions\InvalidStateTransitionException;
use Maestro\Workflow\ValueObjects\WorkflowId;

/**
 * Artisan command to detect and handle zombie jobs.
 *
 * Intended for scheduled execution to periodically clean up
 * jobs that have exceeded their expected runtime.
 */
final class DetectZombieJobsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'maestro:detect-zombies
        {--timeout=30 : Timeout in minutes for running jobs}
        {--stale-dispatched : Also detect stale dispatched jobs}
        {--stale-timeout=60 : Timeout in minutes for dispatched but not started jobs}';

    /**
     * @var string
     */
    protected $description = 'Detect and mark failed jobs that have exceeded their expected runtime (zombies)';

    /**
     * @throws InvalidStateTransitionException
     */
    public function handle(ZombieJobDetector $zombieJobDetector): int
    {
        $timeout = (int) $this->option('timeout');
        $detectStale = (bool) $this->option('stale-dispatched');
        $staleTimeout = (int) $this->option('stale-timeout');

        $this->info('Detecting zombie jobs...');

        $zombieJobDetectionResult = $zombieJobDetector->detect($timeout);

        if ($zombieJobDetectionResult->hasZombies()) {
            $this->warn(sprintf(
                'Found and marked %d zombie job(s) as failed.',
                $zombieJobDetectionResult->markedFailedCount,
            ));

            $this->table(
                ['Job UUID', 'Job Class', 'Workflow ID', 'Started At'],
                array_map(
                    static fn (JobRecord $jobRecord): array => [
                        $jobRecord->jobUuid,
                        $jobRecord->jobClass,
                        $jobRecord->workflowId->value,
                        $jobRecord->startedAt()?->toIso8601String() ?? 'N/A',
                    ],
                    $zombieJobDetectionResult->detectedJobs,
                ),
            );

            $this->info(sprintf(
                'Affected workflow IDs: %s',
                implode(', ', array_map(static fn (WorkflowId $workflowId): string => $workflowId->value, $zombieJobDetectionResult->affectedWorkflowIds)),
            ));
        } else {
            $this->info('No zombie jobs detected.');
        }

        if ($detectStale) {
            $this->newLine();
            $this->info('Detecting stale dispatched jobs...');

            $staleResult = $zombieJobDetector->detectStaleDispatched($staleTimeout);

            if ($staleResult->hasZombies()) {
                $this->warn(sprintf(
                    'Found and marked %d stale dispatched job(s) as failed.',
                    $staleResult->markedFailedCount,
                ));

                $this->table(
                    ['Job UUID', 'Job Class', 'Workflow ID', 'Dispatched At'],
                    array_map(
                        static fn (JobRecord $jobRecord): array => [
                            $jobRecord->jobUuid,
                            $jobRecord->jobClass,
                            $jobRecord->workflowId->value,
                            $jobRecord->dispatchedAt->toIso8601String(),
                        ],
                        $staleResult->detectedJobs,
                    ),
                );
            } else {
                $this->info('No stale dispatched jobs detected.');
            }
        }

        return self::SUCCESS;
    }
}
