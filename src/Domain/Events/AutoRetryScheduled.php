<?php

declare(strict_types=1);

namespace Maestro\Workflow\Domain\Events;

use Carbon\CarbonImmutable;
use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\DefinitionVersion;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\WorkflowId;

/**
 * Emitted when an automatic retry is scheduled for a failed workflow.
 *
 * This event is emitted when the AutoRetry resolution strategy schedules
 * a retry after the configured delay.
 */
final readonly class AutoRetryScheduled
{
    public function __construct(
        public WorkflowId $workflowId,
        public DefinitionKey $definitionKey,
        public DefinitionVersion $definitionVersion,
        public StepKey $failedStepKey,
        public int $retryNumber,
        public int $maxRetries,
        public int $delaySeconds,
        public CarbonImmutable $scheduledFor,
        public CarbonImmutable $occurredAt,
    ) {}
}
