<?php

declare(strict_types=1);

namespace Maestro\Workflow\ValueObjects;

use Maestro\Workflow\Contracts\PollResult;
use Maestro\Workflow\Contracts\StepOutput;

/**
 * A poll result indicating the polling condition has been satisfied.
 */
final readonly class CompletedPollResult implements PollResult
{
    private function __construct(
        private ?StepOutput $stepOutput,
    ) {}

    public static function withOutput(StepOutput $stepOutput): self
    {
        return new self($stepOutput);
    }

    public static function withoutOutput(): self
    {
        return new self(null);
    }

    public function isComplete(): bool
    {
        return true;
    }

    public function shouldContinue(): bool
    {
        return false;
    }

    public function output(): ?StepOutput
    {
        return $this->stepOutput;
    }

    public function nextIntervalSeconds(): ?int
    {
        return null;
    }
}
