<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Maestro\Workflow\Domain\JobRecord;
use Maestro\Workflow\Enums\JobState;
use Maestro\Workflow\Exceptions\InvalidStateTransitionException;
use Maestro\Workflow\ValueObjects\JobId;
use Maestro\Workflow\ValueObjects\StepRunId;
use Maestro\Workflow\ValueObjects\WorkflowId;

describe('JobRecord', static function (): void {
    beforeEach(function (): void {
        CarbonImmutable::setTestNow(CarbonImmutable::now());

        $this->workflowId = WorkflowId::generate();
        $this->stepRunId = StepRunId::generate();
        $this->jobUuid = 'job-uuid-123';
        $this->jobClass = 'App\\Jobs\\TestJob';
        $this->queue = 'default';
    });

    afterEach(function (): void {
        CarbonImmutable::setTestNow();
    });

    describe('creation', static function (): void {
        it('creates with dispatched state by default', function (): void {
            $jobRecord = JobRecord::create(
                $this->workflowId,
                $this->stepRunId,
                $this->jobUuid,
                $this->jobClass,
                $this->queue,
            );

            expect($jobRecord->status())->toBe(JobState::Dispatched)
                ->and($jobRecord->workflowId)->toBe($this->workflowId)
                ->and($jobRecord->stepRunId)->toBe($this->stepRunId)
                ->and($jobRecord->jobUuid)->toBe($this->jobUuid)
                ->and($jobRecord->jobClass)->toBe($this->jobClass)
                ->and($jobRecord->queue)->toBe($this->queue)
                ->and($jobRecord->attempt())->toBe(1)
                ->and($jobRecord->isDispatched())->toBeTrue()
                ->and($jobRecord->dispatchedAt)->toBeInstanceOf(CarbonImmutable::class);
        });

        it('creates with a provided job id', function (): void {
            $jobId = JobId::generate();
            $jobRecord = JobRecord::create(
                $this->workflowId,
                $this->stepRunId,
                $this->jobUuid,
                $this->jobClass,
                $this->queue,
                $jobId,
            );

            expect($jobRecord->id)->toBe($jobId);
        });

        it('generates job id if not provided', function (): void {
            $jobRecord = JobRecord::create(
                $this->workflowId,
                $this->stepRunId,
                $this->jobUuid,
                $this->jobClass,
                $this->queue,
            );

            expect($jobRecord->id->value)->toBeValidUuid();
        });
    });

    describe('state transitions', static function (): void {
        it('transitions from dispatched to running via start', function (): void {
            $jobRecord = JobRecord::create(
                $this->workflowId,
                $this->stepRunId,
                $this->jobUuid,
                $this->jobClass,
                $this->queue,
            );

            $jobRecord->start('worker-1');

            expect($jobRecord->status())->toBe(JobState::Running)
                ->and($jobRecord->startedAt())->toBeInstanceOf(CarbonImmutable::class)
                ->and($jobRecord->workerId())->toBe('worker-1')
                ->and($jobRecord->isRunning())->toBeTrue();
        });

        it('transitions from running to succeeded', function (): void {
            $jobRecord = JobRecord::create(
                $this->workflowId,
                $this->stepRunId,
                $this->jobUuid,
                $this->jobClass,
                $this->queue,
            );
            $jobRecord->start();

            CarbonImmutable::setTestNow(CarbonImmutable::now()->addMilliseconds(150));
            $jobRecord->succeed();

            expect($jobRecord->status())->toBe(JobState::Succeeded)
                ->and($jobRecord->finishedAt())->toBeInstanceOf(CarbonImmutable::class)
                ->and($jobRecord->runtimeMs())->toBe(150)
                ->and($jobRecord->isSucceeded())->toBeTrue()
                ->and($jobRecord->isTerminal())->toBeTrue();
        });

        it('transitions from running to failed', function (): void {
            $jobRecord = JobRecord::create(
                $this->workflowId,
                $this->stepRunId,
                $this->jobUuid,
                $this->jobClass,
                $this->queue,
            );
            $jobRecord->start();

            CarbonImmutable::setTestNow(CarbonImmutable::now()->addMilliseconds(100));
            $jobRecord->fail('RuntimeException', 'Something went wrong', 'stack trace...');

            expect($jobRecord->status())->toBe(JobState::Failed)
                ->and($jobRecord->finishedAt())->toBeInstanceOf(CarbonImmutable::class)
                ->and($jobRecord->failureClass())->toBe('RuntimeException')
                ->and($jobRecord->failureMessage())->toBe('Something went wrong')
                ->and($jobRecord->failureTrace())->toBe('stack trace...')
                ->and($jobRecord->runtimeMs())->toBe(100)
                ->and($jobRecord->isFailed())->toBeTrue()
                ->and($jobRecord->isTerminal())->toBeTrue();
        });
    });

    describe('invalid transitions', static function (): void {
        it('throws when starting a running job', function (): void {
            $jobRecord = JobRecord::create(
                $this->workflowId,
                $this->stepRunId,
                $this->jobUuid,
                $this->jobClass,
                $this->queue,
            );
            $jobRecord->start();

            expect(static fn () => $jobRecord->start())
                ->toThrow(InvalidStateTransitionException::class);
        });

        it('throws when succeeding a dispatched job', function (): void {
            $jobRecord = JobRecord::create(
                $this->workflowId,
                $this->stepRunId,
                $this->jobUuid,
                $this->jobClass,
                $this->queue,
            );

            expect(static fn () => $jobRecord->succeed())
                ->toThrow(InvalidStateTransitionException::class);
        });

        it('throws when failing a succeeded job', function (): void {
            $jobRecord = JobRecord::create(
                $this->workflowId,
                $this->stepRunId,
                $this->jobUuid,
                $this->jobClass,
                $this->queue,
            );
            $jobRecord->start();
            $jobRecord->succeed();

            expect(static fn () => $jobRecord->fail())
                ->toThrow(InvalidStateTransitionException::class);
        });
    });

    describe('attempt tracking', static function (): void {
        it('increments attempt count', function (): void {
            $jobRecord = JobRecord::create(
                $this->workflowId,
                $this->stepRunId,
                $this->jobUuid,
                $this->jobClass,
                $this->queue,
            );

            $jobRecord->incrementAttempt();

            expect($jobRecord->attempt())->toBe(2);
        });
    });

    describe('timing calculations', static function (): void {
        it('returns duration from runtimeMs', function (): void {
            $jobRecord = JobRecord::create(
                $this->workflowId,
                $this->stepRunId,
                $this->jobUuid,
                $this->jobClass,
                $this->queue,
            );
            $jobRecord->start();
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addMilliseconds(250));
            $jobRecord->succeed();

            expect($jobRecord->duration())->toBe(250);
        });

        it('calculates queue wait time', function (): void {
            $jobRecord = JobRecord::create(
                $this->workflowId,
                $this->stepRunId,
                $this->jobUuid,
                $this->jobClass,
                $this->queue,
            );

            CarbonImmutable::setTestNow(CarbonImmutable::now()->addMilliseconds(500));
            $jobRecord->start();

            expect($jobRecord->queueWaitTime())->toBe(500);
        });

        it('returns null queue wait time when not started', function (): void {
            $jobRecord = JobRecord::create(
                $this->workflowId,
                $this->stepRunId,
                $this->jobUuid,
                $this->jobClass,
                $this->queue,
            );

            expect($jobRecord->queueWaitTime())->toBeNull();
        });
    });

    describe('reconstitution', static function (): void {
        it('reconstitutes from persisted data', function (): void {
            $jobId = JobId::generate();
            $now = CarbonImmutable::now();
            $started = $now->addSeconds(1);
            $finished = $now->addSeconds(2);

            $jobRecord = JobRecord::reconstitute(
                workflowId: $this->workflowId,
                stepRunId: $this->stepRunId,
                jobUuid: $this->jobUuid,
                jobClass: $this->jobClass,
                queue: $this->queue,
                attempt: 2,
                dispatchedAt: $now,
                startedAt: $started,
                finishedAt: $finished,
                runtimeMs: 1000,
                failureClass: null,
                failureMessage: null,
                failureTrace: null,
                workerId: 'worker-1',
                createdAt: $now,
                updatedAt: $finished,
                id: $jobId,
                status: JobState::Succeeded,
            );

            expect($jobRecord->id)->toBe($jobId)
                ->and($jobRecord->status())->toBe(JobState::Succeeded)
                ->and($jobRecord->attempt())->toBe(2)
                ->and($jobRecord->runtimeMs())->toBe(1000)
                ->and($jobRecord->workerId())->toBe('worker-1');
        });
    });
});
