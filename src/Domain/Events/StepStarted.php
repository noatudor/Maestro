<?php

declare(strict_types=1);

namespace Maestro\Workflow\Domain\Events;

use Carbon\CarbonImmutable;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\StepRunId;
use Maestro\Workflow\ValueObjects\WorkflowId;

final readonly class StepStarted
{
    public function __construct(
        public WorkflowId $workflowId,
        public StepRunId $stepRunId,
        public StepKey $stepKey,
        public int $attempt,
        public CarbonImmutable $occurredAt,
    ) {}
}
