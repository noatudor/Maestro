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

    public function isTerminal(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed, self::Cancelled], true);
    }

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Pending => $target === self::Running,
            self::Running => in_array($target, [self::Paused, self::Succeeded, self::Failed, self::Cancelled], true),
            self::Paused => in_array($target, [self::Running, self::Cancelled], true),
            self::Failed => in_array($target, [self::Running, self::Cancelled], true),
            self::Succeeded, self::Cancelled => false,
        };
    }

    public function isActive(): bool
    {
        return in_array($this, [self::Pending, self::Running, self::Paused], true);
    }
}
