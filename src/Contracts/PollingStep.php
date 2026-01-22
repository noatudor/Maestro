<?php

declare(strict_types=1);

namespace Maestro\Workflow\Contracts;

use Maestro\Workflow\Definition\Config\PollingConfiguration;

/**
 * A step that executes repeatedly until a condition is met.
 *
 * Polling steps dispatch a job at configurable intervals and evaluate
 * the PollResult to determine when the step is complete.
 */
interface PollingStep extends StepDefinition
{
    /**
     * The fully qualified class name of the polling job to dispatch.
     *
     * @return class-string
     */
    public function jobClass(): string;

    /**
     * Get the polling configuration for this step.
     */
    public function pollingConfiguration(): PollingConfiguration;
}
