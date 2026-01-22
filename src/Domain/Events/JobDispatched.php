<?php

declare(strict_types=1);

namespace Maestro\Workflow\Domain\Events;

use Carbon\CarbonImmutable;
use Maestro\Workflow\ValueObjects\JobId;
use Maestro\Workflow\ValueObjects\StepRunId;
use Maestro\Workflow\ValueObjects\WorkflowId;

final readonly class JobDispatched
{
    public function __construct(
        public WorkflowId $workflowId,
        public StepRunId $stepRunId,
        public JobId $jobId,
        public string $jobUuid,
        public string $jobClass,
        public string $queue,
        public CarbonImmutable $occurredAt,
    ) {}
}
