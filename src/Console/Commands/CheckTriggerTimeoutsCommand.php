<?php

declare(strict_types=1);

namespace Maestro\Workflow\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Maestro\Workflow\Application\Orchestration\PauseTriggerHandler;
use Maestro\Workflow\Application\Orchestration\WorkflowAdvancer;
use Maestro\Workflow\Contracts\StepDefinition;
use Maestro\Workflow\Contracts\WorkflowRepository;
use Maestro\Workflow\Definition\Config\PauseTriggerDefinition;
use Maestro\Workflow\Definition\WorkflowDefinitionRegistry;
use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\Enums\WorkflowState;
use Maestro\Workflow\Exceptions\DefinitionNotFoundException;
use Maestro\Workflow\ValueObjects\StepKey;
use Throwable;

/**
 * Process timed-out trigger deadlines.
 *
 * Queries for paused workflows with trigger_timeout_at <= now() and applies
 * the configured timeout policy (fail, auto-resume, send reminder, extend).
 *
 * Should be run every minute via Laravel scheduler.
 */
final class CheckTriggerTimeoutsCommand extends Command
{
    protected $signature = 'maestro:check-trigger-timeouts
        {--limit=100 : Maximum number of workflows to process}
        {--dry-run : Show what would be processed without making changes}';

    protected $description = 'Process workflows with timed-out trigger deadlines';

    public function __construct(
        private readonly WorkflowRepository $workflowRepository,
        private readonly WorkflowDefinitionRegistry $workflowDefinitionRegistry,
        private readonly PauseTriggerHandler $pauseTriggerHandler,
        private readonly WorkflowAdvancer $workflowAdvancer,
    ) {
        parent::__construct();
    }

    /**
     * @throws DefinitionNotFoundException
     */
    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $dryRun = (bool) $this->option('dry-run');

        $this->info('Checking for timed-out trigger deadlines...');

        $timedOutWorkflows = $this->workflowRepository->findByStateAndTriggerTimeoutBefore(
            WorkflowState::Paused,
            CarbonImmutable::now(),
            $limit,
        );

        if ($timedOutWorkflows === []) {
            $this->info('No timed-out workflows found.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Found %d workflow(s) with timed-out triggers.', count($timedOutWorkflows)));

        $processed = 0;
        $failed = 0;

        foreach ($timedOutWorkflows as $timedOutWorkflow) {
            $this->processTimedOutWorkflow($timedOutWorkflow, $dryRun, $processed, $failed);
        }

        $this->info(sprintf('Processed: %d, Failed: %d', $processed, $failed));

        return self::SUCCESS;
    }

    /**
     * @throws DefinitionNotFoundException
     */
    private function processTimedOutWorkflow(
        WorkflowInstance $workflowInstance,
        bool $dryRun,
        int &$processed,
        int &$failed,
    ): void {
        $stepDefinition = $this->getCurrentStepDefinition($workflowInstance);

        if (! $stepDefinition instanceof StepDefinition) {
            $this->warn(sprintf('Workflow %s: No step definition found, skipping.', $workflowInstance->id->value));
            $failed++;

            return;
        }

        $pauseTrigger = $stepDefinition->pauseTrigger();

        if (! $pauseTrigger instanceof PauseTriggerDefinition) {
            $this->warn(sprintf('Workflow %s: No pause trigger configuration found, skipping.', $workflowInstance->id->value));
            $failed++;

            return;
        }

        if ($dryRun) {
            $this->line(sprintf(
                '  [DRY RUN] Would process timeout for workflow %s with policy: %s',
                $workflowInstance->id->value,
                $pauseTrigger->timeoutPolicy->value,
            ));
            $processed++;

            return;
        }

        try {
            $this->pauseTriggerHandler->handleTimeout($workflowInstance, $pauseTrigger);

            $refreshedWorkflow = $this->workflowRepository->find($workflowInstance->id);
            if ($refreshedWorkflow instanceof WorkflowInstance && $refreshedWorkflow->isRunning()) {
                $this->workflowAdvancer->evaluate($workflowInstance->id);
            }

            $this->line(sprintf(
                '  Processed workflow %s with policy: %s',
                $workflowInstance->id->value,
                $pauseTrigger->timeoutPolicy->value,
            ));
            $processed++;
        } catch (Throwable $e) {
            $this->error(sprintf(
                '  Failed to process workflow %s: %s',
                $workflowInstance->id->value,
                $e->getMessage(),
            ));
            $failed++;
        }
    }

    /**
     * @throws DefinitionNotFoundException
     */
    private function getCurrentStepDefinition(WorkflowInstance $workflowInstance): ?StepDefinition
    {
        $currentStepKey = $workflowInstance->currentStepKey();
        if (! $currentStepKey instanceof StepKey) {
            return null;
        }

        $workflowDefinition = $this->workflowDefinitionRegistry->get(
            $workflowInstance->definitionKey,
            $workflowInstance->definitionVersion,
        );

        return $workflowDefinition->getStep($currentStepKey);
    }
}
