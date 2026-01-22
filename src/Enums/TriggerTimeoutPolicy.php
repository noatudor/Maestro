<?php

declare(strict_types=1);

namespace Maestro\Workflow\Enums;

/**
 * Policy for handling pause trigger timeout.
 *
 * When a workflow is paused awaiting a trigger and the timeout is exceeded,
 * this policy determines how the workflow should proceed.
 */
enum TriggerTimeoutPolicy: string
{
    /**
     * Mark the workflow as FAILED when trigger times out.
     */
    case FailWorkflow = 'fail_workflow';

    /**
     * Keep the workflow paused and send a reminder notification.
     */
    case SendReminder = 'send_reminder';

    /**
     * Automatically resume with empty/default payload.
     */
    case AutoResume = 'auto_resume';

    /**
     * Extend the timeout deadline.
     */
    case ExtendTimeout = 'extend_timeout';

    public function shouldFailWorkflow(): bool
    {
        return $this === self::FailWorkflow;
    }

    public function shouldSendReminder(): bool
    {
        return $this === self::SendReminder;
    }

    public function shouldAutoResume(): bool
    {
        return $this === self::AutoResume;
    }

    public function shouldExtendTimeout(): bool
    {
        return $this === self::ExtendTimeout;
    }
}
