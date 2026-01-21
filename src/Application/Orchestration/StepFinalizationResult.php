<?php

declare(strict_types=1);

namespace Maestro\Workflow\Application\Orchestration;

use Maestro\Workflow\Domain\StepRun;

/**
 * Result of a step finalization attempt.
 *
 * In concurrent scenarios (fan-in), multiple workers may attempt to finalize
 * the same step. Only one will succeed; the others will receive an
 * "already finalized" result.
 */
final readonly class StepFinalizationResult
{
    private function __construct(
        private bool $finalized,
        private bool $wonRace,
        private StepRun $stepRun,
    ) {}

    /**
     * Step is not ready for finalization (jobs still running).
     */
    public static function notReady(StepRun $stepRun): self
    {
        return new self(false, false, $stepRun);
    }

    /**
     * Step was finalized by this worker.
     */
    public static function finalized(StepRun $stepRun): self
    {
        return new self(true, true, $stepRun);
    }

    /**
     * Step was already finalized by another worker (this worker lost the race).
     */
    public static function alreadyFinalized(StepRun $stepRun): self
    {
        return new self(true, false, $stepRun);
    }

    /**
     * Check if the step has been finalized (by any worker).
     */
    public function isFinalized(): bool
    {
        return $this->finalized;
    }

    /**
     * Check if this worker won the finalization race.
     *
     * Returns true only if this worker was the one that finalized the step.
     * Use this to determine whether to proceed with workflow advancement.
     */
    public function wonRace(): bool
    {
        return $this->wonRace;
    }

    public function stepRun(): StepRun
    {
        return $this->stepRun;
    }
}
