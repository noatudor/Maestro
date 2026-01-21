<?php

declare(strict_types=1);

namespace Maestro\Workflow\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Maestro\Workflow\Contracts\JobLedgerRepository;
use Maestro\Workflow\Contracts\StepOutputRepository;
use Maestro\Workflow\Contracts\StepRunRepository;
use Maestro\Workflow\Contracts\WorkflowRepository;
use Maestro\Workflow\Domain\WorkflowInstance;
use Throwable;

/**
 * Artisan command to clean up old completed workflows.
 *
 * Deletes terminal workflows (succeeded, failed, cancelled) and all their
 * associated data (step runs, job records, step outputs).
 */
final class CleanupWorkflowsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'maestro:cleanup
                            {--days=30 : Delete workflows older than this many days}
                            {--succeeded : Only delete succeeded workflows}
                            {--failed : Only delete failed workflows}
                            {--cancelled : Only delete cancelled workflows}
                            {--limit=1000 : Maximum number of workflows to delete per run}
                            {--force : Delete without confirmation}
                            {--dry-run : Show what would be deleted without making changes}';

    /**
     * @var string
     */
    protected $description = 'Clean up old completed workflows and their associated data';

    public function __construct(
        private readonly WorkflowRepository $workflowRepository,
        private readonly StepRunRepository $stepRunRepository,
        private readonly JobLedgerRepository $jobLedgerRepository,
        private readonly StepOutputRepository $stepOutputRepository,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $limit = (int) $this->option('limit');
        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');

        $threshold = CarbonImmutable::now()->subDays($days);
        $workflows = $this->workflowRepository->findTerminalBefore($threshold);

        $workflows = $this->filterByState($workflows);
        $workflows = array_slice($workflows, 0, $limit);

        $count = count($workflows);

        if ($count === 0) {
            $this->info(sprintf('No workflows older than %d days found matching criteria.', $days));

            return self::SUCCESS;
        }

        $this->info(sprintf('Found %d workflow(s) to clean up:', $count));
        $this->newLine();

        $this->table(
            ['ID', 'Definition', 'State', 'Completed At'],
            array_map(
                static fn (WorkflowInstance $workflowInstance): array => [
                    $workflowInstance->id->value,
                    $workflowInstance->definitionKey->value.' v'.$workflowInstance->definitionVersion->toString(),
                    $workflowInstance->state()->value,
                    $workflowInstance->updatedAt()->toDateTimeString(),
                ],
                $workflows,
            ),
        );

        if ($dryRun) {
            $this->warn(sprintf('Dry run: Would delete %d workflow(s) and their associated data.', $count));

            return self::SUCCESS;
        }

        if (! $force && ! $this->confirm(sprintf('Do you want to delete %d workflow(s) and all associated data?', $count))) {
            $this->info('Cleanup cancelled.');

            return self::SUCCESS;
        }

        $deleted = 0;
        $failed = 0;

        $this->output->progressStart($count);

        foreach ($workflows as $workflow) {
            try {
                $this->deleteWorkflowAndRelatedData($workflow);
                $deleted++;
            } catch (Throwable $e) { // @phpstan-ignore catch.neverThrown (Database exceptions can occur)
                $this->newLine();
                $this->error(sprintf('Failed to delete workflow %s: %s', $workflow->id->value, $e->getMessage()));
                $failed++;
            }

            $this->output->progressAdvance();
        }

        $this->output->progressFinish();
        $this->newLine();

        $this->info(sprintf('Deleted: %d, Failed: %d', $deleted, $failed));

        return $failed > 0 ? self::FAILURE : self::SUCCESS; // @phpstan-ignore greater.alwaysFalse
    }

    /**
     * @param list<WorkflowInstance> $workflows
     *
     * @return list<WorkflowInstance>
     */
    private function filterByState(array $workflows): array
    {
        $succeededOnly = (bool) $this->option('succeeded');
        $failedOnly = (bool) $this->option('failed');
        $cancelledOnly = (bool) $this->option('cancelled');

        if (! $succeededOnly && ! $failedOnly && ! $cancelledOnly) {
            return $workflows;
        }

        return array_values(array_filter(
            $workflows,
            static function (WorkflowInstance $workflowInstance) use ($succeededOnly, $failedOnly, $cancelledOnly): bool {
                if ($succeededOnly && $workflowInstance->isSucceeded()) {
                    return true;
                }
                if ($failedOnly && $workflowInstance->isFailed()) {
                    return true;
                }

                return $cancelledOnly && $workflowInstance->isCancelled();
            },
        ));
    }

    private function deleteWorkflowAndRelatedData(WorkflowInstance $workflowInstance): void
    {
        $this->jobLedgerRepository->deleteByWorkflowId($workflowInstance->id);
        $this->stepOutputRepository->deleteByWorkflowId($workflowInstance->id);
        $this->stepRunRepository->deleteByWorkflowId($workflowInstance->id);
        $this->workflowRepository->delete($workflowInstance->id);
    }
}
