<?php

declare(strict_types=1);

namespace Maestro\Workflow\Application\Orchestration;

/**
 * Statistics about jobs for a step run.
 */
final readonly class StepJobStats
{
    private function __construct(
        public int $total,
        public int $succeeded,
        public int $failed,
        public int $running,
        public int $dispatched,
    ) {}

    public static function create(
        int $total,
        int $succeeded,
        int $failed,
        int $running,
        int $dispatched,
    ): self {
        return new self(
            max(0, $total),
            max(0, $succeeded),
            max(0, $failed),
            max(0, $running),
            max(0, $dispatched),
        );
    }

    public static function empty(): self
    {
        return new self(0, 0, 0, 0, 0);
    }

    public function completed(): int
    {
        return $this->succeeded + $this->failed;
    }

    public function pending(): int
    {
        return $this->running + $this->dispatched;
    }

    public function allJobsComplete(): bool
    {
        if ($this->total === 0) {
            return true;
        }

        return $this->completed() >= $this->total;
    }

    public function hasFailures(): bool
    {
        return $this->failed > 0;
    }

    public function hasSuccesses(): bool
    {
        return $this->succeeded > 0;
    }

    public function allSucceeded(): bool
    {
        return $this->total > 0 && $this->succeeded === $this->total;
    }

    public function allFailed(): bool
    {
        return $this->total > 0 && $this->failed === $this->total;
    }

    public function successRate(): float
    {
        if ($this->total === 0) {
            return 1.0;
        }

        return $this->succeeded / $this->total;
    }
}
