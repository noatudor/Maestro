<?php

declare(strict_types=1);

use Maestro\Workflow\Domain\StepRun;
use Maestro\Workflow\Enums\StepState;
use Maestro\Workflow\Http\Responses\StepRunDTO;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\WorkflowId;

describe('StepRunDTO', static function (): void {
    describe('fromStepRun', static function (): void {
        it('creates dto from pending step run', function (): void {
            $stepRun = StepRun::create(
                WorkflowId::generate(),
                StepKey::fromString('step-1'),
                attempt: 1,
                totalJobCount: 3,
            );

            $stepRunDTO = StepRunDTO::fromStepRun($stepRun);

            expect($stepRunDTO->id)->toBe($stepRun->id->value);
            expect($stepRunDTO->workflowId)->toBe($stepRun->workflowId->value);
            expect($stepRunDTO->stepKey)->toBe('step-1');
            expect($stepRunDTO->attempt)->toBe(1);
            expect($stepRunDTO->status)->toBe(StepState::Pending);
            expect($stepRunDTO->totalJobCount)->toBe(3);
            expect($stepRunDTO->completedJobCount)->toBe(0);
            expect($stepRunDTO->failedJobCount)->toBe(0);
            expect($stepRunDTO->startedAt)->toBeNull();
            expect($stepRunDTO->finishedAt)->toBeNull();
        });

        it('creates dto from running step run', function (): void {
            $stepRun = StepRun::create(
                WorkflowId::generate(),
                StepKey::fromString('step-1'),
                totalJobCount: 5,
            );
            $stepRun->start();
            $stepRun->recordJobSuccess();
            $stepRun->recordJobSuccess();

            $stepRunDTO = StepRunDTO::fromStepRun($stepRun);

            expect($stepRunDTO->status)->toBe(StepState::Running);
            expect($stepRunDTO->startedAt)->not->toBeNull();
            expect($stepRunDTO->completedJobCount)->toBe(2);
            expect($stepRunDTO->succeededJobCount)->toBe(2);
        });

        it('creates dto from failed step run', function (): void {
            $stepRun = StepRun::create(
                WorkflowId::generate(),
                StepKey::fromString('step-1'),
                totalJobCount: 3,
            );
            $stepRun->start();
            $stepRun->recordJobFailure();
            $stepRun->fail('STEP_FAILED', 'Job failed');

            $stepRunDTO = StepRunDTO::fromStepRun($stepRun);

            expect($stepRunDTO->status)->toBe(StepState::Failed);
            expect($stepRunDTO->failureCode)->toBe('STEP_FAILED');
            expect($stepRunDTO->failureMessage)->toBe('Job failed');
            expect($stepRunDTO->failedJobCount)->toBe(1);
            expect($stepRunDTO->finishedAt)->not->toBeNull();
        });
    });

    describe('toArray', static function (): void {
        it('returns array representation', function (): void {
            $stepRun = StepRun::create(
                WorkflowId::generate(),
                StepKey::fromString('step-1'),
                attempt: 2,
                totalJobCount: 10,
            );

            $stepRunDTO = StepRunDTO::fromStepRun($stepRun);
            $array = $stepRunDTO->toArray();

            expect($array)->toBeArray();
            expect($array['step_key'])->toBe('step-1');
            expect($array['attempt'])->toBe(2);
            expect($array['status'])->toBe('pending');
            expect($array['total_job_count'])->toBe(10);
        });
    });
});
