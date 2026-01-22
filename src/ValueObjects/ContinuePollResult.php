<?php

declare(strict_types=1);

namespace Maestro\Workflow\ValueObjects;

use Maestro\Workflow\Contracts\PollResult;
use Maestro\Workflow\Contracts\StepOutput;

/**
 * A poll result indicating polling should continue.
 */
final readonly class ContinuePollResult implements PollResult
{
    private function __construct(
        private ?int $nextIntervalOverrideSeconds,
    ) {}

    public static function atDefaultInterval(): self
    {
        return new self(null);
    }

    public static function afterSeconds(int $seconds): self
    {
        return new self(max(1, $seconds));
    }

    public function isComplete(): bool
    {
        return false;
    }

    public function shouldContinue(): bool
    {
        return true;
    }

    public function output(): ?StepOutput
    {
        return null;
    }

    public function nextIntervalSeconds(): ?int
    {
        return $this->nextIntervalOverrideSeconds;
    }
}
