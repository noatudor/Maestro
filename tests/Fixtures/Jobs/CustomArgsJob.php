<?php

declare(strict_types=1);

namespace Maestro\Workflow\Tests\Fixtures\Jobs;

use Maestro\Workflow\Application\Job\OrchestratedJob;
use Maestro\Workflow\ValueObjects\StepRunId;
use Maestro\Workflow\ValueObjects\WorkflowId;

/**
 * Test job for fan-out scenarios with custom job arguments factory.
 */
final class CustomArgsJob extends OrchestratedJob
{
    public function __construct(
        WorkflowId $workflowId,
        StepRunId $stepRunId,
        string $jobUuid,
        public readonly mixed $custom,
    ) {
        parent::__construct($workflowId, $stepRunId, $jobUuid);
    }

    protected function execute(): void
    {
        // Test implementation - does nothing
    }
}
