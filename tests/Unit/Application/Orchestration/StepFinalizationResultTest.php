<?php

declare(strict_types=1);

use Maestro\Workflow\Application\Orchestration\StepFinalizationResult;
use Maestro\Workflow\Domain\StepRun;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\WorkflowId;

describe('StepFinalizationResult', function (): void {
    beforeEach(function (): void {
        $this->stepRun = StepRun::create(
            WorkflowId::generate(),
            StepKey::fromString('test-step'),
        );
    });

    describe('notReady', function (): void {
        it('creates not finalized result', function (): void {
            $stepFinalizationResult = StepFinalizationResult::notReady($this->stepRun);

            expect($stepFinalizationResult->isFinalized())->toBeFalse();
            expect($stepFinalizationResult->stepRun())->toBe($this->stepRun);
        });
    });

    describe('finalized', function (): void {
        it('creates finalized result', function (): void {
            $stepFinalizationResult = StepFinalizationResult::finalized($this->stepRun);

            expect($stepFinalizationResult->isFinalized())->toBeTrue();
            expect($stepFinalizationResult->stepRun())->toBe($this->stepRun);
        });
    });
});
