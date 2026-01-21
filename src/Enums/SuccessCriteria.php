<?php

declare(strict_types=1);

namespace Maestro\Workflow\Enums;

/**
 * Defines success criteria for fan-out steps.
 */
enum SuccessCriteria: string
{
    case All = 'all';
    case Majority = 'majority';
    case BestEffort = 'best_effort';

    public function requiresAllJobs(): bool
    {
        return $this === self::All;
    }

    public function requiresMajority(): bool
    {
        return $this === self::Majority;
    }

    public function allowsAnySuccess(): bool
    {
        return $this === self::BestEffort;
    }

    public function evaluate(int $succeeded, int $total): bool
    {
        if ($total === 0) {
            return true;
        }

        return match ($this) {
            self::All => $succeeded === $total,
            self::Majority => $succeeded > ($total / 2),
            self::BestEffort => $succeeded > 0,
        };
    }
}
