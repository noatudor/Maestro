<?php

declare(strict_types=1);

namespace Maestro\Workflow\Console\Commands;

use Illuminate\Console\Command;
use Maestro\Workflow\Application\Orchestration\CompensationExecutor;
use Maestro\Workflow\Contracts\WorkflowRepository;
use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\Enums\CompensationScope;
use Maestro\Workflow\Exceptions\DefinitionNotFoundException;
use Maestro\Workflow\Exceptions\InvalidStateTransitionException;
use Maestro\Workflow\Exceptions\InvalidStepKeyException;
use Maestro\Workflow\Exceptions\WorkflowNotFoundException;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\WorkflowId;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * Artisan command to trigger compensation for a workflow.
 */
final class CompensateWorkflowCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'maestro:compensate
                            {workflow : The workflow ID to compensate}
                            {--scope=all : Compensation scope (all, failed_step_only)}
                            {--steps= : Comma-separated list of step keys to compensate}
                            {--reason= : Reason for triggering compensation}
                            {--by= : Who is triggering this compensation}
                            {--force : Apply without confirmation}';

    /**
     * @var string
     */
    protected $description = 'Trigger compensation for a workflow to undo completed steps';

    public function __construct(
        private readonly CompensationExecutor $compensationExecutor,
        private readonly WorkflowRepository $workflowRepository,
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

        if ($workflow->isCompensating()) {
            $this->error('Workflow is already compensating.');

            return self::FAILURE;
        }

        if ($workflow->isCompensated()) {
            $this->error('Workflow has already been compensated.');

            return self::FAILURE;
        }

        $this->displayWorkflowInfo($workflow);

        try {
            $scope = $this->getScope();
            $stepKeys = $this->getStepKeys();
        } catch (InvalidStepKeyException $e) {
            $this->error('Invalid step key: '.$e->getMessage());

            return self::FAILURE;
        }

        $reason = $this->getOptionOrPrompt('reason', 'Reason for compensation (optional)');
        $initiatedBy = $this->getOptionOrPrompt('by', 'Who is triggering this compensation (optional)');

        $force = (bool) $this->option('force');

        if (! $force) {
            $this->newLine();
            $this->info('Compensation Details:');
            $this->info('  Scope: '.$scope->value);

            if ($stepKeys !== null) {
                $stepKeyStrings = array_map(
                    static fn (StepKey $stepKey): string => $stepKey->value,
                    $stepKeys,
                );
                $this->info('  Steps: '.implode(', ', $stepKeyStrings));
            }

            if ($reason !== '') {
                $this->info('  Reason: '.$reason);
            }

            if ($initiatedBy !== '') {
                $this->info('  Initiated by: '.$initiatedBy);
            }

            if (! $this->confirm('Do you want to start compensation?')) {
                $this->info('Compensation cancelled.');

                return self::SUCCESS;
            }
        }

        try {
            $this->compensationExecutor->initiate(
                workflowId: $workflowId,
                stepKeys: $stepKeys,
                initiatedBy: $initiatedBy !== '' ? $initiatedBy : null,
                reason: $reason !== '' ? $reason : null,
                scope: $scope,
            );

            $this->info(sprintf('Compensation started for workflow %s', $workflowIdString));

            return self::SUCCESS;
        } catch (WorkflowNotFoundException) {
            $this->error('Workflow not found: '.$workflowIdString);

            return self::FAILURE;
        } catch (DefinitionNotFoundException $e) {
            $this->error('Workflow definition not found: '.$e->getMessage());

            return self::FAILURE;
        } catch (InvalidStateTransitionException $e) {
            $this->error('Invalid state transition: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    private function displayWorkflowInfo(WorkflowInstance $workflowInstance): void
    {
        $version = $workflowInstance->definitionVersion->toString();

        $this->info('Workflow Details:');
        $this->info('  ID: '.$workflowInstance->id->value);
        $this->info(sprintf('  Definition: %s v%s', $workflowInstance->definitionKey->value, $version));
        $this->info('  State: '.$workflowInstance->state()->value);
        $this->newLine();
    }

    private function getScope(): CompensationScope
    {
        /** @var string|null $scopeOption */
        $scopeOption = $this->option('scope');

        if ($scopeOption !== null && $scopeOption !== 'all') {
            $scope = CompensationScope::tryFrom($scopeOption);

            if ($scope !== null) {
                return $scope;
            }
        }

        if ($this->option('steps') !== null) {
            return CompensationScope::Partial;
        }

        $selected = select(
            label: 'Select compensation scope:',
            options: [
                CompensationScope::All->value => 'All - Compensate all steps with compensation defined',
                CompensationScope::FailedStepOnly->value => 'Failed step only - Compensate only the failed step',
            ],
            default: CompensationScope::All->value,
        );

        return CompensationScope::from($selected);
    }

    /**
     * @return list<StepKey>|null
     *
     * @throws InvalidStepKeyException
     */
    private function getStepKeys(): ?array
    {
        /** @var string|null $stepsOption */
        $stepsOption = $this->option('steps');

        if ($stepsOption === null || $stepsOption === '') {
            return null;
        }

        $stepKeyStrings = array_map(trim(...), explode(',', $stepsOption));
        $stepKeys = [];

        foreach ($stepKeyStrings as $stepKeyString) {
            if ($stepKeyString !== '') {
                $stepKeys[] = StepKey::fromString($stepKeyString);
            }
        }

        return $stepKeys !== [] ? $stepKeys : null;
    }

    private function getOptionOrPrompt(string $option, string $prompt): string
    {
        /** @var string|null $value */
        $value = $this->option($option);

        if ($value !== null) {
            return $value;
        }

        return text(label: $prompt);
    }
}
