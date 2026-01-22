<?php

declare(strict_types=1);

namespace Tests\Fakes;

use Maestro\Workflow\Contracts\PollAttemptRepository;
use Maestro\Workflow\Domain\PollAttempt;
use Maestro\Workflow\ValueObjects\PollAttemptId;
use Maestro\Workflow\ValueObjects\StepRunId;

final class InMemoryPollAttemptRepository implements PollAttemptRepository
{
    /** @var array<string, PollAttempt> */
    private array $attempts = [];

    public function find(PollAttemptId $pollAttemptId): ?PollAttempt
    {
        return $this->attempts[$pollAttemptId->value] ?? null;
    }

    public function save(PollAttempt $pollAttempt): void
    {
        $this->attempts[$pollAttempt->id->value] = $pollAttempt;
    }

    /**
     * @return list<PollAttempt>
     */
    public function findByStepRun(StepRunId $stepRunId): array
    {
        $results = [];
        foreach ($this->attempts as $attempt) {
            if ($attempt->stepRunId->equals($stepRunId)) {
                $results[] = $attempt;
            }
        }

        usort($results, static fn (PollAttempt $a, PollAttempt $b): int => $a->attemptNumber <=> $b->attemptNumber);

        return $results;
    }

    public function findLatestByStepRun(StepRunId $stepRunId): ?PollAttempt
    {
        $attempts = $this->findByStepRun($stepRunId);

        if ($attempts === []) {
            return null;
        }

        return $attempts[count($attempts) - 1];
    }

    public function countByStepRun(StepRunId $stepRunId): int
    {
        return count($this->findByStepRun($stepRunId));
    }

    public function deleteByStepRun(StepRunId $stepRunId): int
    {
        $deleted = 0;
        foreach ($this->attempts as $id => $attempt) {
            if ($attempt->stepRunId->equals($stepRunId)) {
                unset($this->attempts[$id]);
                $deleted++;
            }
        }

        return $deleted;
    }

    public function clear(): void
    {
        $this->attempts = [];
    }

    public function all(): array
    {
        return array_values($this->attempts);
    }
}
