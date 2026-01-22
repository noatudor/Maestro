<?php

declare(strict_types=1);

namespace Maestro\Workflow\Domain\Events;

use Carbon\CarbonImmutable;
use Maestro\Workflow\Enums\RetryMode;
use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\DefinitionVersion;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\WorkflowId;

final readonly class RetryFromStepInitiated
{
    /**
     * @param list<string> $affectedStepKeys
     */
    public function __construct(
        public WorkflowId $workflowId,
        public DefinitionKey $definitionKey,
        public DefinitionVersion $definitionVersion,
        public StepKey $retryFromStepKey,
        public RetryMode $retryMode,
        public array $affectedStepKeys,
        public ?string $initiatedBy,
        public ?string $reason,
        public CarbonImmutable $occurredAt,
    ) {}
}
