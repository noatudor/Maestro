<?php

declare(strict_types=1);

namespace Maestro\Workflow\Definition\Config;

/**
 * Queue configuration for job dispatch.
 */
final readonly class QueueConfiguration
{
    private function __construct(
        public ?string $queue,
        public ?string $connection,
        public int $delaySeconds,
    ) {}

    public static function create(?string $queue = null, ?string $connection = null, int $delaySeconds = 0): self
    {
        return new self($queue, $connection, max(0, $delaySeconds));
    }

    public static function default(): self
    {
        return new self(null, null, 0);
    }

    public static function onQueue(string $queue): self
    {
        return new self($queue, null, 0);
    }

    public static function onConnection(string $connection): self
    {
        return new self(null, $connection, 0);
    }

    public function hasQueue(): bool
    {
        return $this->queue !== null;
    }

    public function hasConnection(): bool
    {
        return $this->connection !== null;
    }

    public function hasDelay(): bool
    {
        return $this->delaySeconds > 0;
    }

    public function withQueue(string $queue): self
    {
        return new self($queue, $this->connection, $this->delaySeconds);
    }

    public function withConnection(string $connection): self
    {
        return new self($this->queue, $connection, $this->delaySeconds);
    }

    public function withDelay(int $seconds): self
    {
        return new self($this->queue, $this->connection, max(0, $seconds));
    }

    public function equals(self $other): bool
    {
        return $this->queue === $other->queue
            && $this->connection === $other->connection
            && $this->delaySeconds === $other->delaySeconds;
    }
}
