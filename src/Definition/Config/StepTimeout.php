<?php

declare(strict_types=1);

namespace Maestro\Workflow\Definition\Config;

/**
 * Timeout configuration for a step.
 */
final readonly class StepTimeout
{
    private function __construct(
        public ?int $stepTimeoutSeconds,
        public ?int $jobTimeoutSeconds,
    ) {}

    public static function create(?int $stepTimeoutSeconds = null, ?int $jobTimeoutSeconds = null): self
    {
        return new self($stepTimeoutSeconds, $jobTimeoutSeconds);
    }

    public static function none(): self
    {
        return new self(null, null);
    }

    public static function stepOnly(int $seconds): self
    {
        return new self($seconds, null);
    }

    public static function jobOnly(int $seconds): self
    {
        return new self(null, $seconds);
    }

    public function hasStepTimeout(): bool
    {
        return $this->stepTimeoutSeconds !== null;
    }

    public function hasJobTimeout(): bool
    {
        return $this->jobTimeoutSeconds !== null;
    }

    public function hasAnyTimeout(): bool
    {
        if ($this->hasStepTimeout()) {
            return true;
        }

        return $this->hasJobTimeout();
    }

    public function withStepTimeout(int $seconds): self
    {
        return new self($seconds, $this->jobTimeoutSeconds);
    }

    public function withJobTimeout(int $seconds): self
    {
        return new self($this->stepTimeoutSeconds, $seconds);
    }

    public function equals(self $other): bool
    {
        return $this->stepTimeoutSeconds === $other->stepTimeoutSeconds
            && $this->jobTimeoutSeconds === $other->jobTimeoutSeconds;
    }
}
