<?php

declare(strict_types=1);

namespace Maestro\Workflow\Application\Job;

use Maestro\Workflow\Domain\JobRecord;
use Maestro\Workflow\ValueObjects\WorkflowId;

/**
 * Result of zombie job detection and handling.
 */
final readonly class ZombieJobDetectionResult
{
    /**
     * @param list<JobRecord> $detectedJobs
     * @param list<WorkflowId> $affectedWorkflowIds
     */
    public function __construct(
        public array $detectedJobs,
        public array $affectedWorkflowIds,
        public int $markedFailedCount,
    ) {}

    public static function empty(): self
    {
        return new self([], [], 0);
    }

    public function hasZombies(): bool
    {
        return $this->markedFailedCount > 0;
    }
}
