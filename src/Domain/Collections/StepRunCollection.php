<?php

declare(strict_types=1);

namespace Maestro\Workflow\Domain\Collections;

use Maestro\Workflow\Domain\StepRun;
use Maestro\Workflow\Enums\StepState;
use Maestro\Workflow\ValueObjects\StepKey;

/**
 * @extends AbstractCollection<StepRun>
 */
final class StepRunCollection extends AbstractCollection
{
    /**
     * @param list<StepRun> $items
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
     * @param list<StepRun> $items
     */
    public static function fromArray(array $items): self
    {
        return new self($items);
    }

    public function add(StepRun $stepRun): self
    {
        $items = $this->items;
        $items[] = $stepRun;

        return new self($items);
    }

    public function filter(callable $callback): self
    {
        return new self($this->filterItems($callback));
    }

    public function pending(): self
    {
        return $this->filter(fn (StepRun $stepRun): bool => $stepRun->isPending());
    }

    public function running(): self
    {
        return $this->filter(fn (StepRun $stepRun): bool => $stepRun->isRunning());
    }

    public function succeeded(): self
    {
        return $this->filter(fn (StepRun $stepRun): bool => $stepRun->isSucceeded());
    }

    public function failed(): self
    {
        return $this->filter(fn (StepRun $stepRun): bool => $stepRun->isFailed());
    }

    public function terminal(): self
    {
        return $this->filter(fn (StepRun $stepRun): bool => $stepRun->isTerminal());
    }

    public function byState(StepState $state): self
    {
        return $this->filter(fn (StepRun $stepRun): bool => $stepRun->status() === $state);
    }

    public function findByKey(StepKey $stepKey): ?StepRun
    {
        return $this->first(fn (StepRun $stepRun): bool => $stepRun->stepKey->equals($stepKey));
    }

    public function findLatestByKey(StepKey $stepKey): ?StepRun
    {
        $matching = $this->filter(fn (StepRun $stepRun): bool => $stepRun->stepKey->equals($stepKey));

        if ($matching->isEmpty()) {
            return null;
        }

        $latest = null;
        foreach ($matching as $stepRun) {
            if ($latest === null || $stepRun->attempt > $latest->attempt) {
                $latest = $stepRun;
            }
        }

        return $latest;
    }

    public function forAttempt(int $attempt): self
    {
        return $this->filter(fn (StepRun $stepRun): bool => $stepRun->attempt === $attempt);
    }

    public function latestAttempts(): self
    {
        $latestByKey = [];
        foreach ($this->items as $stepRun) {
            $key = $stepRun->stepKey->value;
            if (! isset($latestByKey[$key]) || $stepRun->attempt > $latestByKey[$key]->attempt) {
                $latestByKey[$key] = $stepRun;
            }
        }

        return new self(array_values($latestByKey));
    }

    public function totalJobCount(): int
    {
        return (int) $this->sum(fn (StepRun $stepRun): int => $stepRun->totalJobCount());
    }

    public function totalFailedJobCount(): int
    {
        return (int) $this->sum(fn (StepRun $stepRun): int => $stepRun->failedJobCount());
    }

    public function hasAnyFailed(): bool
    {
        return $this->any(fn (StepRun $stepRun): bool => $stepRun->isFailed());
    }

    public function areAllSucceeded(): bool
    {
        return $this->isNotEmpty() && $this->every(fn (StepRun $stepRun): bool => $stepRun->isSucceeded());
    }

    public function areAllTerminal(): bool
    {
        return $this->isNotEmpty() && $this->every(fn (StepRun $stepRun): bool => $stepRun->isTerminal());
    }
}
