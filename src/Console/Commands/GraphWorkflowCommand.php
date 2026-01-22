<?php

declare(strict_types=1);

namespace Maestro\Workflow\Console\Commands;

use Illuminate\Console\Command;
use Maestro\Workflow\Contracts\FanOutStep;
use Maestro\Workflow\Contracts\SingleJobStep;
use Maestro\Workflow\Contracts\StepDefinition;
use Maestro\Workflow\Definition\WorkflowDefinition;
use Maestro\Workflow\Definition\WorkflowDefinitionRegistry;
use Maestro\Workflow\Exceptions\DefinitionNotFoundException;
use Maestro\Workflow\Exceptions\InvalidDefinitionKeyException;
use Maestro\Workflow\ValueObjects\DefinitionKey;

/**
 * Artisan command to output step dependency graph.
 */
final class GraphWorkflowCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'maestro:graph
                            {definition : The workflow definition key to graph}
                            {--format=text : Output format (text, mermaid, dot)}';

    /**
     * @var string
     */
    protected $description = 'Output step dependency graph for a workflow definition';

    public function __construct(
        private readonly WorkflowDefinitionRegistry $workflowDefinitionRegistry,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        /** @var string $definitionKeyString */
        $definitionKeyString = $this->argument('definition');

        try {
            $definitionKey = DefinitionKey::fromString($definitionKeyString);
        } catch (InvalidDefinitionKeyException $e) {
            $this->error('Invalid definition key: '.$e->getMessage());

            return self::FAILURE;
        }

        try {
            $definition = $this->workflowDefinitionRegistry->getLatest($definitionKey);
        } catch (DefinitionNotFoundException) {
            $this->error('Definition not found: '.$definitionKeyString);
            $this->listAvailableDefinitions();

            return self::FAILURE;
        }

        /** @var string $format */
        $format = $this->option('format');

        return match ($format) {
            'text' => $this->outputTextGraph($definition),
            'mermaid' => $this->outputMermaidGraph($definition),
            'dot' => $this->outputDotGraph($definition),
            default => $this->invalidFormat($format),
        };
    }

    private function outputTextGraph(WorkflowDefinition $workflowDefinition): int
    {
        $this->info(sprintf('Workflow: %s v%s', $workflowDefinition->key()->value, $workflowDefinition->version()->toString()));
        $this->info('Display Name: '.$workflowDefinition->displayName());
        $this->newLine();

        $steps = $workflowDefinition->steps();

        if ($steps->isEmpty()) {
            $this->warn('No steps defined.');

            return self::SUCCESS;
        }

        $this->info('Step Dependency Graph:');
        $this->newLine();

        $stepIndex = 0;
        foreach ($steps as $step) {
            $stepIndex++;
            $this->renderTextStep($step, $stepIndex, $steps->count());
        }

        $this->newLine();
        $this->displayLegend();

        return self::SUCCESS;
    }

    private function renderTextStep(StepDefinition $stepDefinition, int $index, int $total): void
    {
        $typeIndicator = $this->getStepTypeIndicator($stepDefinition);
        $requiresStr = $this->formatRequires($stepDefinition);
        $producesStr = $this->formatProduces($stepDefinition);

        $connector = $index < $total ? '│' : ' ';
        $arrow = $index < $total ? '↓' : ' ';

        $this->line('  ┌─────────────────────────────────────────────┐');
        $this->line(sprintf('  │ %s [%s] %s', $typeIndicator, $stepDefinition->key()->value, str_pad('', 40 - strlen($stepDefinition->key()->value) - strlen($typeIndicator) - 4, ' ').'│'));
        $this->line(sprintf('  │   %s│', str_pad($stepDefinition->displayName(), 42, ' ')));

        if ($requiresStr !== '') {
            $this->line(sprintf('  │   Requires: %s│', str_pad($requiresStr, 32, ' ')));
        }

        if ($producesStr !== '') {
            $this->line(sprintf('  │   Produces: %s│', str_pad($producesStr, 32, ' ')));
        }

        $this->line(sprintf('  │   Policy: %s│', str_pad($stepDefinition->failurePolicy()->value, 34, ' ')));
        $this->line('  └─────────────────────────────────────────────┘');

        if ($index < $total) {
            $this->line(sprintf('  %s', $connector));
            $this->line(sprintf('  %s', $arrow));
        }
    }

    private function outputMermaidGraph(WorkflowDefinition $workflowDefinition): int
    {
        $this->line('```mermaid');
        $this->line('flowchart TD');
        $this->line(sprintf(
            '    subgraph %s["%s v%s"]',
            $this->sanitizeId($workflowDefinition->key()->value),
            $workflowDefinition->displayName(),
            $workflowDefinition->version()->toString(),
        ));

        $steps = $workflowDefinition->steps();
        $previousKey = null;

        foreach ($steps as $step) {
            $id = $this->sanitizeId($step->key()->value);
            $label = $step->displayName();
            $shape = $step instanceof FanOutStep ? '[[' : '[';
            $shapeEnd = $step instanceof FanOutStep ? ']]' : ']';

            $this->line(sprintf('        %s%s"%s"%s', $id, $shape, $label, $shapeEnd));

            if ($previousKey !== null) {
                $this->line(sprintf(
                    '        %s --> %s',
                    $this->sanitizeId($previousKey),
                    $id,
                ));
            }

            $previousKey = $step->key()->value;
        }

        $this->line('    end');
        $this->line('```');

        return self::SUCCESS;
    }

    private function outputDotGraph(WorkflowDefinition $workflowDefinition): int
    {
        $this->line('digraph workflow {');
        $this->line('    rankdir=TB;');
        $this->line('    node [shape=box, style=rounded];');
        $this->line(sprintf('    label="%s v%s";', $workflowDefinition->displayName(), $workflowDefinition->version()->toString()));
        $this->line('    labelloc=t;');
        $this->newLine();

        $steps = $workflowDefinition->steps();
        $previousKey = null;

        foreach ($steps as $step) {
            $id = $this->sanitizeId($step->key()->value);
            $label = sprintf('%s\\n%s', $step->key()->value, $step->displayName());
            $shape = $step instanceof FanOutStep ? 'parallelogram' : 'box';

            $this->line(sprintf('    %s [label="%s", shape=%s];', $id, $label, $shape));

            if ($previousKey !== null) {
                $this->line(sprintf(
                    '    %s -> %s;',
                    $this->sanitizeId($previousKey),
                    $id,
                ));
            }

            $previousKey = $step->key()->value;
        }

        $this->newLine();
        $this->line('}');

        return self::SUCCESS;
    }

    private function invalidFormat(string $format): int
    {
        $this->error('Invalid format: '.$format);
        $this->line('Valid formats: text, mermaid, dot');

        return self::FAILURE;
    }

    private function getStepTypeIndicator(StepDefinition $stepDefinition): string
    {
        if ($stepDefinition instanceof FanOutStep) {
            return '<fg=cyan>FAN-OUT</>';
        }

        if ($stepDefinition instanceof SingleJobStep) {
            return '<fg=green>JOB</>';
        }

        return '<fg=gray>STEP</>';
    }

    private function formatRequires(StepDefinition $stepDefinition): string
    {
        $requires = $stepDefinition->requires();

        if ($requires === []) {
            return '';
        }

        $shortNames = array_map($this->shortenClassName(...), $requires);

        $result = implode(', ', $shortNames);

        if (strlen($result) > 30) {
            return count($requires).' outputs';
        }

        return $result;
    }

    private function formatProduces(StepDefinition $stepDefinition): string
    {
        $produces = $stepDefinition->produces();

        if ($produces === null) {
            return '';
        }

        return $this->shortenClassName($produces);
    }

    private function shortenClassName(string $className): string
    {
        $parts = explode('\\', $className);

        return end($parts);
    }

    private function sanitizeId(string $id): string
    {
        return str_replace(['-', '.'], '_', $id);
    }

    private function displayLegend(): void
    {
        $this->info('Legend:');
        $this->line('  <fg=green>JOB</> = Single job step');
        $this->line('  <fg=cyan>FAN-OUT</> = Parallel job step');
    }

    private function listAvailableDefinitions(): void
    {
        $this->newLine();

        try {
            $definitions = $this->workflowDefinitionRegistry->allLatest();
        } catch (DefinitionNotFoundException|InvalidDefinitionKeyException) {
            return;
        }

        if ($definitions === []) {
            return;
        }

        $this->info('Available definitions:');
        foreach ($definitions as $definition) {
            $this->line('  - '.$definition->key()->value);
        }
    }
}
