<?php

declare(strict_types=1);

namespace Maestro\Workflow\Console\Commands;

use Illuminate\Console\Command;
use Maestro\Workflow\Application\Orchestration\CompensationExecutor;
use Maestro\Workflow\Contracts\CompensationRunRepository;
use Maestro\Workflow\Contracts\WorkflowRepository;
use Maestro\Workflow\Domain\CompensationRun;
use Maestro\Workflow\Exceptions\InvalidStateTransitionException;
use Maestro\Workflow\Exceptions\WorkflowNotFoundException;
use Maestro\Workflow\ValueObjects\CompensationRunId;
use Maestro\Workflow\ValueObjects\WorkflowId;

use function Laravel\Prompts\select;

/**
 * Artisan command to skip a failed or pending compensation step.
 */
final class SkipCompensationCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'maestro:skip-compensation
                            {workflow : The workflow ID}
                            {--compensation-run= : The compensation run ID to skip (optional)}
                            {--all : Skip all remaining compensation steps}
                            {--force : Apply without confirmation}';

    /**
     * @var string
     */
    protected $description = 'Skip a failed compensation step and continue with remaining compensations';

    public function __construct(
        private readonly CompensationExecutor $compensationExecutor,
        private readonly WorkflowRepository $workflowRepository,
        private readonly CompensationRunRepository $compensationRunRepository,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        /** @var string $workflowIdString */
        $workflowIdString = $this->argument('workflow');
        $workflowId = WorkflowId::fromString($workflowIdString);

        try {
            $workflow = $this->workflowRepository->findOrFail($workflowId);
        } catch (WorkflowNotFoundException) {
            $this->error('Workflow not found: '.$workflowIdString);

            return self::FAILURE;
        }

        if (! $workflow->isCompensationFailed()) {
            $this->error('Workflow compensation has not failed. Current state: '.$workflow->state()->value);

            return self::FAILURE;
        }

        $skipAll = (bool) $this->option('all');
        $force = (bool) $this->option('force');

        if ($skipAll) {
            return $this->skipAllRemaining($workflowId, $force);
        }

        return $this->skipSingleStep($workflowId, $force);
    }

    private function skipAllRemaining(WorkflowId $workflowId, bool $force): int
    {
        if (! $force) {
            $this->warn('This will skip all remaining compensation steps and mark the workflow as compensated.');

            if (! $this->confirm('Are you sure you want to skip all remaining compensation steps?')) {
                $this->info('Operation cancelled.');

                return self::SUCCESS;
            }
        }

        try {
            $this->compensationExecutor->skipRemaining($workflowId);
            $this->info('All remaining compensation steps have been skipped.');

            return self::SUCCESS;
        } catch (WorkflowNotFoundException) {
            $this->error('Workflow not found.');

            return self::FAILURE;
        } catch (InvalidStateTransitionException $e) {
            $this->error('Invalid state transition: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    private function skipSingleStep(WorkflowId $workflowId, bool $force): int
    {
        /** @var string|null $compensationRunIdOption */
        $compensationRunIdOption = $this->option('compensation-run');

        if ($compensationRunIdOption !== null && $compensationRunIdOption !== '') {
            $compensationRunId = CompensationRunId::fromString($compensationRunIdOption);
        } else {
            $compensationRunId = $this->selectFailedCompensationRun($workflowId);

            if (! $compensationRunId instanceof CompensationRunId) {
                $this->error('No failed compensation run found to skip.');

                return self::FAILURE;
            }
        }

        $compensationRun = $this->compensationRunRepository->find($compensationRunId);

        if (! $compensationRun instanceof CompensationRun) {
            $this->error('Compensation run not found: '.$compensationRunId->value);

            return self::FAILURE;
        }

        if (! $compensationRun->isFailed()) {
            $this->error('Compensation run is not in failed state. Current status: '.$compensationRun->status()->value);

            return self::FAILURE;
        }

        if (! $force) {
            $this->info('Compensation Run Details:');
            $this->info('  Step: '.$compensationRun->stepKey->value);
            $this->info('  Attempts: '.$compensationRun->attempt().'/'.$compensationRun->maxAttempts());

            $failureMessage = $compensationRun->failureMessage();

            if ($failureMessage !== null) {
                $this->info('  Failure: '.$failureMessage);
            }

            if (! $this->confirm('Skip this compensation step and continue?')) {
                $this->info('Operation cancelled.');

                return self::SUCCESS;
            }
        }

        try {
            $this->compensationExecutor->skipStep($compensationRunId);
            $this->info(sprintf('Compensation step %s has been skipped.', $compensationRun->stepKey->value));

            return self::SUCCESS;
        } catch (WorkflowNotFoundException) {
            $this->error('Workflow not found.');

            return self::FAILURE;
        } catch (InvalidStateTransitionException $e) {
            $this->error('Invalid state transition: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    private function selectFailedCompensationRun(WorkflowId $workflowId): ?CompensationRunId
    {
        $runs = $this->compensationRunRepository->findByWorkflow($workflowId);

        $failedRuns = [];

        foreach ($runs as $run) {
            if ($run->isFailed()) {
                $failedRuns[$run->id->value] = sprintf(
                    '%s (attempt %d/%d)',
                    $run->stepKey->value,
                    $run->attempt(),
                    $run->maxAttempts(),
                );
            }
        }

        if ($failedRuns === []) {
            return null;
        }

        if (count($failedRuns) === 1) {
            return CompensationRunId::fromString(array_key_first($failedRuns));
        }

        /** @var string $selected */
        $selected = select(
            label: 'Select the compensation step to skip:',
            options: $failedRuns,
        );

        return CompensationRunId::fromString($selected);
    }
}
