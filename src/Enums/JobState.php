<?php

declare(strict_types=1);

namespace Maestro\Workflow\Enums;

enum JobState: string
{
    case Dispatched = 'dispatched';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed], true);
    }

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            // Dispatched jobs can transition to Running normally, or directly to Failed
            // if they were never picked up (stale job detection)
            self::Dispatched => in_array($target, [self::Running, self::Failed], true),
            self::Running => in_array($target, [self::Succeeded, self::Failed], true),
            self::Succeeded, self::Failed => false,
        };
    }
}
