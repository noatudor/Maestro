<?php

declare(strict_types=1);

namespace Maestro\Workflow\ValueObjects;

use Maestro\Workflow\Domain\StepRun;
use Maestro\Workflow\Enums\SkipReason;

/**
 * The result of attempting to dispatch a step.
 */
final readonly class StepDispatchResult
{
    private function __construct(
        private StepRun $stepRun,
        private bool $wasSkipped,
    ) {}

    /**
     * Create a result for a successfully dispatched step.
     */
    public static function dispatched(StepRun $stepRun): self
    {
        return new self($stepRun, false);
    }

    /**
     * Create a result for a skipped step.
     */
    public static function skipped(StepRun $stepRun): self
    {
        return new self($stepRun, true);
    }

    /**
     * Get the step run record (either running or skipped).
     */
    public function stepRun(): StepRun
    {
        return $this->stepRun;
    }

    /**
     * Whether the step was skipped.
     */
    public function wasSkipped(): bool
    {
        return $this->wasSkipped;
    }

    /**
     * Whether the step was dispatched for execution.
     */
    public function wasDispatched(): bool
    {
        return ! $this->wasSkipped;
    }

    /**
     * Get the skip reason if the step was skipped.
     */
    public function skipReason(): ?SkipReason
    {
        return $this->stepRun->skipReason();
    }
}
