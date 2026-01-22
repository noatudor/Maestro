<?php

declare(strict_types=1);

namespace Maestro\Workflow\Definition\Config;

use Maestro\Workflow\Enums\CancelBehavior;
use Maestro\Workflow\Enums\CompensationScope;
use Maestro\Workflow\Enums\FailureResolutionStrategy;
use Maestro\Workflow\Enums\TimeoutBehavior;

/**
 * Configuration for how workflow failures are handled.
 *
 * This is set at the workflow definition level and determines the default
 * behavior when steps fail after exhausting their retry attempts.
 */
final readonly class FailureResolutionConfig
{
    private function __construct(
        public FailureResolutionStrategy $strategy,
        public AutoRetryConfig $autoRetryConfig,
        public CompensationScope $compensationScope,
        public CancelBehavior $cancelBehavior,
        public TimeoutBehavior $timeoutBehavior,
    ) {}

    /**
     * Create a configuration with AwaitDecision strategy (default, recommended).
     *
     * Workflow transitions to FAILED and waits for manual intervention.
     */
    public static function awaitDecision(
        CancelBehavior $cancelBehavior = CancelBehavior::NoCompensate,
        TimeoutBehavior $timeoutBehavior = TimeoutBehavior::Fail,
    ): self {
        return new self(
            strategy: FailureResolutionStrategy::AwaitDecision,
            autoRetryConfig: AutoRetryConfig::disabled(),
            compensationScope: CompensationScope::All,
            cancelBehavior: $cancelBehavior,
            timeoutBehavior: $timeoutBehavior,
        );
    }

    /**
     * Create a configuration with AutoRetry strategy.
     *
     * Automatically retries the failed step according to the provided config.
     * After max retries exhausted, falls back to the configured fallback strategy.
     */
    public static function autoRetry(
        AutoRetryConfig $autoRetryConfig,
        CancelBehavior $cancelBehavior = CancelBehavior::NoCompensate,
        TimeoutBehavior $timeoutBehavior = TimeoutBehavior::Fail,
    ): self {
        return new self(
            strategy: FailureResolutionStrategy::AutoRetry,
            autoRetryConfig: $autoRetryConfig,
            compensationScope: CompensationScope::All,
            cancelBehavior: $cancelBehavior,
            timeoutBehavior: $timeoutBehavior,
        );
    }

    /**
     * Create a configuration with AutoCompensate strategy.
     *
     * Immediately triggers compensation on failure. Requires steps to have
     * compensation jobs defined.
     */
    public static function autoCompensate(
        CompensationScope $compensationScope = CompensationScope::All,
        CancelBehavior $cancelBehavior = CancelBehavior::Compensate,
        TimeoutBehavior $timeoutBehavior = TimeoutBehavior::Compensate,
    ): self {
        return new self(
            strategy: FailureResolutionStrategy::AutoCompensate,
            autoRetryConfig: AutoRetryConfig::disabled(),
            compensationScope: $compensationScope,
            cancelBehavior: $cancelBehavior,
            timeoutBehavior: $timeoutBehavior,
        );
    }

    /**
     * Create the default configuration (AwaitDecision).
     */
    public static function default(): self
    {
        return self::awaitDecision();
    }

    public function awaitsDecision(): bool
    {
        return $this->strategy->awaitsDecision();
    }

    public function autoRetries(): bool
    {
        return $this->strategy->autoRetries();
    }

    public function autoCompensates(): bool
    {
        return $this->strategy->autoCompensates();
    }

    public function shouldCompensateOnCancel(): bool
    {
        return $this->cancelBehavior->shouldCompensate();
    }

    public function shouldCompensateOnTimeout(): bool
    {
        return $this->timeoutBehavior->shouldCompensate();
    }

    public function compensatesAllSteps(): bool
    {
        return $this->compensationScope->compensatesAll();
    }

    public function withStrategy(FailureResolutionStrategy $failureResolutionStrategy): self
    {
        return new self(
            strategy: $failureResolutionStrategy,
            autoRetryConfig: $this->autoRetryConfig,
            compensationScope: $this->compensationScope,
            cancelBehavior: $this->cancelBehavior,
            timeoutBehavior: $this->timeoutBehavior,
        );
    }

    public function withAutoRetryConfig(AutoRetryConfig $autoRetryConfig): self
    {
        return new self(
            strategy: $this->strategy,
            autoRetryConfig: $autoRetryConfig,
            compensationScope: $this->compensationScope,
            cancelBehavior: $this->cancelBehavior,
            timeoutBehavior: $this->timeoutBehavior,
        );
    }

    public function withCompensationScope(CompensationScope $compensationScope): self
    {
        return new self(
            strategy: $this->strategy,
            autoRetryConfig: $this->autoRetryConfig,
            compensationScope: $compensationScope,
            cancelBehavior: $this->cancelBehavior,
            timeoutBehavior: $this->timeoutBehavior,
        );
    }

    public function withCancelBehavior(CancelBehavior $cancelBehavior): self
    {
        return new self(
            strategy: $this->strategy,
            autoRetryConfig: $this->autoRetryConfig,
            compensationScope: $this->compensationScope,
            cancelBehavior: $cancelBehavior,
            timeoutBehavior: $this->timeoutBehavior,
        );
    }

    public function withTimeoutBehavior(TimeoutBehavior $timeoutBehavior): self
    {
        return new self(
            strategy: $this->strategy,
            autoRetryConfig: $this->autoRetryConfig,
            compensationScope: $this->compensationScope,
            cancelBehavior: $this->cancelBehavior,
            timeoutBehavior: $timeoutBehavior,
        );
    }

    public function equals(self $other): bool
    {
        return $this->strategy === $other->strategy
            && $this->autoRetryConfig->equals($other->autoRetryConfig)
            && $this->compensationScope === $other->compensationScope
            && $this->cancelBehavior === $other->cancelBehavior
            && $this->timeoutBehavior === $other->timeoutBehavior;
    }
}
