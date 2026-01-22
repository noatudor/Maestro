<?php

declare(strict_types=1);

namespace Maestro\Workflow\Domain;

use Carbon\CarbonImmutable;
use Maestro\Workflow\ValueObjects\TriggerPayload;
use Maestro\Workflow\ValueObjects\TriggerPayloadId;
use Maestro\Workflow\ValueObjects\WorkflowId;

/**
 * Domain entity for a received trigger payload.
 *
 * Records the payload data sent when resuming a paused workflow.
 */
final readonly class TriggerPayloadRecord
{
    private function __construct(
        public TriggerPayloadId $id,
        public WorkflowId $workflowId,
        public string $triggerKey,
        public TriggerPayload $payload,
        public CarbonImmutable $receivedAt,
        public ?string $sourceIp,
        public ?string $sourceIdentifier,
        public CarbonImmutable $createdAt,
    ) {}

    public static function create(
        WorkflowId $workflowId,
        string $triggerKey,
        TriggerPayload $triggerPayload,
        ?string $sourceIp = null,
        ?string $sourceIdentifier = null,
        ?TriggerPayloadId $triggerPayloadId = null,
    ): self {
        $now = CarbonImmutable::now();

        return new self(
            id: $triggerPayloadId ?? TriggerPayloadId::generate(),
            workflowId: $workflowId,
            triggerKey: $triggerKey,
            payload: $triggerPayload,
            receivedAt: $now,
            sourceIp: $sourceIp,
            sourceIdentifier: $sourceIdentifier,
            createdAt: $now,
        );
    }

    public static function reconstitute(
        TriggerPayloadId $triggerPayloadId,
        WorkflowId $workflowId,
        string $triggerKey,
        TriggerPayload $triggerPayload,
        CarbonImmutable $receivedAt,
        ?string $sourceIp,
        ?string $sourceIdentifier,
        CarbonImmutable $createdAt,
    ): self {
        return new self(
            id: $triggerPayloadId,
            workflowId: $workflowId,
            triggerKey: $triggerKey,
            payload: $triggerPayload,
            receivedAt: $receivedAt,
            sourceIp: $sourceIp,
            sourceIdentifier: $sourceIdentifier,
            createdAt: $createdAt,
        );
    }
}
