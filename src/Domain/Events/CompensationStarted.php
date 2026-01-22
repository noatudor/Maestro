<?php

declare(strict_types=1);

namespace Maestro\Workflow\Domain\Events;

use Carbon\CarbonImmutable;
use Maestro\Workflow\Enums\CompensationScope;
use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\DefinitionVersion;
use Maestro\Workflow\ValueObjects\WorkflowId;

final readonly class CompensationStarted
{
    /**
     * @param list<string> $stepKeysToCompensate
     */
    public function __construct(
        public WorkflowId $workflowId,
        public DefinitionKey $definitionKey,
        public DefinitionVersion $definitionVersion,
        public CompensationScope $scope,
        public array $stepKeysToCompensate,
        public int $totalSteps,
        public ?string $initiatedBy,
        public ?string $reason,
        public CarbonImmutable $occurredAt,
    ) {}
}
