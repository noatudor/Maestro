<?php

declare(strict_types=1);

namespace Maestro\Workflow\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Maestro\Workflow\Contracts\StepRunRepository;
use Maestro\Workflow\Contracts\WorkflowRepository;
use Maestro\Workflow\Definition\WorkflowDefinitionRegistry;
use Maestro\Workflow\Enums\StepState;

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
        private readonly WorkflowDefinitionRegistry $definitionRegistry,
    ) {
        parent::__construct();
    }

    /**
     * @throws \Maestro\Workflow\Exceptions\InvalidStateTransitionException
     */
    public function handle(): int
    {
        $defaultTimeout = (int) $this->option('step-timeout');
        $dryRun = (bool) $this->option('dry-run');

        $this->info('Checking for timed out steps...');

        $runningWorkflows = $this->workflowRepository->findRunning();
        $timedOutCount = 0;

        foreach ($runningWorkflows as $workflow) {
            $currentStepKey = $workflow->currentStepKey();
            if ($currentStepKey === null) {
                continue;
            }

            $stepRun = $this->stepRunRepository->findLatestByWorkflowIdAndStepKey(
                $workflow->id,
                $currentStepKey,
            );

            if ($stepRun === null || $stepRun->status() !== StepState::Running) {
                continue;
            }

            $definition = $this->definitionRegistry->find(
                $workflow->definitionKey,
                $workflow->definitionVersion,
            );

            if ($definition === null) {
                continue;
            }

            $stepDefinition = $definition->getStep($currentStepKey);
            if ($stepDefinition === null) {
                continue;
            }

            $timeout = $stepDefinition->timeout();
            $stepTimeoutSeconds = $timeout->stepTimeoutSeconds ?? $defaultTimeout;

            if ($stepTimeoutSeconds <= 0) {
                continue;
            }

            $startedAt = $stepRun->startedAt();
            if ($startedAt === null) {
                continue;
            }

            $threshold = $startedAt->addSeconds($stepTimeoutSeconds);

            if (CarbonImmutable::now()->greaterThan($threshold)) {
                $timedOutCount++;

                if ($dryRun) {
                    $this->warn(sprintf(
                        'Would mark step run %s (workflow %s, step %s) as timed out',
                        $stepRun->id->value,
                        $workflow->id->value,
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
