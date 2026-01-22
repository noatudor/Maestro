<?php

declare(strict_types=1);

namespace Maestro\Workflow\Enums;

/**
 * Reasons why a step was skipped during workflow execution.
 */
enum SkipReason: string
{
    /**
     * Step condition evaluated to false.
     */
    case ConditionFalse = 'condition_false';

    /**
     * Step is not on the active branch path.
     */
    case NotOnActiveBranch = 'not_on_active_branch';

    /**
     * Workflow terminated early before this step.
     */
    case TerminatedEarly = 'terminated_early';

    public function displayName(): string
    {
        return match ($this) {
            self::ConditionFalse => 'Condition evaluated to false',
            self::NotOnActiveBranch => 'Not on active branch',
            self::TerminatedEarly => 'Workflow terminated early',
        };
    }
}
