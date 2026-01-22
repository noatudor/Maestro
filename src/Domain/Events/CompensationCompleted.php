<?php

declare(strict_types=1);

namespace Maestro\Workflow\Domain\Events;

use Carbon\CarbonImmutable;
use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\DefinitionVersion;
use Maestro\Workflow\ValueObjects\WorkflowId;

final readonly class CompensationCompleted
{
    public function __construct(
        public WorkflowId $workflowId,
        public DefinitionKey $definitionKey,
        public DefinitionVersion $definitionVersion,
        public int $totalStepsCompensated,
        public int $stepsSucceeded,
        public int $stepsSkipped,
        public int $durationMs,
        public CarbonImmutable $occurredAt,
    ) {}
}
