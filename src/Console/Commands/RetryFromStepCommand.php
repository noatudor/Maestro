<?php

declare(strict_types=1);

namespace Maestro\Workflow\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use Maestro\Workflow\Application\Orchestration\RetryFromStepService;
use Maestro\Workflow\Contracts\StepRunRepository;
use Maestro\Workflow\Contracts\WorkflowRepository;
use Maestro\Workflow\Definition\WorkflowDefinition;
use Maestro\Workflow\Definition\WorkflowDefinitionRegistry;
use Maestro\Workflow\Domain\StepRun;
use Maestro\Workflow\Enums\RetryMode;
use Maestro\Workflow\Exceptions\DefinitionNotFoundException;
use Maestro\Workflow\Exceptions\InvalidStepKeyException;
use Maestro\Workflow\Exceptions\StepNotFoundException;
use Maestro\Workflow\Exceptions\WorkflowNotFoundException;
use Maestro\Workflow\ValueObjects\RetryFromStepRequest;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\WorkflowId;

/**
 * Artisan command to retry a workflow from a specific step.
 */
final class RetryFromStepCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'maestro:retry-from
                            {workflow : The workflow ID to retry}
                            {step? : The step key to retry from (interactive if not provided)}
                            {--mode=retry_only : Retry mode (retry_only or compensate_then_retry)}
                            {--reason= : Optional reason for the retry}
                            {--force : Skip confirmation}';

    /**
     * @var string
     */
    protected $description = 'Retry a workflow from a specific step';

    public function __construct(
        private readonly WorkflowRepository $workflowRepository,
        private readonly StepRunRepository $stepRunRepository,
        private readonly WorkflowDefinitionRegistry $workflowDefinitionRegistry,
        private readonly RetryFromStepService $retryFromStepService,
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

        try {
            $definition = $this->workflowDefinitionRegistry->get(
                $workflow->definitionKey,
                $workflow->definitionVersion,
            );
        } catch (DefinitionNotFoundException) {
            $this->error('Workflow definition not found');

            return self::FAILURE;
        }

        $this->info('Workflow: '.$workflow->id->value);
        $this->info('Definition: '.$definition->key()->value.' v'.$definition->version()->toString());
        $this->info('Current state: '.$workflow->state()->value);
        $currentStepKey = $workflow->currentStepKey();
        $this->info('Current step: '.($currentStepKey instanceof StepKey ? $currentStepKey->value : 'None'));

        /** @var string|null $stepKeyString */
        $stepKeyString = $this->argument('step');

        if ($stepKeyString === null) {
            $stepKeyString = $this->promptForStep($workflowId, $definition);
            if ($stepKeyString === null) {
                $this->warn('No step selected. Aborting.');

                return self::FAILURE;
            }
        }

        if (! is_string($stepKeyString)) {
            $this->error('Invalid step key provided');

            return self::FAILURE;
        }

        try {
            $stepKey = StepKey::fromString($stepKeyString);
        } catch (InvalidStepKeyException $e) {
            $this->error('Invalid step key: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! $definition->hasStep($stepKey)) {
            $this->error('Step not found in workflow definition: '.$stepKeyString);

            return self::FAILURE;
        }

        /** @var string $modeString */
        $modeString = $this->option('mode');
        $retryMode = RetryMode::tryFrom($modeString) ?? RetryMode::RetryOnly;

        $stepsAfter = $definition->steps()->stepsAfter($stepKey);
        $affectedCount = 1 + $stepsAfter->count();

        $this->newLine();
        $this->warn('This will retry from step: '.$stepKey->value);
        $this->warn('Affected steps: '.$affectedCount);
        $this->warn('Retry mode: '.$retryMode->value);

        if ($retryMode === RetryMode::CompensateThenRetry) {
            $this->warn('Compensation will be executed for completed steps.');
        }

        $this->newLine();
        $this->info('Affected steps:');
        $this->line('  - '.$stepKey->value.' (retry point)');
        foreach ($stepsAfter as $stepAfter) {
            $this->line('  - '.$stepAfter->key()->value);
        }

        if (! (bool) $this->option('force') && ! $this->confirm('Do you want to proceed?')) {
            $this->info('Aborted.');

            return self::SUCCESS;
        }

        /** @var string|null $reason */
        $reason = $this->option('reason');

        try {
            $request = RetryFromStepRequest::create(
                workflowId: $workflowId,
                retryFromStepKey: $stepKey,
                retryMode: $retryMode,
                initiatedBy: 'CLI',
                reason: $reason,
            );

            $result = $this->retryFromStepService->execute($request);

            $this->newLine();
            $this->info('Retry from step completed successfully!');
            $this->info('New step run ID: '.$result->newStepRunId->value);
            $this->info('Superseded step runs: '.$result->supersededCount());
            $this->info('Cleared outputs: '.$result->clearedOutputCount());
            $this->info('New workflow state: '.$result->workflowInstance->state()->value);

            return self::SUCCESS;
        } catch (StepNotFoundException $e) {
            $this->error('Step not found: '.$e->getMessage());

            return self::FAILURE;
        } catch (Exception $e) {
            $this->error('Failed to retry from step: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    private function promptForStep(
        WorkflowId $workflowId,
        WorkflowDefinition $workflowDefinition,
    ): ?string {
        $stepRunCollection = $this->stepRunRepository->findByWorkflowId($workflowId);

        $choices = [];
        foreach ($workflowDefinition->steps() as $step) {
            $stepRun = null;
            foreach ($stepRunCollection as $run) {
                if ($run->stepKey->equals($step->key()) && ! $run->isSuperseded()) {
                    $stepRun = $run;

                    break;
                }
            }

            $status = $stepRun instanceof StepRun ? $stepRun->status()->value : 'not started';
            $choices[$step->key()->value] = sprintf('%s (%s)', $step->key()->value, $status);
        }

        if ($choices === []) {
            $this->warn('No steps available for selection.');

            return null;
        }

        $selected = $this->choice(
            'Select the step to retry from:',
            $choices,
        );

        return is_string($selected) ? $selected : null;
    }
}
