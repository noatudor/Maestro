<?php

declare(strict_types=1);

namespace Maestro\Workflow\Domain\Events;

use Carbon\CarbonImmutable;
use Maestro\Workflow\ValueObjects\CompensationRunId;
use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\DefinitionVersion;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\WorkflowId;

final readonly class CompensationStepSucceeded
{
    public function __construct(
        public WorkflowId $workflowId,
        public DefinitionKey $definitionKey,
        public DefinitionVersion $definitionVersion,
        public StepKey $stepKey,
        public CompensationRunId $compensationRunId,
        public int $attempt,
        public int $durationMs,
        public CarbonImmutable $occurredAt,
    ) {}
}
