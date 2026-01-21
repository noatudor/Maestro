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
        return $this->filter(static fn (JobRecord $jobRecord): bool => $jobRecord->isDispatched());
    }

    public function running(): self
    {
        return $this->filter(static fn (JobRecord $jobRecord): bool => $jobRecord->isRunning());
    }

    public function succeeded(): self
    {
        return $this->filter(static fn (JobRecord $jobRecord): bool => $jobRecord->isSucceeded());
    }

    public function failed(): self
    {
        return $this->filter(static fn (JobRecord $jobRecord): bool => $jobRecord->isFailed());
    }

    public function terminal(): self
    {
        return $this->filter(static fn (JobRecord $jobRecord): bool => $jobRecord->isTerminal());
    }

    public function byState(JobState $jobState): self
    {
        return $this->filter(static fn (JobRecord $jobRecord): bool => $jobRecord->status() === $jobState);
    }

    public function forStepRun(StepRunId $stepRunId): self
    {
        return $this->filter(static fn (JobRecord $jobRecord): bool => $jobRecord->stepRunId->equals($stepRunId));
    }

    public function findByJobUuid(string $jobUuid): ?JobRecord
    {
        return $this->first(static fn (JobRecord $jobRecord): bool => $jobRecord->jobUuid === $jobUuid);
    }

    public function findByQueue(string $queue): self
    {
        return $this->filter(static fn (JobRecord $jobRecord): bool => $jobRecord->queue === $queue);
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
        return (int) $this->sum(static fn (JobRecord $jobRecord): int => $jobRecord->runtimeMs() ?? 0);
    }

    public function averageRuntimeMs(): float
    {
        $jobRecordCollection = $this->terminal();
        if ($jobRecordCollection->isEmpty()) {
            return 0.0;
        }

        return $jobRecordCollection->totalRuntimeMs() / $jobRecordCollection->count();
    }

    public function hasAnyFailed(): bool
    {
        return $this->any(static fn (JobRecord $jobRecord): bool => $jobRecord->isFailed());
    }

    public function areAllSucceeded(): bool
    {
        return $this->isNotEmpty() && $this->every(static fn (JobRecord $jobRecord): bool => $jobRecord->isSucceeded());
    }

    public function areAllTerminal(): bool
    {
        return $this->isNotEmpty() && $this->every(static fn (JobRecord $jobRecord): bool => $jobRecord->isTerminal());
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
        foreach ($this->items as $item) {
            $queue = $item->queue;
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
        foreach ($this->items as $item) {
            $status = $item->status()->value;
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        return $counts;
    }
}
