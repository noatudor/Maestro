<?php

declare(strict_types=1);

namespace Maestro\Workflow\Enums;

/**
 * Defines the type of branching at a branch point.
 */
enum BranchType: string
{
    /**
     * Exactly one branch is taken based on the condition.
     * The condition must return exactly one branch key.
     */
    case Exclusive = 'exclusive';

    /**
     * One or more branches are taken based on the condition.
     * The condition can return multiple branch keys, and all
     * selected branches will execute.
     */
    case Inclusive = 'inclusive';

    public function displayName(): string
    {
        return match ($this) {
            self::Exclusive => 'Exclusive (XOR)',
            self::Inclusive => 'Inclusive (OR)',
        };
    }

    public function isExclusive(): bool
    {
        return $this === self::Exclusive;
    }

    public function isInclusive(): bool
    {
        return $this === self::Inclusive;
    }
}
