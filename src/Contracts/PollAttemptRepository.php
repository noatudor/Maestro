<?php

declare(strict_types=1);

namespace Maestro\Workflow\Contracts;

use Maestro\Workflow\Domain\PollAttempt;
use Maestro\Workflow\ValueObjects\PollAttemptId;
use Maestro\Workflow\ValueObjects\StepRunId;

interface PollAttemptRepository
{
    public function find(PollAttemptId $pollAttemptId): ?PollAttempt;

    public function save(PollAttempt $pollAttempt): void;

    /**
     * Find all poll attempts for a step run, ordered by attempt number.
     *
     * @return list<PollAttempt>
     */
    public function findByStepRun(StepRunId $stepRunId): array;

    /**
     * Find the latest poll attempt for a step run.
     */
    public function findLatestByStepRun(StepRunId $stepRunId): ?PollAttempt;

    /**
     * Count poll attempts for a step run.
     */
    public function countByStepRun(StepRunId $stepRunId): int;

    /**
     * Delete all poll attempts for a step run.
     */
    public function deleteByStepRun(StepRunId $stepRunId): int;
}
