<?php

declare(strict_types=1);

namespace Maestro\Workflow\Enums;

/**
 * Status of a compensation run for a single step.
 */
enum CompensationRunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed, self::Skipped], true);
    }

    public function isSuccessful(): bool
    {
        return $this === self::Succeeded || $this === self::Skipped;
    }

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Pending => $target === self::Running || $target === self::Skipped,
            self::Running => in_array($target, [self::Succeeded, self::Failed], true),
            self::Failed => in_array($target, [self::Running, self::Skipped], true),
            self::Succeeded, self::Skipped => false,
        };
    }
}
