<?php

declare(strict_types=1);

namespace Maestro\Workflow\Definition\Config;

use Maestro\Workflow\Enums\RetryScope;

/**
 * Retry configuration for a step.
 */
final readonly class RetryConfiguration
{
    private function __construct(
        public int $maxAttempts,
        public int $delaySeconds,
        public float $backoffMultiplier,
        public int $maxDelaySeconds,
        public RetryScope $scope,
    ) {}

    public static function create(
        int $maxAttempts = 3,
        int $delaySeconds = 0,
        float $backoffMultiplier = 1.0,
        int $maxDelaySeconds = 3600,
        RetryScope $scope = RetryScope::All,
    ): self {
        return new self(
            max(1, $maxAttempts),
            max(0, $delaySeconds),
            max(1.0, $backoffMultiplier),
            max(0, $maxDelaySeconds),
            $scope,
        );
    }

    public static function none(): self
    {
        return new self(1, 0, 1.0, 0, RetryScope::All);
    }

    public static function default(): self
    {
        return new self(3, 60, 2.0, 3600, RetryScope::All);
    }

    public function allowsRetry(): bool
    {
        return $this->maxAttempts > 1;
    }

    public function hasReachedMaxAttempts(int $currentAttempt): bool
    {
        return $currentAttempt >= $this->maxAttempts;
    }

    public function getDelayForAttempt(int $attempt): int
    {
        if ($attempt <= 1 || $this->delaySeconds === 0) {
            return $this->delaySeconds;
        }

        $delay = (int) ($this->delaySeconds * ($this->backoffMultiplier ** ($attempt - 1)));

        return min($delay, $this->maxDelaySeconds);
    }

    public function retriesAllJobs(): bool
    {
        return $this->scope->retriesAllJobs();
    }

    public function retriesFailedJobsOnly(): bool
    {
        return $this->scope->retriesFailedJobsOnly();
    }

    public function withMaxAttempts(int $maxAttempts): self
    {
        return new self(
            max(1, $maxAttempts),
            $this->delaySeconds,
            $this->backoffMultiplier,
            $this->maxDelaySeconds,
            $this->scope,
        );
    }

    public function withDelay(int $seconds): self
    {
        return new self(
            $this->maxAttempts,
            max(0, $seconds),
            $this->backoffMultiplier,
            $this->maxDelaySeconds,
            $this->scope,
        );
    }

    public function withBackoff(float $multiplier): self
    {
        return new self(
            $this->maxAttempts,
            $this->delaySeconds,
            max(1.0, $multiplier),
            $this->maxDelaySeconds,
            $this->scope,
        );
    }

    public function withScope(RetryScope $scope): self
    {
        return new self(
            $this->maxAttempts,
            $this->delaySeconds,
            $this->backoffMultiplier,
            $this->maxDelaySeconds,
            $scope,
        );
    }

    public function equals(self $other): bool
    {
        return $this->maxAttempts === $other->maxAttempts
            && $this->delaySeconds === $other->delaySeconds
            && $this->backoffMultiplier === $other->backoffMultiplier
            && $this->maxDelaySeconds === $other->maxDelaySeconds
            && $this->scope === $other->scope;
    }
}
