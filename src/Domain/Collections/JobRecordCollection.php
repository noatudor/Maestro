<?php

declare(strict_types=1);

namespace Maestro\Workflow\Domain\Collections;

use Maestro\Workflow\Domain\JobRecord;
use Maestro\Workflow\Enums\JobState;
use Maestro\Workflow\ValueObjects\StepRunId;

/**
 * @extends AbstractCollection<JobRecord>
 */
final class JobRecordCollection extends AbstractCollection
{
    /**
     * @param list<JobRecord> $items
     */
    public function __construct(array $items = [])
    {
        parent::__construct($items);
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @param list<JobRecord> $items
     */
    public static function fromArray(array $items): self
    {
        return new self($items);
    }

    public function add(JobRecord $jobRecord): self
    {
        $items = $this->items;
        $items[] = $jobRecord;

        return new self($items);
    }

    public function filter(callable $callback): self
    {
        return new self($this->filterItems($callback));
    }

    public function dispatched(): self
    {
        return $this->filter(fn (JobRecord $job): bool => $job->isDispatched());
    }

    public function running(): self
    {
        return $this->filter(fn (JobRecord $job): bool => $job->isRunning());
    }

    public function succeeded(): self
    {
        return $this->filter(fn (JobRecord $job): bool => $job->isSucceeded());
    }

    public function failed(): self
    {
        return $this->filter(fn (JobRecord $job): bool => $job->isFailed());
    }

    public function terminal(): self
    {
        return $this->filter(fn (JobRecord $job): bool => $job->isTerminal());
    }

    public function byState(JobState $state): self
    {
        return $this->filter(fn (JobRecord $job): bool => $job->status() === $state);
    }

    public function forStepRun(StepRunId $stepRunId): self
    {
        return $this->filter(fn (JobRecord $job): bool => $job->stepRunId->equals($stepRunId));
    }

    public function findByJobUuid(string $jobUuid): ?JobRecord
    {
        return $this->first(fn (JobRecord $job): bool => $job->jobUuid === $jobUuid);
    }

    public function findByQueue(string $queue): self
    {
        return $this->filter(fn (JobRecord $job): bool => $job->queue === $queue);
    }

    public function succeededCount(): int
    {
        return $this->succeeded()->count();
    }

    public function failedCount(): int
    {
        return $this->failed()->count();
    }

    public function terminalCount(): int
    {
        return $this->terminal()->count();
    }

    public function inProgressCount(): int
    {
        return $this->dispatched()->count() + $this->running()->count();
    }

    public function totalRuntimeMs(): int
    {
        return (int) $this->sum(fn (JobRecord $job): int => $job->runtimeMs() ?? 0);
    }

    public function averageRuntimeMs(): float
    {
        $completedJobs = $this->terminal();
        if ($completedJobs->isEmpty()) {
            return 0.0;
        }

        return $completedJobs->totalRuntimeMs() / $completedJobs->count();
    }

    public function hasAnyFailed(): bool
    {
        return $this->any(fn (JobRecord $job): bool => $job->isFailed());
    }

    public function areAllSucceeded(): bool
    {
        return $this->isNotEmpty() && $this->every(fn (JobRecord $job): bool => $job->isSucceeded());
    }

    public function areAllTerminal(): bool
    {
        return $this->isNotEmpty() && $this->every(fn (JobRecord $job): bool => $job->isTerminal());
    }

    public function areAllCompleted(): bool
    {
        return $this->areAllTerminal();
    }

    /**
     * @return array<string, int>
     */
    public function countByQueue(): array
    {
        $counts = [];
        foreach ($this->items as $job) {
            $queue = $job->queue;
            $counts[$queue] = ($counts[$queue] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    public function countByStatus(): array
    {
        $counts = [];
        foreach ($this->items as $job) {
            $status = $job->status()->value;
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        return $counts;
    }
}
