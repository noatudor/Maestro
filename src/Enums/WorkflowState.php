<?php

declare(strict_types=1);

namespace Maestro\Workflow\Enums;

enum WorkflowState: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Paused = 'paused';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Compensating = 'compensating';
    case Compensated = 'compensated';
    case CompensationFailed = 'compensation_failed';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed, self::Cancelled, self::Compensated], true);
    }

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Pending => $target === self::Running,
            self::Running => in_array($target, [self::Paused, self::Succeeded, self::Failed, self::Cancelled, self::Compensating], true),
            self::Paused => in_array($target, [self::Running, self::Cancelled, self::Compensating], true),
            self::Failed => in_array($target, [self::Running, self::Cancelled, self::Compensating], true),
            self::Compensating => in_array($target, [self::Compensated, self::CompensationFailed], true),
            self::CompensationFailed => in_array($target, [self::Compensating, self::Compensated], true),
            self::Succeeded, self::Cancelled, self::Compensated => false,
        };
    }

    public function isActive(): bool
    {
        return in_array($this, [self::Pending, self::Running, self::Paused, self::Compensating], true);
    }

    public function isCompensating(): bool
    {
        return $this === self::Compensating;
    }

    public function isCompensated(): bool
    {
        return $this === self::Compensated;
    }

    public function isCompensationFailed(): bool
    {
        return $this === self::CompensationFailed;
    }

    public function requiresCompensationHandling(): bool
    {
        return in_array($this, [self::Compensating, self::CompensationFailed], true);
    }
}
