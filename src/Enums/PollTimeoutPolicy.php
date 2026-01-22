<?php

declare(strict_types=1);

namespace Maestro\Workflow\Enums;

/**
 * Policy for handling polling step timeout.
 *
 * When a polling step exceeds its maximum duration or attempts,
 * this policy determines how the workflow should proceed.
 */
enum PollTimeoutPolicy: string
{
    /**
     * Mark the workflow as FAILED when polling times out.
     */
    case FailWorkflow = 'fail_workflow';

    /**
     * Pause the workflow and await manual decision.
     */
    case PauseWorkflow = 'pause_workflow';

    /**
     * Continue with a default output value provided in the configuration.
     */
    case ContinueWithDefault = 'continue_with_default';

    public function shouldFailWorkflow(): bool
    {
        return $this === self::FailWorkflow;
    }

    public function shouldPauseWorkflow(): bool
    {
        return $this === self::PauseWorkflow;
    }

    public function shouldContinueWithDefault(): bool
    {
        return $this === self::ContinueWithDefault;
    }
}
