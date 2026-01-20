<?php

declare(strict_types=1);

namespace Maestro\Workflow\Commands;

use Illuminate\Console\Command;

final class WorkflowStatusCommand extends Command
{
    protected $signature = 'maestro:status {workflow? : The workflow ID to check}';

    protected $description = 'Check the status of workflows';

    public function handle(): int
    {
        $workflowId = $this->argument('workflow');

        if ($workflowId !== null) {
            $this->showWorkflowStatus((string) $workflowId);

            return self::SUCCESS;
        }

        $this->showOverview();

        return self::SUCCESS;
    }

    private function showWorkflowStatus(string $workflowId): void
    {
        $this->info("Workflow status for: {$workflowId}");
        $this->warn('Status check not yet implemented.');
    }

    private function showOverview(): void
    {
        $this->info('Maestro Workflow Overview');
        $this->warn('Overview not yet implemented.');
    }
}
