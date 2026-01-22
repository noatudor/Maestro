<?php

declare(strict_types=1);

namespace Maestro\Workflow\Console\Commands;

use Illuminate\Console\Command;
use Maestro\Workflow\Application\Orchestration\CompensationExecutor;
use Maestro\Workflow\Contracts\WorkflowRepository;
use Maestro\Workflow\Exceptions\InvalidStateTransitionException;
use Maestro\Workflow\Exceptions\WorkflowNotFoundException;
use Maestro\Workflow\ValueObjects\WorkflowId;

/**
 * Artisan command to retry failed compensation for a workflow.
 */
final class RetryCompensationCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'maestro:retry-compensation
                            {workflow : The workflow ID}
                            {--force : Apply without confirmation}';

    /**
     * @var string
     */
    protected $description = 'Retry failed compensation steps for a workflow';

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

        if (! $workflow->isCompensationFailed()) {
            $this->error('Workflow compensation has not failed. Current state: '.$workflow->state()->value);

            return self::FAILURE;
        }

        $force = (bool) $this->option('force');

        if (! $force) {
            $this->info('Workflow Details:');
            $this->info('  ID: '.$workflow->id->value);
            $this->info('  Definition: '.$workflow->definitionKey->value);
            $this->info('  State: '.$workflow->state()->value);

            if (! $this->confirm('Do you want to retry compensation?')) {
                $this->info('Operation cancelled.');

                return self::SUCCESS;
            }
        }

        try {
            $this->compensationExecutor->retryCompensation($workflowId);
            $this->info(sprintf('Compensation retry initiated for workflow %s', $workflowIdString));

            return self::SUCCESS;
        } catch (WorkflowNotFoundException) {
            $this->error('Workflow not found: '.$workflowIdString);

            return self::FAILURE;
        } catch (InvalidStateTransitionException $e) {
            $this->error('Invalid state transition: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
