<?php

declare(strict_types=1);

namespace Maestro\Workflow\Console\Commands;

use Illuminate\Console\Command;
use Maestro\Workflow\Application\Orchestration\FailureResolutionHandler;
use Throwable;

/**
 * Artisan command to process due auto-retries for failed workflows.
 *
 * This command should be run on a schedule (e.g., every minute) to process
 * workflows that have scheduled auto-retries.
 */
final class ProcessAutoRetriesCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'maestro:process-auto-retries
                            {--dry-run : Show which workflows would be retried without actually retrying them}';

    /**
     * @var string
     */
    protected $description = 'Process scheduled auto-retries for failed workflows';

    public function __construct(
        private readonly FailureResolutionHandler $failureResolutionHandler,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->info('Dry run mode - no workflows will be retried.');
        }

        try {
            $retriedWorkflows = $this->failureResolutionHandler->processAutoRetries();

            $count = count($retriedWorkflows);

            if ($count === 0) {
                $this->info('No workflows due for auto-retry.');

                return self::SUCCESS;
            }

            if ($dryRun) {
                $this->info(sprintf('Would retry %d workflow(s):', $count));
            } else {
                $this->info(sprintf('Successfully retried %d workflow(s):', $count));
            }

            foreach ($retriedWorkflows as $retriedWorkflow) {
                $this->info('  - '.$retriedWorkflow->value);
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Error processing auto-retries: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
