<?php

declare(strict_types=1);

namespace Maestro\Workflow\Definition\Config;

/**
 * Defines compensation configuration for a step.
 *
 * When a step has compensation defined, its compensation job can be
 * executed to undo the side effects of the forward job.
 */
final readonly class CompensationDefinition
{
    /**
     * @param class-string $jobClass
     */
    private function __construct(
        public string $jobClass,
        public ?int $timeoutSeconds,
        public RetryConfiguration $retryConfiguration,
        public ?QueueConfiguration $queueConfiguration,
    ) {}

    /**
     * @param class-string $jobClass
     */
    public static function create(
        string $jobClass,
        ?int $timeoutSeconds = null,
        ?RetryConfiguration $retryConfiguration = null,
        ?QueueConfiguration $queueConfiguration = null,
    ): self {
        return new self(
            $jobClass,
            $timeoutSeconds,
            $retryConfiguration ?? RetryConfiguration::default(),
            $queueConfiguration,
        );
    }

    public function hasTimeout(): bool
    {
        return $this->timeoutSeconds !== null;
    }

    public function withTimeout(int $seconds): self
    {
        return new self(
            $this->jobClass,
            $seconds,
            $this->retryConfiguration,
            $this->queueConfiguration,
        );
    }

    public function withRetry(RetryConfiguration $retryConfiguration): self
    {
        return new self(
            $this->jobClass,
            $this->timeoutSeconds,
            $retryConfiguration,
            $this->queueConfiguration,
        );
    }

    public function withQueue(QueueConfiguration $queueConfiguration): self
    {
        return new self(
            $this->jobClass,
            $this->timeoutSeconds,
            $this->retryConfiguration,
            $queueConfiguration,
        );
    }
}
