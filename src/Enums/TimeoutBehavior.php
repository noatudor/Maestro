<?php

declare(strict_types=1);

namespace Maestro\Workflow\Enums;

/**
 * Defines behavior when a workflow times out.
 */
enum TimeoutBehavior: string
{
    /**
     * Execute compensation jobs for completed steps on timeout.
     */
    case Compensate = 'compensate';

    /**
     * Mark workflow as FAILED and await manual decision.
     */
    case Fail = 'fail';

    /**
     * Pause workflow and await manual decision.
     */
    case AwaitDecision = 'await_decision';

    public function shouldCompensate(): bool
    {
        return $this === self::Compensate;
    }

    public function shouldFail(): bool
    {
        return $this === self::Fail;
    }

    public function shouldAwaitDecision(): bool
    {
        return $this === self::AwaitDecision;
    }
}
