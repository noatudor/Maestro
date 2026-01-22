<?php

declare(strict_types=1);

namespace Maestro\Workflow\Domain\Events;

use Carbon\CarbonImmutable;
use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\DefinitionVersion;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\WorkflowId;

/**
 * Emitted when a workflow enters FAILED state and is awaiting manual resolution.
 *
 * This event allows external systems to be notified when human intervention
 * is required to resolve a failed workflow.
 */
final readonly class WorkflowAwaitingResolution
{
    public function __construct(
        public WorkflowId $workflowId,
        public DefinitionKey $definitionKey,
        public DefinitionVersion $definitionVersion,
        public StepKey $failedStepKey,
        public ?string $failureCode,
        public ?string $failureMessage,
        public CarbonImmutable $occurredAt,
    ) {}
}
