<?php

declare(strict_types=1);

namespace Maestro\Workflow\Domain\Events;

use Carbon\CarbonImmutable;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\StepRunId;
use Maestro\Workflow\ValueObjects\WorkflowId;

/**
 * Event dispatched when a poll attempt completes (regardless of result).
 */
final readonly class PollAttempted
{
    public function __construct(
        public WorkflowId $workflowId,
        public StepRunId $stepRunId,
        public StepKey $stepKey,
        public int $attemptNumber,
        public bool $resultComplete,
        public bool $resultContinue,
        public CarbonImmutable $occurredAt,
    ) {}
}
