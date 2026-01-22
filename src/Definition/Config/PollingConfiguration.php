<?php

declare(strict_types=1);

namespace Maestro\Workflow\Definition\Config;

use Maestro\Workflow\Contracts\StepOutput;
use Maestro\Workflow\Enums\PollTimeoutPolicy;

/**
 * Configuration for polling step behavior.
 *
 * Defines the polling interval, maximum duration, maximum attempts,
 * backoff strategy, and timeout policy.
 */
final readonly class PollingConfiguration
{
    /**
     * @param class-string<StepOutput>|null $defaultOutputClass
     */
    private function __construct(
        public int $intervalSeconds,
        public int $maxDurationSeconds,
        public ?int $maxAttempts,
        public float $backoffMultiplier,
        public int $maxIntervalSeconds,
        public PollTimeoutPolicy $timeoutPolicy,
        public ?string $defaultOutputClass,
    ) {}

    /**
     * @param class-string<StepOutput>|null $defaultOutputClass
     */
    public static function create(
        int $intervalSeconds = 300,
        int $maxDurationSeconds = 86400,
        ?int $maxAttempts = null,
        float $backoffMultiplier = 1.0,
        ?int $maxIntervalSeconds = null,
        PollTimeoutPolicy $pollTimeoutPolicy = PollTimeoutPolicy::FailWorkflow,
        ?string $defaultOutputClass = null,
    ): self {
        return new self(
            intervalSeconds: max(1, $intervalSeconds),
            maxDurationSeconds: max(1, $maxDurationSeconds),
            maxAttempts: $maxAttempts !== null ? max(1, $maxAttempts) : null,
            backoffMultiplier: max(1.0, $backoffMultiplier),
            maxIntervalSeconds: $maxIntervalSeconds !== null ? max($intervalSeconds, $maxIntervalSeconds) : $intervalSeconds * 60,
            timeoutPolicy: $pollTimeoutPolicy,
            defaultOutputClass: $defaultOutputClass,
        );
    }

    public static function default(): self
    {
        return new self(
            intervalSeconds: 300,
            maxDurationSeconds: 86400,
            maxAttempts: null,
            backoffMultiplier: 1.0,
            maxIntervalSeconds: 18000,
            timeoutPolicy: PollTimeoutPolicy::FailWorkflow,
            defaultOutputClass: null,
        );
    }

    /**
     * Calculate the next poll interval based on the current attempt.
     *
     * Supports exponential backoff when backoffMultiplier > 1.0.
     */
    public function calculateIntervalForAttempt(int $attemptNumber, ?int $overrideSeconds = null): int
    {
        if ($overrideSeconds !== null) {
            return min($overrideSeconds, $this->maxIntervalSeconds);
        }

        if ($attemptNumber <= 1 || $this->backoffMultiplier <= 1.0) {
            return $this->intervalSeconds;
        }

        $interval = (int) ($this->intervalSeconds * ($this->backoffMultiplier ** ($attemptNumber - 1)));

        return min($interval, $this->maxIntervalSeconds);
    }

    /**
     * Check if the given attempt count exceeds the maximum.
     */
    public function hasExceededMaxAttempts(int $attemptCount): bool
    {
        return $this->maxAttempts !== null && $attemptCount >= $this->maxAttempts;
    }

    /**
     * Check if polling should use exponential backoff.
     */
    public function hasBackoff(): bool
    {
        return $this->backoffMultiplier > 1.0;
    }

    /**
     * Check if a default output is configured for timeout continuation.
     */
    public function hasDefaultOutput(): bool
    {
        return $this->defaultOutputClass !== null;
    }

    public function withInterval(int $seconds): self
    {
        return new self(
            max(1, $seconds),
            $this->maxDurationSeconds,
            $this->maxAttempts,
            $this->backoffMultiplier,
            $this->maxIntervalSeconds,
            $this->timeoutPolicy,
            $this->defaultOutputClass,
        );
    }

    public function withMaxDuration(int $seconds): self
    {
        return new self(
            $this->intervalSeconds,
            max(1, $seconds),
            $this->maxAttempts,
            $this->backoffMultiplier,
            $this->maxIntervalSeconds,
            $this->timeoutPolicy,
            $this->defaultOutputClass,
        );
    }

    public function withMaxAttempts(?int $attempts): self
    {
        return new self(
            $this->intervalSeconds,
            $this->maxDurationSeconds,
            $attempts !== null ? max(1, $attempts) : null,
            $this->backoffMultiplier,
            $this->maxIntervalSeconds,
            $this->timeoutPolicy,
            $this->defaultOutputClass,
        );
    }

    public function withBackoff(float $multiplier): self
    {
        return new self(
            $this->intervalSeconds,
            $this->maxDurationSeconds,
            $this->maxAttempts,
            max(1.0, $multiplier),
            $this->maxIntervalSeconds,
            $this->timeoutPolicy,
            $this->defaultOutputClass,
        );
    }

    public function withMaxInterval(int $seconds): self
    {
        return new self(
            $this->intervalSeconds,
            $this->maxDurationSeconds,
            $this->maxAttempts,
            $this->backoffMultiplier,
            max($this->intervalSeconds, $seconds),
            $this->timeoutPolicy,
            $this->defaultOutputClass,
        );
    }

    public function withTimeoutPolicy(PollTimeoutPolicy $pollTimeoutPolicy): self
    {
        return new self(
            $this->intervalSeconds,
            $this->maxDurationSeconds,
            $this->maxAttempts,
            $this->backoffMultiplier,
            $this->maxIntervalSeconds,
            $pollTimeoutPolicy,
            $this->defaultOutputClass,
        );
    }

    /**
     * @param class-string<StepOutput>|null $outputClass
     */
    public function withDefaultOutput(?string $outputClass): self
    {
        return new self(
            $this->intervalSeconds,
            $this->maxDurationSeconds,
            $this->maxAttempts,
            $this->backoffMultiplier,
            $this->maxIntervalSeconds,
            $this->timeoutPolicy,
            $outputClass,
        );
    }

    public function equals(self $other): bool
    {
        return $this->intervalSeconds === $other->intervalSeconds
            && $this->maxDurationSeconds === $other->maxDurationSeconds
            && $this->maxAttempts === $other->maxAttempts
            && $this->backoffMultiplier === $other->backoffMultiplier
            && $this->maxIntervalSeconds === $other->maxIntervalSeconds
            && $this->timeoutPolicy === $other->timeoutPolicy
            && $this->defaultOutputClass === $other->defaultOutputClass;
    }
}
