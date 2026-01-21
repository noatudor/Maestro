<?php

declare(strict_types=1);

namespace Maestro\Workflow\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Maestro\Workflow\Contracts\StepDefinition;
use Maestro\Workflow\Contracts\StepRunRepository;
use Maestro\Workflow\Contracts\WorkflowRepository;
use Maestro\Workflow\Definition\WorkflowDefinition;
use Maestro\Workflow\Definition\WorkflowDefinitionRegistry;
use Maestro\Workflow\Domain\StepRun;
use Maestro\Workflow\Enums\StepState;
use Maestro\Workflow\Exceptions\InvalidStateTransitionException;

/**
 * Check for timed out steps and workflows and handle them appropriately.
 */
final class CheckWorkflowTimeoutsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'maestro:check-timeouts
                            {--step-timeout=3600 : Default step timeout in seconds}
                            {--dry-run : Show what would be done without making changes}';

    /**
     * @var string
     */
    protected $description = 'Check for timed out workflow steps and mark them as failed';

    public function __construct(
        private readonly WorkflowRepository $workflowRepository,
        private readonly StepRunRepository $stepRunRepository,
        private readonly WorkflowDefinitionRegistry $workflowDefinitionRegistry,
    ) {
        parent::__construct();
    }

    /**
     * @throws InvalidStateTransitionException
     */
    public function handle(): int
    {
        $defaultTimeout = (int) $this->option('step-timeout');
        $dryRun = (bool) $this->option('dry-run');

        $this->info('Checking for timed out steps...');

        $runningWorkflows = $this->workflowRepository->findRunning();
        $timedOutCount = 0;

        foreach ($runningWorkflows as $runningWorkflow) {
            $currentStepKey = $runningWorkflow->currentStepKey();
            if ($currentStepKey === null) {
                continue;
            }

            $stepRun = $this->stepRunRepository->findLatestByWorkflowIdAndStepKey(
                $runningWorkflow->id,
                $currentStepKey,
            );
            if (! $stepRun instanceof StepRun) {
                continue;
            }
            if ($stepRun->status() !== StepState::Running) {
                continue;
            }

            $definition = $this->workflowDefinitionRegistry->find(
                $runningWorkflow->definitionKey,
                $runningWorkflow->definitionVersion,
            );

            if (! $definition instanceof WorkflowDefinition) {
                continue;
            }

            $stepDefinition = $definition->getStep($currentStepKey);
            if (! $stepDefinition instanceof StepDefinition) {
                continue;
            }

            $timeout = $stepDefinition->timeout();
            $stepTimeoutSeconds = $timeout->stepTimeoutSeconds ?? $defaultTimeout;

            if ($stepTimeoutSeconds <= 0) {
                continue;
            }

            $startedAt = $stepRun->startedAt();
            if (! $startedAt instanceof CarbonImmutable) {
                continue;
            }

            $threshold = $startedAt->addSeconds($stepTimeoutSeconds);

            if (CarbonImmutable::now()->greaterThan($threshold)) {
                $timedOutCount++;

                if ($dryRun) {
                    $this->warn(sprintf(
                        'Would mark step run %s (workflow %s, step %s) as timed out',
                        $stepRun->id->value,
                        $runningWorkflow->id->value,
                        $currentStepKey->value,
                    ));
                } else {
                    $stepRun->fail('STEP_TIMEOUT', 'Step execution exceeded timeout');
                    $this->stepRunRepository->save($stepRun);

                    $this->info(sprintf(
                        'Marked step run %s as timed out',
                        $stepRun->id->value,
                    ));
                }
            }
        }

        $this->info(sprintf('Found %d timed out step(s)', $timedOutCount));

        return self::SUCCESS;
    }
}
