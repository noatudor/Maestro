<?php

declare(strict_types=1);

namespace Maestro\Workflow\Domain\Events;

use Carbon\CarbonImmutable;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\StepRunId;
use Maestro\Workflow\ValueObjects\WorkflowId;

final readonly class StepFailed
{
    public function __construct(
        public WorkflowId $workflowId,
        public StepRunId $stepRunId,
        public StepKey $stepKey,
        public int $attempt,
        public int $failedJobCount,
        public int $totalJobCount,
        public ?string $failureCode,
        public ?string $failureMessage,
        public ?int $durationMs,
        public CarbonImmutable $occurredAt,
    ) {}
}
