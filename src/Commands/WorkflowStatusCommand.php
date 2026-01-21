<?php

declare(strict_types=1);

namespace Maestro\Workflow\Commands;

use Illuminate\Console\Command;
use Maestro\Workflow\Contracts\JobLedgerRepository;
use Maestro\Workflow\Contracts\StepRunRepository;
use Maestro\Workflow\Contracts\WorkflowRepository;
use Maestro\Workflow\Domain\StepRun;
use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\Enums\WorkflowState;
use Maestro\Workflow\Exceptions\WorkflowNotFoundException;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\WorkflowId;

/**
 * Artisan command to check workflow status and overview.
 */
final class WorkflowStatusCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'maestro:status
                            {workflow? : The workflow ID to check}
                            {--state= : Filter overview by state (pending, running, paused, succeeded, failed, cancelled)}
                            {--limit=20 : Limit the number of workflows shown in overview}';

    /**
     * @var string
     */
    protected $description = 'Check the status of workflows';

    public function __construct(
        private readonly WorkflowRepository $workflowRepository,
        private readonly StepRunRepository $stepRunRepository,
        private readonly JobLedgerRepository $jobLedgerRepository,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $workflowIdString = $this->argument('workflow');

        if (is_string($workflowIdString)) {
            return $this->showWorkflowStatus($workflowIdString);
        }

        return $this->showOverview();
    }

    private function showWorkflowStatus(string $workflowIdString): int
    {
        $workflowId = WorkflowId::fromString($workflowIdString);

        try {
            $workflow = $this->workflowRepository->findOrFail($workflowId);
        } catch (WorkflowNotFoundException) {
            $this->error('Workflow not found: '.$workflowIdString);

            return self::FAILURE;
        }

        $this->displayWorkflowDetails($workflow);
        $this->newLine();
        $this->displayStepRuns($workflow);
        $this->newLine();
        $this->displayJobSummary($workflow);

        return self::SUCCESS;
    }

    private function showOverview(): int
    {
        $this->info('Maestro Workflow Overview');
        $this->newLine();

        $this->displayStateCounts();
        $this->newLine();

        /** @var string|null $stateFilter */
        $stateFilter = $this->option('state');
        $limit = (int) $this->option('limit');

        if ($stateFilter !== null) {
            $state = WorkflowState::tryFrom($stateFilter);
            if ($state === null) {
                $this->error(sprintf('Invalid state: %s. Valid states: pending, running, paused, succeeded, failed, cancelled', $stateFilter));

                return self::FAILURE;
            }
            $workflows = array_values($this->workflowRepository->findByState($state));
        } else {
            $running = $this->workflowRepository->findRunning();
            $paused = $this->workflowRepository->findPaused();
            $failed = $this->workflowRepository->findFailed();
            $workflows = array_merge($running, $paused, $failed);
        }

        $workflows = array_slice($workflows, 0, $limit);

        if ($workflows === []) {
            $this->info('No active workflows found.');

            return self::SUCCESS;
        }

        $this->info('Active Workflows:');
        $this->table(
            ['ID', 'Definition', 'State', 'Current Step', 'Updated At'],
            array_map(
                static function (WorkflowInstance $workflowInstance): array {
                    $stepKey = $workflowInstance->currentStepKey();

                    return [
                        substr($workflowInstance->id->value, 0, 8).'...',
                        $workflowInstance->definitionKey->value,
                        $workflowInstance->state()->value,
                        $stepKey instanceof StepKey ? $stepKey->value : '-',
                        $workflowInstance->updatedAt()->diffForHumans(),
                    ];
                },
                $workflows,
            ),
        );

        return self::SUCCESS;
    }

    private function displayStateCounts(): void
    {
        $counts = [];
        foreach (WorkflowState::cases() as $state) {
            $workflows = $this->workflowRepository->findByState($state);
            $counts[$state->value] = count($workflows);
        }

        $this->table(
            ['State', 'Count'],
            array_map(
                static fn (string $state, int $count): array => [$state, (string) $count],
                array_keys($counts),
                array_values($counts),
            ),
        );
    }

    private function displayWorkflowDetails(WorkflowInstance $workflowInstance): void
    {
        $currentStepKey = $workflowInstance->currentStepKey();

        $this->info('Workflow Details');
        $this->table(
            ['Property', 'Value'],
            [
                ['ID', $workflowInstance->id->value],
                ['Definition', $workflowInstance->definitionKey->value],
                ['Version', $workflowInstance->definitionVersion->toString()],
                ['State', $workflowInstance->state()->value],
                ['Current Step', $currentStepKey instanceof StepKey ? $currentStepKey->value : '-'],
                ['Created At', $workflowInstance->createdAt->toDateTimeString()],
                ['Updated At', $workflowInstance->updatedAt()->toDateTimeString()],
                ['Paused At', $workflowInstance->pausedAt()?->toDateTimeString() ?? '-'],
                ['Paused Reason', $workflowInstance->pausedReason() ?? '-'],
                ['Failed At', $workflowInstance->failedAt()?->toDateTimeString() ?? '-'],
                ['Failure Code', $workflowInstance->failureCode() ?? '-'],
                ['Failure Message', $workflowInstance->failureMessage() ?? '-'],
                ['Succeeded At', $workflowInstance->succeededAt()?->toDateTimeString() ?? '-'],
                ['Cancelled At', $workflowInstance->cancelledAt()?->toDateTimeString() ?? '-'],
                ['Locked By', $workflowInstance->lockedBy() ?? '-'],
            ],
        );
    }

    private function displayStepRuns(WorkflowInstance $workflowInstance): void
    {
        $stepRunCollection = $this->stepRunRepository->findByWorkflowId($workflowInstance->id);

        if ($stepRunCollection->isEmpty()) {
            $this->info('No step runs recorded.');

            return;
        }

        $this->info('Step Runs:');
        $this->table(
            ['Step', 'Attempt', 'Status', 'Jobs', 'Started', 'Finished'],
            $stepRunCollection->map(
                static fn (StepRun $stepRun): array => [
                    $stepRun->stepKey->value,
                    (string) $stepRun->attempt,
                    $stepRun->status()->value,
                    $stepRun->completedJobCount().'/'.$stepRun->totalJobCount(),
                    $stepRun->startedAt()?->toDateTimeString() ?? '-',
                    $stepRun->finishedAt()?->toDateTimeString() ?? '-',
                ],
            ),
        );
    }

    private function displayJobSummary(WorkflowInstance $workflowInstance): void
    {
        $jobRecordCollection = $this->jobLedgerRepository->findByWorkflowId($workflowInstance->id);

        $dispatched = 0;
        $running = 0;
        $succeeded = 0;
        $failed = 0;

        foreach ($jobRecordCollection as $job) {
            if ($job->isDispatched()) {
                $dispatched++;
            } elseif ($job->isRunning()) {
                $running++;
            } elseif ($job->isSucceeded()) {
                $succeeded++;
            } elseif ($job->isFailed()) {
                $failed++;
            }
        }

        $this->info('Job Summary:');
        $this->table(
            ['Status', 'Count'],
            [
                ['Dispatched', (string) $dispatched],
                ['Running', (string) $running],
                ['Succeeded', (string) $succeeded],
                ['Failed', (string) $failed],
                ['Total', (string) count($jobRecordCollection)],
            ],
        );
    }
}
