<?php

declare(strict_types=1);

namespace Maestro\Workflow\Console\Commands;

use Illuminate\Console\Command;
use Maestro\Workflow\Contracts\WorkflowManager;
use Maestro\Workflow\Definition\WorkflowDefinitionRegistry;
use Maestro\Workflow\Exceptions\DefinitionNotFoundException;
use Maestro\Workflow\Exceptions\InvalidDefinitionKeyException;
use Maestro\Workflow\Exceptions\StepDependencyException;
use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\WorkflowId;

/**
 * Artisan command to start a new workflow instance.
 */
final class StartWorkflowCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'maestro:start
                            {definition : The workflow definition key to start}
                            {--id= : Optional custom workflow ID (UUID)}
                            {--list : List available workflow definitions}';

    /**
     * @var string
     */
    protected $description = 'Start a new workflow instance';

    public function __construct(
        private readonly WorkflowManager $workflowManager,
        private readonly WorkflowDefinitionRegistry $workflowDefinitionRegistry,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if ((bool) $this->option('list')) {
            return $this->listDefinitions();
        }

        /** @var string $definitionKeyString */
        $definitionKeyString = $this->argument('definition');

        try {
            $definitionKey = DefinitionKey::fromString($definitionKeyString);
        } catch (InvalidDefinitionKeyException $e) {
            $this->error('Invalid definition key: '.$e->getMessage());

            return self::FAILURE;
        }

        $workflowId = null;
        /** @var string|null $customId */
        $customId = $this->option('id');
        if ($customId !== null) {
            $workflowId = WorkflowId::fromString($customId);
        }

        try {
            $workflowDefinition = $this->workflowDefinitionRegistry->getLatest($definitionKey);

            $this->info('Starting workflow...');
            $this->info(sprintf('Definition: %s v%s', $workflowDefinition->key()->value, $workflowDefinition->version()->toString()));
            $this->info('Steps: '.$workflowDefinition->stepCount());

            $workflow = $this->workflowManager->startWorkflow($definitionKey, $workflowId);

            $this->newLine();
            $this->info('Workflow started successfully!');
            $this->info('Workflow ID: '.$workflow->id->value);
            $this->info('State: '.$workflow->state()->value);

            $currentStep = $workflow->currentStepKey();
            if ($currentStep instanceof StepKey) {
                $this->info('Current step: '.$currentStep->value);
            }

            return self::SUCCESS;
        } catch (DefinitionNotFoundException) {
            $this->error('Definition not found: '.$definitionKeyString);
            $this->newLine();
            $this->listAvailableDefinitions();

            return self::FAILURE;
        } catch (StepDependencyException $e) {
            $this->error('Step dependency error: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    private function listDefinitions(): int
    {
        $this->info('Available workflow definitions:');
        $this->newLine();
        $this->listAvailableDefinitions();

        return self::SUCCESS;
    }

    private function listAvailableDefinitions(): void
    {
        try {
            $definitions = $this->workflowDefinitionRegistry->allLatest();
        } catch (DefinitionNotFoundException|InvalidDefinitionKeyException) {
            $this->warn('No workflow definitions registered.');

            return;
        }

        if ($definitions === []) {
            $this->warn('No workflow definitions registered.');

            return;
        }

        $rows = [];
        foreach ($definitions as $definition) {
            $rows[] = [
                $definition->key()->value,
                $definition->version()->toString(),
                $definition->displayName(),
                (string) $definition->stepCount(),
            ];
        }

        $this->table(
            ['Key', 'Version', 'Display Name', 'Steps'],
            $rows,
        );
    }
}
