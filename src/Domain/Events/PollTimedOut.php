<?php

declare(strict_types=1);

namespace Maestro\Workflow\Domain\Events;

use Carbon\CarbonImmutable;
use Maestro\Workflow\Enums\PollTimeoutPolicy;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\StepRunId;
use Maestro\Workflow\ValueObjects\WorkflowId;

/**
 * Event dispatched when a polling step times out.
 */
final readonly class PollTimedOut
{
    public function __construct(
        public WorkflowId $workflowId,
        public StepRunId $stepRunId,
        public StepKey $stepKey,
        public int $totalAttempts,
        public PollTimeoutPolicy $policy,
        public CarbonImmutable $occurredAt,
    ) {}
}
