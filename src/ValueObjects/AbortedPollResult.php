<?php

declare(strict_types=1);

namespace Maestro\Workflow\ValueObjects;

use Maestro\Workflow\Contracts\PollResult;
use Maestro\Workflow\Contracts\StepOutput;

/**
 * A poll result indicating polling should be aborted without completion.
 *
 * This signals that the polling condition cannot be met and the step
 * should fail according to its failure policy.
 */
final readonly class AbortedPollResult implements PollResult
{
    private function __construct() {}

    public static function create(): self
    {
        return new self();
    }

    public function isComplete(): bool
    {
        return false;
    }

    public function shouldContinue(): bool
    {
        return false;
    }

    public function output(): ?StepOutput
    {
        return null;
    }

    public function nextIntervalSeconds(): ?int
    {
        return null;
    }
}
