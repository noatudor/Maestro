<?php

declare(strict_types=1);

namespace Maestro\Workflow\Contracts;

/**
 * Result returned from a polling job to indicate whether polling is complete.
 *
 * Polling jobs return this to indicate:
 * - Whether the polling condition is satisfied (isComplete)
 * - Whether polling should continue if not complete (shouldContinue)
 * - The output to store when complete (output)
 * - Optional custom interval for the next poll (nextIntervalSeconds)
 */
interface PollResult
{
    /**
     * Whether the polling condition has been satisfied.
     *
     * When true, the step will be marked as succeeded and output stored.
     */
    public function isComplete(): bool;

    /**
     * Whether polling should continue if not complete.
     *
     * When false and isComplete is false, the step will be aborted.
     */
    public function shouldContinue(): bool;

    /**
     * The output to store when polling is complete.
     *
     * Only used when isComplete() returns true.
     */
    public function output(): ?StepOutput;

    /**
     * Override the next polling interval in seconds.
     *
     * Returns null to use the default interval from configuration.
     * Returns a positive integer to override the next interval.
     */
    public function nextIntervalSeconds(): ?int;
}
