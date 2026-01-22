<?php

declare(strict_types=1);

namespace Maestro\Workflow\Console\Commands;

use Illuminate\Console\Command;
use Maestro\Workflow\Definition\Validation\WorkflowDefinitionValidator;
use Maestro\Workflow\Definition\WorkflowDefinition;
use Maestro\Workflow\Definition\WorkflowDefinitionRegistry;
use Maestro\Workflow\Exceptions\DefinitionNotFoundException;
use Maestro\Workflow\Exceptions\InvalidDefinitionKeyException;
use Maestro\Workflow\ValueObjects\DefinitionKey;

/**
 * Artisan command to validate all registered workflow definitions.
 */
final class ValidateDefinitionsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'maestro:validate
                            {definition? : Optional specific definition key to validate}
                            {--all-versions : Validate all versions, not just latest}
                            {--skip-class-check : Skip checking if job/output classes exist}';

    /**
     * @var string
     */
    protected $description = 'Validate all registered workflow definitions';

    public function __construct(
        private readonly WorkflowDefinitionRegistry $workflowDefinitionRegistry,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $skipClassCheck = (bool) $this->option('skip-class-check');
        $validator = new WorkflowDefinitionValidator(! $skipClassCheck);

        /** @var string|null $definitionKeyString */
        $definitionKeyString = $this->argument('definition');

        if ($definitionKeyString !== null) {
            return $this->validateSingleDefinition($definitionKeyString, $validator);
        }

        return $this->validateAllDefinitions($validator);
    }

    private function validateSingleDefinition(string $definitionKeyString, WorkflowDefinitionValidator $workflowDefinitionValidator): int
    {
        try {
            $definitionKey = DefinitionKey::fromString($definitionKeyString);
        } catch (InvalidDefinitionKeyException $e) {
            $this->error('Invalid definition key: '.$e->getMessage());

            return self::FAILURE;
        }

        $allVersions = (bool) $this->option('all-versions');

        if ($allVersions) {
            $definitions = $this->workflowDefinitionRegistry->getAllVersions($definitionKey);

            if ($definitions === []) {
                $this->error('No definitions found for: '.$definitionKeyString);

                return self::FAILURE;
            }

            $this->info(sprintf('Validating %d versions of "%s"...', count($definitions), $definitionKeyString));
        } else {
            try {
                $definition = $this->workflowDefinitionRegistry->getLatest($definitionKey);
            } catch (DefinitionNotFoundException) {
                $this->error('Definition not found: '.$definitionKeyString);

                return self::FAILURE;
            }

            $definitions = [$definition];
            $this->info(sprintf('Validating "%s" v%s...', $definitionKeyString, $definition->version()->toString()));
        }

        $hasErrors = false;
        foreach ($definitions as $definition) {
            if (! $this->validateDefinition($definition, $workflowDefinitionValidator)) {
                $hasErrors = true;
            }
        }

        return $hasErrors ? self::FAILURE : self::SUCCESS;
    }

    private function validateAllDefinitions(WorkflowDefinitionValidator $workflowDefinitionValidator): int
    {
        $allVersions = (bool) $this->option('all-versions');

        try {
            $definitions = $allVersions
                ? $this->workflowDefinitionRegistry->all()
                : $this->workflowDefinitionRegistry->allLatest();
        } catch (DefinitionNotFoundException|InvalidDefinitionKeyException) {
            $this->warn('No workflow definitions registered.');

            return self::SUCCESS;
        }

        if ($definitions === []) {
            $this->warn('No workflow definitions registered.');

            return self::SUCCESS;
        }

        $versionText = $allVersions ? 'all versions' : 'latest versions';
        $this->info(sprintf('Validating %d workflow definitions (%s)...', count($definitions), $versionText));
        $this->newLine();

        $validCount = 0;
        $invalidCount = 0;
        $totalErrors = 0;

        foreach ($definitions as $definition) {
            $result = $workflowDefinitionValidator->validate($definition);

            if ($result->isValid()) {
                $validCount++;
                $this->line(sprintf(
                    '  <fg=green>OK</> %s v%s',
                    $definition->key()->value,
                    $definition->version()->toString(),
                ));
            } else {
                $invalidCount++;
                $totalErrors += $result->errorCount();
                $this->line(sprintf(
                    '  <fg=red>FAIL</> %s v%s (%d errors)',
                    $definition->key()->value,
                    $definition->version()->toString(),
                    $result->errorCount(),
                ));

                foreach ($result->errors() as $error) {
                    $this->line(sprintf('       - [%s] %s', $error->code, $error->message));
                }
            }
        }

        $this->newLine();
        $this->displaySummary($validCount, $invalidCount, $totalErrors);

        return $invalidCount > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function validateDefinition(WorkflowDefinition $workflowDefinition, WorkflowDefinitionValidator $workflowDefinitionValidator): bool
    {
        $validationResult = $workflowDefinitionValidator->validate($workflowDefinition);

        if ($validationResult->isValid()) {
            $this->line(sprintf(
                '  <fg=green>OK</> %s v%s (%d steps)',
                $workflowDefinition->key()->value,
                $workflowDefinition->version()->toString(),
                $workflowDefinition->stepCount(),
            ));

            return true;
        }

        $this->line(sprintf(
            '  <fg=red>FAIL</> %s v%s',
            $workflowDefinition->key()->value,
            $workflowDefinition->version()->toString(),
        ));

        foreach ($validationResult->errors() as $error) {
            $this->line(sprintf('       - [%s] %s', $error->code, $error->message));
        }

        return false;
    }

    private function displaySummary(int $valid, int $invalid, int $totalErrors): void
    {
        if ($invalid === 0) {
            $this->info(sprintf('All %d definitions are valid.', $valid));

            return;
        }

        $this->error(sprintf(
            '%d valid, %d invalid (%d total errors)',
            $valid,
            $invalid,
            $totalErrors,
        ));
    }
}
