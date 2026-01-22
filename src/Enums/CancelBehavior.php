<?php

declare(strict_types=1);

namespace Maestro\Workflow\Enums;

/**
 * Defines behavior when a workflow is cancelled.
 */
enum CancelBehavior: string
{
    /**
     * Execute compensation jobs for completed steps before cancellation.
     */
    case Compensate = 'compensate';

    /**
     * Cancel immediately without compensation. Side effects remain.
     */
    case NoCompensate = 'no_compensate';

    public function shouldCompensate(): bool
    {
        return $this === self::Compensate;
    }
}
