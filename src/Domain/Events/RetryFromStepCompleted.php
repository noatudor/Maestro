<?php

declare(strict_types=1);

namespace Maestro\Workflow\Domain\Events;

use Carbon\CarbonImmutable;
use Maestro\Workflow\Enums\RetryMode;
use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\DefinitionVersion;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\StepRunId;
use Maestro\Workflow\ValueObjects\WorkflowId;

final readonly class RetryFromStepCompleted
{
    public function __construct(
        public WorkflowId $workflowId,
        public DefinitionKey $definitionKey,
        public DefinitionVersion $definitionVersion,
        public StepKey $retryFromStepKey,
        public StepRunId $newStepRunId,
        public RetryMode $retryMode,
        public int $supersededStepRunCount,
        public int $clearedOutputCount,
        public bool $compensationExecuted,
        public CarbonImmutable $occurredAt,
    ) {}
}
