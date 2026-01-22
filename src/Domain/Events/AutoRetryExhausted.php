<?php

declare(strict_types=1);

namespace Maestro\Workflow\Domain\Events;

use Carbon\CarbonImmutable;
use Maestro\Workflow\Enums\FailureResolutionStrategy;
use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\DefinitionVersion;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\WorkflowId;

/**
 * Emitted when automatic retries are exhausted and fallback strategy is applied.
 *
 * This event indicates that the workflow has reached its maximum auto-retry
 * attempts and is now applying the configured fallback strategy.
 */
final readonly class AutoRetryExhausted
{
    public function __construct(
        public WorkflowId $workflowId,
        public DefinitionKey $definitionKey,
        public DefinitionVersion $definitionVersion,
        public StepKey $failedStepKey,
        public int $totalAttempts,
        public FailureResolutionStrategy $fallbackStrategy,
        public CarbonImmutable $occurredAt,
    ) {}
}
