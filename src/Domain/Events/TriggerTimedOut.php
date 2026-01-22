<?php

declare(strict_types=1);

namespace Maestro\Workflow\Domain\Events;

use Carbon\CarbonImmutable;
use Maestro\Workflow\Enums\TriggerTimeoutPolicy;
use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\DefinitionVersion;
use Maestro\Workflow\ValueObjects\WorkflowId;

final readonly class TriggerTimedOut
{
    public function __construct(
        public WorkflowId $workflowId,
        public DefinitionKey $definitionKey,
        public DefinitionVersion $definitionVersion,
        public string $triggerKey,
        public TriggerTimeoutPolicy $appliedPolicy,
        public CarbonImmutable $timeoutAt,
        public CarbonImmutable $occurredAt,
    ) {}
}
