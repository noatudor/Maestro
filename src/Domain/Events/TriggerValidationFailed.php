<?php

declare(strict_types=1);

namespace Maestro\Workflow\Domain\Events;

use Carbon\CarbonImmutable;
use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\DefinitionVersion;
use Maestro\Workflow\ValueObjects\TriggerPayload;
use Maestro\Workflow\ValueObjects\WorkflowId;

final readonly class TriggerValidationFailed
{
    public function __construct(
        public WorkflowId $workflowId,
        public DefinitionKey $definitionKey,
        public DefinitionVersion $definitionVersion,
        public string $triggerKey,
        public TriggerPayload $payload,
        public string $failureReason,
        public ?string $sourceIp,
        public ?string $sourceIdentifier,
        public CarbonImmutable $occurredAt,
    ) {}
}
