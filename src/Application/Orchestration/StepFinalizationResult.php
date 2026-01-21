<?php

declare(strict_types=1);

namespace Maestro\Workflow\Application\Orchestration;

use Maestro\Workflow\Domain\StepRun;

/**
 * Result of a step finalization attempt.
 */
final readonly class StepFinalizationResult
{
    private function __construct(
        private bool $finalized,
        private StepRun $stepRun,
    ) {}

    public static function notReady(StepRun $stepRun): self
    {
        return new self(false, $stepRun);
    }

    public static function finalized(StepRun $stepRun): self
    {
        return new self(true, $stepRun);
    }

    public function isFinalized(): bool
    {
        return $this->finalized;
    }

    public function stepRun(): StepRun
    {
        return $this->stepRun;
    }
}
