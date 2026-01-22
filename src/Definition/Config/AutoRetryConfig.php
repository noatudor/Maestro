<?php

declare(strict_types=1);

namespace Maestro\Workflow\Definition\Config;

use Maestro\Workflow\Enums\FailureResolutionStrategy;

/**
 * Configuration for automatic retry behavior when using AutoRetry resolution strategy.
 *
 * This configures workflow-level auto-retry, which is different from step-level
 * retry configuration. Auto-retry is triggered after step retries are exhausted.
 */
final readonly class AutoRetryConfig
{
    private function __construct(
        public int $maxRetries,
        public int $delaySeconds,
        public float $backoffMultiplier,
        public int $maxDelaySeconds,
        public FailureResolutionStrategy $fallbackStrategy,
    ) {}

    public static function create(
        int $maxRetries = 3,
        int $delaySeconds = 60,
        float $backoffMultiplier = 2.0,
        int $maxDelaySeconds = 3600,
        FailureResolutionStrategy $failureResolutionStrategy = FailureResolutionStrategy::AwaitDecision,
    ): self {
        return new self(
            max(1, $maxRetries),
            max(0, $delaySeconds),
            max(1.0, $backoffMultiplier),
            max(0, $maxDelaySeconds),
            $failureResolutionStrategy,
        );
    }

    public static function default(): self
    {
        return new self(
            maxRetries: 3,
            delaySeconds: 60,
            backoffMultiplier: 2.0,
            maxDelaySeconds: 3600,
            fallbackStrategy: FailureResolutionStrategy::AwaitDecision,
        );
    }

    public static function disabled(): self
    {
        return new self(
            maxRetries: 0,
            delaySeconds: 0,
            backoffMultiplier: 1.0,
            maxDelaySeconds: 0,
            fallbackStrategy: FailureResolutionStrategy::AwaitDecision,
        );
    }

    public function isEnabled(): bool
    {
        return $this->maxRetries > 0;
    }

    public function hasReachedMaxRetries(int $currentRetryCount): bool
    {
        return $currentRetryCount >= $this->maxRetries;
    }

    public function getDelayForRetry(int $retryNumber): int
    {
        if ($retryNumber <= 1 || $this->delaySeconds === 0) {
            return $this->delaySeconds;
        }

        $delay = (int) ($this->delaySeconds * ($this->backoffMultiplier ** ($retryNumber - 1)));

        return min($delay, $this->maxDelaySeconds);
    }

    public function shouldFallbackToAwaitDecision(): bool
    {
        return $this->fallbackStrategy->awaitsDecision();
    }

    public function withMaxRetries(int $maxRetries): self
    {
        return new self(
            max(1, $maxRetries),
            $this->delaySeconds,
            $this->backoffMultiplier,
            $this->maxDelaySeconds,
            $this->fallbackStrategy,
        );
    }

    public function withDelay(int $seconds): self
    {
        return new self(
            $this->maxRetries,
            max(0, $seconds),
            $this->backoffMultiplier,
            $this->maxDelaySeconds,
            $this->fallbackStrategy,
        );
    }

    public function withBackoff(float $multiplier): self
    {
        return new self(
            $this->maxRetries,
            $this->delaySeconds,
            max(1.0, $multiplier),
            $this->maxDelaySeconds,
            $this->fallbackStrategy,
        );
    }

    public function withFallbackStrategy(FailureResolutionStrategy $failureResolutionStrategy): self
    {
        return new self(
            $this->maxRetries,
            $this->delaySeconds,
            $this->backoffMultiplier,
            $this->maxDelaySeconds,
            $failureResolutionStrategy,
        );
    }

    public function equals(self $other): bool
    {
        return $this->maxRetries === $other->maxRetries
            && $this->delaySeconds === $other->delaySeconds
            && $this->backoffMultiplier === $other->backoffMultiplier
            && $this->maxDelaySeconds === $other->maxDelaySeconds
            && $this->fallbackStrategy === $other->fallbackStrategy;
    }
}
