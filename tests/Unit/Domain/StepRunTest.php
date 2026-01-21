<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Maestro\Workflow\Domain\StepRun;
use Maestro\Workflow\Enums\StepState;
use Maestro\Workflow\Exceptions\InvalidStateTransitionException;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\StepRunId;
use Maestro\Workflow\ValueObjects\WorkflowId;

describe('StepRun', static function (): void {
    beforeEach(function (): void {
        CarbonImmutable::setTestNow(CarbonImmutable::now());

        $this->workflowId = WorkflowId::generate();
        $this->stepKey = StepKey::fromString('test-step');
    });

    afterEach(function (): void {
        CarbonImmutable::setTestNow(null);
    });

    describe('creation', static function (): void {
        it('creates with pending state by default', function (): void {
            $stepRun = StepRun::create($this->workflowId, $this->stepKey);

            expect($stepRun->status())->toBe(StepState::Pending)
                ->and($stepRun->workflowId)->toBe($this->workflowId)
                ->and($stepRun->stepKey)->toBe($this->stepKey)
                ->and($stepRun->attempt)->toBe(1)
                ->and($stepRun->isPending())->toBeTrue()
                ->and($stepRun->failedJobCount())->toBe(0)
                ->and($stepRun->totalJobCount())->toBe(0);
        });

        it('creates with a specific attempt number', function (): void {
            $stepRun = StepRun::create($this->workflowId, $this->stepKey, attempt: 3);

            expect($stepRun->attempt)->toBe(3);
        });

        it('creates with a total job count', function (): void {
            $stepRun = StepRun::create($this->workflowId, $this->stepKey, totalJobCount: 5);

            expect($stepRun->totalJobCount())->toBe(5);
        });

        it('creates with a provided step run id', function (): void {
            $id = StepRunId::generate();
            $stepRun = StepRun::create($this->workflowId, $this->stepKey, id: $id);

            expect($stepRun->id)->toBe($id);
        });

        it('generates step run id if not provided', function (): void {
            $stepRun = StepRun::create($this->workflowId, $this->stepKey);

            expect($stepRun->id->value)->toBeValidUuid();
        });
    });

    describe('state transitions', static function (): void {
        it('transitions from pending to running via start', function (): void {
            $stepRun = StepRun::create($this->workflowId, $this->stepKey);

            $stepRun->start();

            expect($stepRun->status())->toBe(StepState::Running)
                ->and($stepRun->startedAt())->toBeInstanceOf(CarbonImmutable::class)
                ->and($stepRun->isRunning())->toBeTrue();
        });

        it('transitions from running to succeeded', function (): void {
            $stepRun = StepRun::create($this->workflowId, $this->stepKey);
            $stepRun->start();

            $stepRun->succeed();

            expect($stepRun->status())->toBe(StepState::Succeeded)
                ->and($stepRun->finishedAt())->toBeInstanceOf(CarbonImmutable::class)
                ->and($stepRun->isSucceeded())->toBeTrue()
                ->and($stepRun->isTerminal())->toBeTrue();
        });

        it('transitions from running to failed', function (): void {
            $stepRun = StepRun::create($this->workflowId, $this->stepKey);
            $stepRun->start();

            $stepRun->fail('JOB_FAILED', 'Job execution failed');

            expect($stepRun->status())->toBe(StepState::Failed)
                ->and($stepRun->finishedAt())->toBeInstanceOf(CarbonImmutable::class)
                ->and($stepRun->failureCode())->toBe('JOB_FAILED')
                ->and($stepRun->failureMessage())->toBe('Job execution failed')
                ->and($stepRun->isFailed())->toBeTrue()
                ->and($stepRun->isTerminal())->toBeTrue();
        });
    });

    describe('invalid transitions', static function (): void {
        it('throws when starting a non-pending step run', function (): void {
            $stepRun = StepRun::create($this->workflowId, $this->stepKey);
            $stepRun->start();

            expect(fn () => $stepRun->start())
                ->toThrow(InvalidStateTransitionException::class);
        });

        it('throws when succeeding a pending step run', function (): void {
            $stepRun = StepRun::create($this->workflowId, $this->stepKey);

            expect(fn () => $stepRun->succeed())
                ->toThrow(InvalidStateTransitionException::class);
        });

        it('throws when failing a succeeded step run', function (): void {
            $stepRun = StepRun::create($this->workflowId, $this->stepKey);
            $stepRun->start();
            $stepRun->succeed();

            expect(fn () => $stepRun->fail())
                ->toThrow(InvalidStateTransitionException::class);
        });
    });

    describe('job count tracking', static function (): void {
        it('tracks total job count', function (): void {
            $stepRun = StepRun::create($this->workflowId, $this->stepKey);

            $stepRun->setTotalJobCount(5);

            expect($stepRun->totalJobCount())->toBe(5);
        });

        it('tracks failed job count', function (): void {
            $stepRun = StepRun::create($this->workflowId, $this->stepKey, totalJobCount: 5);

            $stepRun->incrementFailedJobCount();
            $stepRun->incrementFailedJobCount();

            expect($stepRun->failedJobCount())->toBe(2);
        });

        it('calculates succeeded job count', function (): void {
            $stepRun = StepRun::create($this->workflowId, $this->stepKey, totalJobCount: 5);
            $stepRun->incrementFailedJobCount();

            expect($stepRun->succeededJobCount())->toBe(4);
        });

        it('records job success', function (): void {
            $stepRun = StepRun::create($this->workflowId, $this->stepKey, totalJobCount: 3);

            $stepRun->recordJobSuccess();

            expect($stepRun->failedJobCount())->toBe(0);
        });

        it('records job failure', function (): void {
            $stepRun = StepRun::create($this->workflowId, $this->stepKey, totalJobCount: 3);

            $stepRun->recordJobFailure();

            expect($stepRun->failedJobCount())->toBe(1);
        });
    });

    describe('job completion tracking', static function (): void {
        it('detects when all jobs are completed', function (): void {
            $stepRun = StepRun::create($this->workflowId, $this->stepKey, totalJobCount: 2);
            $stepRun->recordJobSuccess();
            $stepRun->recordJobFailure();

            expect($stepRun->hasAllJobsCompleted())->toBeTrue();
        });

        it('detects when jobs are not yet completed', function (): void {
            $stepRun = StepRun::create($this->workflowId, $this->stepKey, totalJobCount: 3);
            $stepRun->recordJobSuccess();

            expect($stepRun->hasAllJobsCompleted())->toBeFalse();
        });

        it('calculates completed job count', function (): void {
            $stepRun = StepRun::create($this->workflowId, $this->stepKey, totalJobCount: 5);
            $stepRun->recordJobSuccess();
            $stepRun->recordJobSuccess();
            $stepRun->recordJobFailure();

            expect($stepRun->completedJobCount())->toBe(3);
        });
    });

    describe('duration tracking', static function (): void {
        it('returns null duration when not started', function (): void {
            $stepRun = StepRun::create($this->workflowId, $this->stepKey);

            expect($stepRun->duration())->toBeNull();
        });

        it('calculates duration when started', function (): void {
            $stepRun = StepRun::create($this->workflowId, $this->stepKey);
            $stepRun->start();

            CarbonImmutable::setTestNow(CarbonImmutable::now()->addMilliseconds(100));

            expect($stepRun->duration())->toBe(100);
        });

        it('calculates duration when finished', function (): void {
            $stepRun = StepRun::create($this->workflowId, $this->stepKey);
            $stepRun->start();

            CarbonImmutable::setTestNow(CarbonImmutable::now()->addMilliseconds(200));
            $stepRun->succeed();

            expect($stepRun->duration())->toBe(200);
        });
    });

    describe('reconstitution', static function (): void {
        it('reconstitutes from persisted data', function (): void {
            $id = StepRunId::generate();
            $now = CarbonImmutable::now();

            $stepRun = StepRun::reconstitute(
                id: $id,
                workflowId: $this->workflowId,
                stepKey: $this->stepKey,
                attempt: 2,
                status: StepState::Running,
                startedAt: $now,
                finishedAt: null,
                failureCode: null,
                failureMessage: null,
                failedJobCount: 1,
                totalJobCount: 5,
                createdAt: $now,
                updatedAt: $now,
            );

            expect($stepRun->id)->toBe($id)
                ->and($stepRun->attempt)->toBe(2)
                ->and($stepRun->status())->toBe(StepState::Running)
                ->and($stepRun->failedJobCount())->toBe(1)
                ->and($stepRun->totalJobCount())->toBe(5);
        });
    });
});
