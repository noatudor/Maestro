<?php

declare(strict_types=1);

namespace Maestro\Workflow\Enums;

enum StepState: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Polling = 'polling';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case TimedOut = 'timed_out';
    case Superseded = 'superseded';
    case Skipped = 'skipped';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed, self::TimedOut, self::Superseded, self::Skipped], true);
    }

    public function isSuperseded(): bool
    {
        return $this === self::Superseded;
    }

    public function isSkipped(): bool
    {
        return $this === self::Skipped;
    }

    public function isPolling(): bool
    {
        return $this === self::Polling;
    }

    public function isTimedOut(): bool
    {
        return $this === self::TimedOut;
    }

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Pending => in_array($target, [self::Running, self::Polling, self::Superseded, self::Skipped], true),
            self::Running => in_array($target, [self::Succeeded, self::Failed, self::Superseded], true),
            self::Polling => in_array($target, [self::Running, self::Succeeded, self::Failed, self::TimedOut, self::Superseded], true),
            self::Succeeded => $target === self::Superseded,
            self::Failed => $target === self::Superseded,
            self::TimedOut => $target === self::Superseded,
            self::Superseded => false,
            self::Skipped => false,
        };
    }
}
