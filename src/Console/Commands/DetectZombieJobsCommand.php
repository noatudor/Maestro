<?php

declare(strict_types=1);

namespace Maestro\Workflow\Console\Commands;

use Illuminate\Console\Command;
use Maestro\Workflow\Application\Job\ZombieJobDetector;

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
     * @throws \Maestro\Workflow\Exceptions\InvalidStateTransitionException
     */
    public function handle(ZombieJobDetector $detector): int
    {
        $timeout = (int) $this->option('timeout');
        $detectStale = (bool) $this->option('stale-dispatched');
        $staleTimeout = (int) $this->option('stale-timeout');

        $this->info('Detecting zombie jobs...');

        $result = $detector->detect($timeout);

        if ($result->hasZombies()) {
            $this->warn(sprintf(
                'Found and marked %d zombie job(s) as failed.',
                $result->markedFailedCount,
            ));

            $this->table(
                ['Job UUID', 'Job Class', 'Workflow ID', 'Started At'],
                array_map(
                    static fn ($job) => [
                        $job->jobUuid,
                        $job->jobClass,
                        $job->workflowId->value,
                        $job->startedAt()?->toIso8601String() ?? 'N/A',
                    ],
                    $result->detectedJobs,
                ),
            );

            $this->info(sprintf(
                'Affected workflow IDs: %s',
                implode(', ', array_map(static fn ($id) => $id->value, $result->affectedWorkflowIds)),
            ));
        } else {
            $this->info('No zombie jobs detected.');
        }

        if ($detectStale) {
            $this->newLine();
            $this->info('Detecting stale dispatched jobs...');

            $staleResult = $detector->detectStaleDispatched($staleTimeout);

            if ($staleResult->hasZombies()) {
                $this->warn(sprintf(
                    'Found and marked %d stale dispatched job(s) as failed.',
                    $staleResult->markedFailedCount,
                ));

                $this->table(
                    ['Job UUID', 'Job Class', 'Workflow ID', 'Dispatched At'],
                    array_map(
                        static fn ($job) => [
                            $job->jobUuid,
                            $job->jobClass,
                            $job->workflowId->value,
                            $job->dispatchedAt->toIso8601String(),
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
