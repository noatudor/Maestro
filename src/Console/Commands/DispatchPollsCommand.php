<?php

declare(strict_types=1);

namespace Maestro\Workflow\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Maestro\Workflow\Application\Orchestration\PollingStepDispatcher;
use Maestro\Workflow\Contracts\PollingStep;
use Maestro\Workflow\Contracts\StepRunRepository;
use Maestro\Workflow\Contracts\WorkflowRepository;
use Maestro\Workflow\Definition\WorkflowDefinitionRegistry;
use Maestro\Workflow\Enums\StepState;
use Maestro\Workflow\Infrastructure\Persistence\Models\StepRunModel;
use Maestro\Workflow\ValueObjects\StepRunId;
use Throwable;

/**
 * Artisan command to dispatch due poll jobs.
 *
 * This command queries for step runs in POLLING state with next_poll_at <= now
 * and dispatches their poll jobs. Should be run every minute via scheduler.
 */
final class DispatchPollsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'maestro:dispatch-polls
                            {--limit=100 : Maximum number of polls to dispatch per run}
                            {--dry-run : Show which polls would be dispatched without dispatching}';

    /**
     * @var string
     */
    protected $description = 'Dispatch due poll jobs for polling steps';

    public function __construct(
        private readonly StepRunRepository $stepRunRepository,
        private readonly WorkflowRepository $workflowRepository,
        private readonly WorkflowDefinitionRegistry $workflowDefinitionRegistry,
        private readonly PollingStepDispatcher $pollingStepDispatcher,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->info('Dry run mode - no polls will be dispatched.');
        }

        $now = CarbonImmutable::now();
        $dispatchedCount = 0;
        $errorCount = 0;

        $builder = StepRunModel::query()
            ->where('status', StepState::Polling->value)
            ->where('next_poll_at', '<=', $now);

        // @phpstan-ignore-next-line - Laravel Eloquent query builder chaining
        $duePolls = $builder->orderBy('next_poll_at')->limit($limit)->get();

        if ($duePolls->isEmpty()) {
            $this->info('No polls due for dispatch.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Found %d poll(s) due for dispatch.', $duePolls->count()));

        foreach ($duePolls as $duePoll) {
            try {
                $stepRun = $this->stepRunRepository->findOrFail(
                    StepRunId::fromString($duePoll->id),
                );

                $workflowInstance = $this->workflowRepository->findOrFail($stepRun->workflowId);

                $workflowDefinition = $this->workflowDefinitionRegistry->get(
                    $workflowInstance->definitionKey,
                    $workflowInstance->definitionVersion,
                );

                $stepDefinition = $workflowDefinition->steps()->findByKey($stepRun->stepKey);

                if (! $stepDefinition instanceof PollingStep) {
                    $this->warn(sprintf(
                        'Step run %s is in polling state but step definition is not a PollingStep.',
                        $stepRun->id->value,
                    ));

                    continue;
                }

                if ($dryRun) {
                    $this->info(sprintf(
                        '  Would dispatch poll for step run %s (workflow %s, step %s)',
                        $stepRun->id->value,
                        $workflowInstance->id->value,
                        $stepRun->stepKey->value,
                    ));
                    $dispatchedCount++;

                    continue;
                }

                $this->pollingStepDispatcher->dispatchPollJob(
                    $workflowInstance,
                    $stepRun,
                    $stepDefinition,
                );

                $this->info(sprintf(
                    '  Dispatched poll for step run %s',
                    $stepRun->id->value,
                ));
                $dispatchedCount++;
            } catch (Throwable $e) {
                $this->error(sprintf(
                    '  Error dispatching poll for step run %s: %s',
                    $duePoll->id,
                    $e->getMessage(),
                ));
                $errorCount++;
            }
        }

        $this->info(sprintf(
            'Dispatched %d poll(s) with %d error(s).',
            $dispatchedCount,
            $errorCount,
        ));

        return $errorCount > 0 ? self::FAILURE : self::SUCCESS;
    }
}
