<?php

declare(strict_types=1);

use Maestro\Workflow\Domain\StepRun;
use Maestro\Workflow\Enums\SkipReason;
use Maestro\Workflow\ValueObjects\StepDispatchResult;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\WorkflowId;

describe('StepDispatchResult', function () {
    describe('dispatched', function () {
        it('creates a dispatched result from step run', function () {
            $stepRun = StepRun::create(
                WorkflowId::generate(),
                StepKey::fromString('test-step'),
            );

            $result = StepDispatchResult::dispatched($stepRun);

            expect($result->wasDispatched())->toBeTrue()
                ->and($result->wasSkipped())->toBeFalse()
                ->and($result->stepRun())->toBe($stepRun)
                ->and($result->skipReason())->toBeNull();
        });
    });

    describe('skipped', function () {
        it('creates a skipped result from step run', function () {
            $stepRun = StepRun::create(
                WorkflowId::generate(),
                StepKey::fromString('test-step'),
            );
            $stepRun->skip(SkipReason::ConditionFalse);

            $result = StepDispatchResult::skipped($stepRun);

            expect($result->wasSkipped())->toBeTrue()
                ->and($result->wasDispatched())->toBeFalse()
                ->and($result->stepRun())->toBe($stepRun)
                ->and($result->skipReason())->toBe(SkipReason::ConditionFalse);
        });

        it('creates a skipped result with different skip reasons', function () {
            $reasons = [
                SkipReason::ConditionFalse,
                SkipReason::NotOnActiveBranch,
                SkipReason::TerminatedEarly,
            ];

            foreach ($reasons as $reason) {
                $stepRun = StepRun::create(
                    WorkflowId::generate(),
                    StepKey::fromString('test-step'),
                );
                $stepRun->skip($reason);

                $result = StepDispatchResult::skipped($stepRun);

                expect($result->skipReason())->toBe($reason);
            }
        });
    });
});
