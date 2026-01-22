<?php

declare(strict_types=1);

namespace Maestro\Workflow\Domain\Events;

use Carbon\CarbonImmutable;
use Maestro\Workflow\Enums\SkipReason;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\StepRunId;
use Maestro\Workflow\ValueObjects\WorkflowId;

/**
 * Dispatched when a step is skipped during workflow execution.
 */
final readonly class StepSkipped
{
    public function __construct(
        public WorkflowId $workflowId,
        public StepRunId $stepRunId,
        public StepKey $stepKey,
        public SkipReason $reason,
        public ?string $message,
        public CarbonImmutable $occurredAt,
    ) {}
}
