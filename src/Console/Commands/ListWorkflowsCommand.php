<?php

declare(strict_types=1);

namespace Maestro\Workflow\Console\Commands;

use Illuminate\Console\Command;
use Maestro\Workflow\Application\Query\WorkflowQueryService;
use Maestro\Workflow\Enums\WorkflowState;
use Maestro\Workflow\Exceptions\InvalidDefinitionKeyException;
use Maestro\Workflow\Http\Responses\WorkflowListDTO;
use Maestro\Workflow\ValueObjects\DefinitionKey;

/**
 * Artisan command to list workflows with filtering.
 */
final class ListWorkflowsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'maestro:list
                            {--state= : Filter by workflow state (pending, running, paused, succeeded, failed, cancelled, compensating, compensated, compensation_failed)}
                            {--definition= : Filter by definition key}
                            {--limit=50 : Maximum number of workflows to display}
                            {--json : Output as JSON}';

    /**
     * @var string
     */
    protected $description = 'List workflows with optional filtering';

    public function __construct(
        private readonly WorkflowQueryService $workflowQueryService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        /** @var string|null $stateOption */
        $stateOption = $this->option('state');
        /** @var string|null $definitionOption */
        $definitionOption = $this->option('definition');
        /** @var string $limitOption */
        $limitOption = $this->option('limit');
        $limit = (int) $limitOption;

        $workflowList = $this->fetchWorkflows($stateOption, $definitionOption);

        if (! $workflowList instanceof WorkflowListDTO) {
            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($workflowList->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->displayWorkflows($workflowList, $limit, $stateOption, $definitionOption);

        return self::SUCCESS;
    }

    private function fetchWorkflows(?string $state, ?string $definition): ?WorkflowListDTO
    {
        if ($state !== null) {
            $workflowState = WorkflowState::tryFrom($state);
            if ($workflowState === null) {
                $this->error('Invalid state: '.$state);
                $this->line('Valid states: pending, running, paused, succeeded, failed, cancelled, compensating, compensated, compensation_failed');

                return null;
            }

            return $this->workflowQueryService->getWorkflowsByState($workflowState);
        }

        if ($definition !== null) {
            try {
                $definitionKey = DefinitionKey::fromString($definition);
            } catch (InvalidDefinitionKeyException $e) {
                $this->error('Invalid definition key: '.$e->getMessage());

                return null;
            }

            return $this->workflowQueryService->getWorkflowsByDefinition($definitionKey);
        }

        return $this->workflowQueryService->getRunningWorkflows();
    }

    private function displayWorkflows(
        WorkflowListDTO $workflowListDTO,
        int $limit,
        ?string $state,
        ?string $definition,
    ): void {
        $filterDescription = $this->buildFilterDescription($state, $definition);
        $this->info('Workflows'.$filterDescription);
        $this->newLine();

        if ($workflowListDTO->total === 0) {
            $this->warn('No workflows found.');

            return;
        }

        $workflows = array_slice($workflowListDTO->workflows, 0, $limit);
        $rows = [];

        foreach ($workflows as $workflow) {
            $rows[] = [
                substr($workflow->id, 0, 8).'...',
                $workflow->definitionKey,
                $this->formatState($workflow->state),
                $workflow->currentStepKey ?? '-',
                $workflow->createdAt,
                $workflow->updatedAt,
            ];
        }

        $this->table(
            ['ID', 'Definition', 'State', 'Current Step', 'Created', 'Updated'],
            $rows,
        );

        $this->newLine();
        $this->line(sprintf('Showing %d of %d workflows', count($workflows), $workflowListDTO->total));

        if (count($workflows) < $workflowListDTO->total) {
            $this->line(sprintf('Use --limit=%d to see all', $workflowListDTO->total));
        }
    }

    private function buildFilterDescription(?string $state, ?string $definition): string
    {
        $parts = [];

        if ($state !== null) {
            $parts[] = 'state='.$state;
        }

        if ($definition !== null) {
            $parts[] = 'definition='.$definition;
        }

        if ($parts === []) {
            return ' (running)';
        }

        return ' ('.implode(', ', $parts).')';
    }

    private function formatState(WorkflowState $workflowState): string
    {
        return match ($workflowState) {
            WorkflowState::Pending => '<fg=gray>PENDING</>',
            WorkflowState::Running => '<fg=blue>RUNNING</>',
            WorkflowState::Paused => '<fg=yellow>PAUSED</>',
            WorkflowState::Succeeded => '<fg=green>SUCCEEDED</>',
            WorkflowState::Failed => '<fg=red>FAILED</>',
            WorkflowState::Cancelled => '<fg=gray>CANCELLED</>',
            WorkflowState::Compensating => '<fg=magenta>COMPENSATING</>',
            WorkflowState::Compensated => '<fg=cyan>COMPENSATED</>',
            WorkflowState::CompensationFailed => '<fg=red>COMP_FAILED</>',
        };
    }
}
