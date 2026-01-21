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
            $result = StepFinalizationResult::notReady($this->stepRun);

            expect($result->isFinalized())->toBeFalse();
            expect($result->stepRun())->toBe($this->stepRun);
        });
    });

    describe('finalized', function (): void {
        it('creates finalized result', function (): void {
            $result = StepFinalizationResult::finalized($this->stepRun);

            expect($result->isFinalized())->toBeTrue();
            expect($result->stepRun())->toBe($this->stepRun);
        });
    });
});
